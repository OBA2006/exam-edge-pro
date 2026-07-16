<?php
namespace App\Http\Controllers;
use App\Models\{Exam, ExamQuestion, Question};
use App\Services\{AuditService, WebhookService};
use App\Jobs\SendExamNotificationJob;
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Str;

class ExamController extends Controller {
    public function __construct(private AuditService $audit, private WebhookService $webhook) {}

    public function index(Request $request): JsonResponse {
        $user = auth()->user();
        $q = Exam::with(['course:id,name','creator:id,name'])->withCount(['examQuestions as questions_count','sessions'])
            ->when($user->role==='student', fn($q)=>$q->where('status','published'))
            ->when($user->role==='instructor', fn($q)=>$q->where('created_by',$user->id))
            ->when($request->status, fn($q)=>$q->where('status',$request->status))
            ->when($request->search, fn($q)=>$q->where('title','ilike',"%{$request->search}%"))
            ->latest()->paginate($request->per_page??20);
        return response()->json($q);
    }

    public function store(Request $request): JsonResponse {
        $data = $request->validate(['title'=>'required|string|max:255','description'=>'nullable|string','course_id'=>'nullable|uuid|exists:courses,id','duration'=>'required|integer|min:5|max:480','pass_mark'=>'required|integer|min:1|max:100','proctoring_level'=>'in:none,basic,full','shuffle_questions'=>'boolean','max_attempts'=>'integer|min:0','adaptive_mode'=>'boolean']);
        $exam = Exam::create(['id'=>Str::uuid(),'created_by'=>auth()->id()]+$data);
        $this->audit->log('exam','exam_created',$exam->title);
        $this->webhook->dispatch('exam.created',$exam->toArray());
        return response()->json(['exam'=>$exam->load('course')], 201);
    }

    public function show(string $id): JsonResponse {
        return response()->json(['exam'=>Exam::with(['course','creator','examQuestions.question'])->withCount(['examQuestions as questions_count','sessions'])->findOrFail($id)]);
    }

    public function update(Request $request, string $id): JsonResponse {
        $exam = Exam::findOrFail($id);
        $exam->update($request->only(['title','description','duration','pass_mark','proctoring_level','shuffle_questions','shuffle_options','max_attempts','adaptive_mode']));
        $this->audit->log('exam','exam_updated',$exam->title);
        return response()->json(['exam'=>$exam->fresh()]);
    }

    public function destroy(string $id): JsonResponse {
        $exam = Exam::findOrFail($id); $this->audit->log('exam','exam_deleted',$exam->title); $exam->delete();
        return response()->json(['message'=>'Exam deleted.']);
    }

    public function publish(string $id): JsonResponse {
        $exam = Exam::withCount(['examQuestions as questions_count'])->findOrFail($id);
        if ($exam->questions_count === 0) return response()->json(['message'=>'Add at least one question before publishing.'], 422);
        $exam->update(['status'=>'published']);
        if ($exam->course_id) SendExamNotificationJob::dispatch($exam,'published');
        $this->audit->log('exam','exam_published',$exam->title);
        $this->webhook->dispatch('exam.published',$exam->toArray());
        return response()->json(['exam'=>$exam->fresh()]);
    }

    public function archive(string $id): JsonResponse {
        $exam = Exam::findOrFail($id); $exam->update(['status'=>'archived']); $this->audit->log('exam','exam_archived',$exam->title);
        return response()->json(['exam'=>$exam->fresh()]);
    }

    public function duplicate(Request $request, string $id): JsonResponse {
        $source = Exam::with('examQuestions')->findOrFail($id);
        $data = $request->validate(['title'=>'required|string','course_id'=>'nullable|uuid','copy_questions'=>'boolean']);
        $new = $source->replicate(['id','created_at','updated_at','total_submissions']);
        $new->id = Str::uuid(); $new->title = $data['title']; $new->created_by = auth()->id();
        $new->status = 'draft'; $new->course_id = $data['course_id']??$source->course_id; $new->save();
        if ($data['copy_questions']??true) foreach($source->examQuestions as $eq) ExamQuestion::create(['id'=>Str::uuid(),'exam_id'=>$new->id,'question_id'=>$eq->question_id,'order'=>$eq->order]);
        $this->audit->log('exam','exam_duplicated',$source->title.' → '.$new->title);
        return response()->json(['exam'=>$new], 201);
    }

    public function updateLifecycle(Request $request, string $id): JsonResponse {
        $exam = Exam::findOrFail($id);
        $data = $request->validate(['stage'=>'required|in:draft,review,published,active,closed,grading,results,archived']);
        $old = $exam->status; $exam->update(['status'=>$data['stage']]);
        $this->audit->log('exam','lifecycle_changed',"{$exam->title}: {$old} → {$data['stage']}");
        return response()->json(['exam'=>$exam->fresh()]);
    }

