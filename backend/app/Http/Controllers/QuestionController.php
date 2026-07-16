<?php
namespace App\Http\Controllers;
use App\Models\{Question, ExamQuestion};
use App\Services\AiService;
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Str;

class QuestionController extends Controller {
    public function __construct(private AiService $ai) {}

    public function index(Request $request): JsonResponse {
        $q = Question::with('creator:id,name')->when($request->type,fn($q)=>$q->where('type',$request->type))->when($request->difficulty,fn($q)=>$q->where('difficulty',$request->difficulty))->when($request->search,fn($q)=>$q->where('text','ilike',"%{$request->search}%"))->latest()->paginate($request->per_page??20);
        return response()->json($q);
    }

    public function store(Request $request): JsonResponse {
        $data = $request->validate(['type'=>'required|in:mcq,essay,coding,truefalse,fillin','text'=>'required|string','options'=>'required_if:type,mcq|array','answer'=>'nullable|string','explanation'=>'nullable|string','points'=>'integer|min:1','difficulty'=>'in:easy,medium,hard','tags'=>'nullable|array']);
        return response()->json(['question'=>Question::create(['id'=>Str::uuid(),'created_by'=>auth()->id()]+$data)], 201);
    }

    public function show(string $id): JsonResponse { return response()->json(['question'=>Question::findOrFail($id)]); }

    public function update(Request $request, string $id): JsonResponse {
        $q = Question::findOrFail($id); $q->update($request->only(['text','options','answer','explanation','points','difficulty','tags']));
        return response()->json(['question'=>$q->fresh()]);
    }

    public function destroy(string $id): JsonResponse { Question::findOrFail($id)->delete(); return response()->json(['message'=>'Deleted.']); }

    public function versions(string $id): JsonResponse {
        $q = Question::findOrFail($id);
        return response()->json(['current'=>$q->text,'version'=>$q->version,'history'=>$q->version_history??[]]);
    }

    public function restore(Request $request, string $id): JsonResponse {
        $data = $request->validate(['version'=>'required|integer|min:1']);
        $q = Question::findOrFail($id);
        $target = collect($q->version_history??[])->firstWhere('version',$data['version']);
        if (!$target) return response()->json(['message'=>'Version not found.'], 404);
        $q->update(['text'=>$target['text']]);
        return response()->json(['question'=>$q->fresh()]);
    }

    public function bulkImport(Request $request): JsonResponse {
        $data = $request->validate(['questions'=>'required|array|min:1|max:500','exam_id'=>'nullable|uuid|exists:exams,id']);
        $created = [];
        foreach($data['questions'] as $qd) {
            $q = Question::create(['id'=>Str::uuid(),'created_by'=>auth()->id(),'type'=>$qd['type']??'mcq','text'=>$qd['text'],'options'=>$qd['options']??null,'answer'=>$qd['answer']??null,'points'=>$qd['points']??5,'difficulty'=>$qd['difficulty']??'medium','tags'=>is_array($qd['tags']??null)?$qd['tags']:explode(';',$qd['tags']??'')]);
            if ($data['exam_id']) { $order=ExamQuestion::where('exam_id',$data['exam_id'])->max('order')+1; ExamQuestion::create(['id'=>Str::uuid(),'exam_id'=>$data['exam_id'],'question_id'=>$q->id,'order'=>$order]); }
            $created[] = $q->id;
        }
        return response()->json(['created'=>count($created),'ids'=>$created], 201);
    }

    public function aiGenerate(Request $request): JsonResponse {
        $data = $request->validate(['topic'=>'required|string|max:200','type'=>'required|in:mcq,essay,truefalse,fillin,coding,mixed','difficulty'=>'required|in:easy,medium,hard','count'=>'required|integer|min:1|max:20','points'=>'integer|min:1','context'=>'nullable|string','exam_id'=>'nullable|uuid|exists:exams,id']);
        $questions = $this->ai->generateQuestions($data['topic'],$data['type'],$data['difficulty'],$data['count'],$data['points']??5,$data['context']??'');
        if (empty($questions)) return response()->json(['message'=>'Generation failed.'], 500);
        $saved = [];
        foreach($questions as $qd) {
            $q = Question::create(['id'=>Str::uuid(),'created_by'=>auth()->id(),'type'=>$qd['type']??$data['type'],'text'=>$qd['text'],'options'=>$qd['options']??null,'answer'=>$qd['answer']??null,'explanation'=>$qd['explanation']??null,'points'=>$qd['points']??$data['points']??5,'difficulty'=>$qd['difficulty']??$data['difficulty'],'tags'=>$qd['tags']??[$data['topic']]]);
            if ($data['exam_id']) { $order=ExamQuestion::where('exam_id',$data['exam_id'])->max('order')+1; ExamQuestion::create(['id'=>Str::uuid(),'exam_id'=>$data['exam_id'],'question_id'=>$q->id,'order'=>$order]); }
            $saved[] = $q;
        }
        return response()->json(['generated'=>count($saved),'questions'=>$saved], 201);
    }

    public function aiImprove(Request $request, string $id): JsonResponse {
        $q = Question::findOrFail($id);
        $data = $request->validate(['goal'=>'required|in:clarity,bloom,realworld,distractors,antiAI','difficulty'=>'nullable|in:easy,medium,hard']);
        return response()->json(['original'=>$q->text,'improved'=>$this->ai->improveQuestion($q->text,$q->type,$data['difficulty']??$q->difficulty,$data['goal'])]);
    }
}