    public function schedule(Request $request, string $id): JsonResponse {
        $exam = Exam::findOrFail($id);
        $data = $request->validate(['window_start'=>'required|date|after:now','window_end'=>'required|date|after:window_start','timezone'=>'required|timezone','grace_period'=>'integer|min:0|max:60']);
        $exam->update(['window_start'=>$data['window_start'],'window_end'=>$data['window_end'],'timezone'=>$data['timezone'],'grace_period'=>$data['grace_period']??0,'status'=>'published']);
        return response()->json(['exam'=>$exam->fresh()]);
    }

    public function results(string $id): JsonResponse {
        $exam = Exam::findOrFail($id);
        $sessions = \App\Models\ExamSession::with('user')->where('exam_id',$id)->where('status','submitted')->get()
            ->map(fn($s)=>['user'=>$s->user->toPublicArray(),'final_score'=>$s->final_score,'passed'=>$s->passed,'grade_status'=>$s->grade_status,'submitted_at'=>$s->submitted_at,'attempt'=>$s->attempt_number]);
        return response()->json(['exam'=>$exam->only(['id','title','pass_mark']),'results'=>$sessions,'total'=>$sessions->count(),'pass_rate'=>$sessions->count()>0?round($sessions->where('passed',true)->count()/$sessions->count()*100,1):0]);
    }

    public function publishResults(Request $request, string $id): JsonResponse {
        $exam = Exam::findOrFail($id);
        $settings = $request->validate(['visibility'=>'required|in:all,passed,hidden','show_score'=>'boolean','show_feedback'=>'boolean']);
        $exam->update(['results_published'=>true,'results_visibility'=>$settings]);
        SendExamNotificationJob::dispatch($exam,'results_published');
        $this->audit->log('exam','results_published',$exam->title);
        return response()->json(['message'=>'Results published.']);
    }

    public function statistics(string $id): JsonResponse {
        $exam = Exam::findOrFail($id);
        $scores = \App\Models\ExamSession::where('exam_id',$id)->where('status','submitted')->whereNotNull('final_score')->pluck('final_score');
        if ($scores->isEmpty()) return response()->json(['stats'=>null,'message'=>'No data yet.']);
        $sorted=$scores->sort()->values(); $n=$sorted->count(); $mean=$sorted->avg();
        $median=$n%2===0?($sorted[$n/2-1]+$sorted[$n/2])/2:$sorted[intval($n/2)];
        $sd=sqrt($sorted->map(fn($s)=>pow($s-$mean,2))->avg());
        $dist=array_fill(0,10,0); foreach($scores as $s) $dist[min(9,intval($s/10))]++;
        return response()->json(['stats'=>['n'=>$n,'mean'=>round($mean,1),'median'=>round($median,1),'sd'=>round($sd,1),'min'=>$sorted->min(),'max'=>$sorted->max(),'distribution'=>$dist,'pass_rate'=>round($scores->filter(fn($s)=>$s>=$exam->pass_mark)->count()/$n*100,1)]]);
    }

    public function questions(string $id): JsonResponse { return response()->json(['questions'=>ExamQuestion::where('exam_id',$id)->with('question')->orderBy('order')->get()]); }

    public function addQuestion(Request $request, string $id): JsonResponse {
        $exam = Exam::findOrFail($id);
        $data = $request->validate(['question_id'=>'nullable|uuid|exists:questions,id','type'=>'required_without:question_id|in:mcq,essay,coding,truefalse,fillin','text'=>'required_without:question_id|string','options'=>'nullable|array','answer'=>'nullable|string','points'=>'integer|min:1','difficulty'=>'in:easy,medium,hard']);
        if (!isset($data['question_id'])) {
            $q = Question::create(['id'=>Str::uuid(),'created_by'=>auth()->id(),'type'=>$data['type'],'text'=>$data['text'],'options'=>$data['options']??null,'answer'=>$data['answer']??null,'points'=>$data['points']??5,'difficulty'=>$data['difficulty']??'medium']);
            $data['question_id'] = $q->id;
        }
        $order = ExamQuestion::where('exam_id',$id)->max('order')+1;
        ExamQuestion::create(['id'=>Str::uuid(),'exam_id'=>$id,'question_id'=>$data['question_id'],'order'=>$order]);
        return response()->json(['message'=>'Question added.'], 201);
    }

    public function removeQuestion(string $id, string $qId): JsonResponse {
        ExamQuestion::where('exam_id',$id)->where('question_id',$qId)->delete();
        return response()->json(['message'=>'Removed.']);
    }

    public function reorderQuestions(Request $request, string $id): JsonResponse {
        foreach($request->validate(['order'=>'required|array'])['order'] as $pos=>$qId)
            ExamQuestion::where('exam_id',$id)->where('question_id',$qId)->update(['order'=>$pos]);
        return response()->json(['message'=>'Reordered.']);
    }

    public function updateAttemptPolicy(Request $request, string $id): JsonResponse {
        $exam = Exam::findOrFail($id);
        $exam->update($request->validate(['max_attempts'=>'integer|min:0','attempt_cooldown'=>'integer|min:0','score_display'=>'in:best,latest,all,none']));
        return response()->json(['exam'=>$exam->fresh()]);
    }
}
