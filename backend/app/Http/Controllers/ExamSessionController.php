<?php
namespace App\Http\Controllers;
use App\Models\{Exam, ExamSession};
use App\Services\{AuditService, GradingService, WebhookService, CacheService};
use App\Jobs\{GradeEssaysJob, AwardBadgesJob};
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\{Str, Carbon};
use Illuminate\Support\Facades\Cache;

class ExamSessionController extends Controller {
    public function __construct(private AuditService $audit, private GradingService $grading, private WebhookService $webhook, private CacheService $cache) {}

    public function start(Request $request): JsonResponse {
        $data = $request->validate(['exam_id'=>'required|uuid|exists:exams,id']);
        $exam = Exam::with('examQuestions.question')->findOrFail($data['exam_id']);
        $user = auth()->user();
        if ($exam->status !== 'published') return response()->json(['message'=>'Exam not available.'], 403);
        if ($exam->window_start && now()->lt($exam->window_start)) return response()->json(['message'=>'Exam has not started.','window_start'=>$exam->window_start], 403);
        if ($exam->window_end && now()->gt(Carbon::parse($exam->window_end)->addMinutes($exam->grace_period??0))) return response()->json(['message'=>'Exam window has closed.'], 403);
        if ($exam->max_attempts > 0) {
            $attempts = ExamSession::where('exam_id',$exam->id)->where('user_id',$user->id)->count();
            if ($attempts >= $exam->max_attempts) return response()->json(['message'=>'Maximum attempts reached.'], 403);
        }
        $existing = ExamSession::where('exam_id',$exam->id)->where('user_id',$user->id)->where('status','in_progress')->latest()->first();
        if ($existing) return response()->json(['session'=>$this->buildResponse($existing,$exam),'resumed'=>true]);
        $questions = $exam->examQuestions->sortBy('order')->values();
        if ($exam->shuffle_questions) $questions = $questions->shuffle()->values();
        $attemptNum = ExamSession::where('exam_id',$exam->id)->where('user_id',$user->id)->count()+1;
        $session = ExamSession::create(['id'=>Str::uuid(),'exam_id'=>$exam->id,'user_id'=>$user->id,'attempt_number'=>$attemptNum,'time_left'=>$exam->duration*60,'current_question'=>0,'answers'=>[],'flagged_questions'=>[],'question_order'=>$questions->pluck('question_id')->toArray(),'status'=>'in_progress','grade_status'=>'pending','ip_addresses'=>[$request->ip()]]);
        $this->cache->setSession($session->id, $session->toArray());
        $this->audit->log('submission','exam_started',$exam->title.' #'.$attemptNum);
        return response()->json(['session'=>$this->buildResponse($session,$exam),'resumed'=>false], 201);
    }

    public function show(string $id): JsonResponse {
        $session = ExamSession::findOrFail($id);
        $exam = Exam::with('examQuestions.question')->find($session->exam_id);
        return response()->json(['session'=>$this->buildResponse($session,$exam)]);
    }

    public function saveAnswer(Request $request, string $id): JsonResponse {
        $session = ExamSession::findOrFail($id);
        if ($session->status !== 'in_progress') return response()->json(['message'=>'Session not active.'], 422);
        $data = $request->validate(['question_id'=>'required','answer'=>'nullable','time_left'=>'required|integer|min:0','current_question'=>'required|integer|min:0']);
        $answers = $session->answers ?? [];
        $answers[$data['question_id']] = $data['answer'];
        $session->update(['answers'=>$answers,'time_left'=>$data['time_left'],'current_question'=>$data['current_question'],'last_saved_at'=>now()]);
        $this->cache->setSession($session->id, $session->toArray());
        return response()->json(['saved'=>true,'saved_at'=>now()->toIso8601String()]);
    }

    public function flagQuestion(Request $request, string $id): JsonResponse {
        $session = ExamSession::findOrFail($id);
        $qId = $request->validate(['question_id'=>'required'])['question_id'];
        $flagged = $session->flagged_questions ?? [];
        if (in_array($qId,$flagged)) { $flagged=array_values(array_filter($flagged,fn($f)=>$f!==$qId)); $isFlagged=false; }
        else { $flagged[]=$qId; $isFlagged=true; }
        $session->update(['flagged_questions'=>$flagged]);
        return response()->json(['flagged'=>$isFlagged,'all_flagged'=>$flagged]);
    }

    public function submit(Request $request, string $id): JsonResponse {
        $session = ExamSession::findOrFail($id);
        if ($session->status !== 'in_progress') return response()->json(['message'=>'Session already submitted.'], 422);
        $exam = Exam::with('examQuestions.question')->find($session->exam_id);
        if ($request->has('answers')) $session->answers = array_merge($session->answers??[],$request->answers);
        $result = $this->grading->autoGrade($session, $exam);
        $session->update(['status'=>'submitted','grade_status'=>$result['has_essays']?'pending_ai':'graded','auto_score'=>$result['auto_score'],'total_possible'=>$result['total_possible'],'final_score'=>$result['has_essays']?null:$result['percentage'],'passed'=>$result['has_essays']?null:($result['percentage']>=$exam->pass_mark),'submitted_at'=>now()]);
        $exam->increment('total_submissions');
        if ($result['has_essays']) GradeEssaysJob::dispatch($session,$exam)->delay(now()->addSeconds(5));
        AwardBadgesJob::dispatch($session->user_id,$exam->id,$session->id);
        Cache::forget("session:{$session->id}");
        $this->audit->log('submission','exam_submitted',$exam->title);
        $this->webhook->dispatch('submission.received',['session_id'=>$session->id,'exam_id'=>$exam->id]);
        return response()->json(['message'=>'Submitted.','auto_score'=>$result['auto_score'],'total'=>$result['total_possible'],'percentage'=>$result['percentage'],'has_essays'=>$result['has_essays'],'final_score'=>$session->final_score]);
    }

    public function recover(string $id): JsonResponse {
        $session = ExamSession::findOrFail($id);
        $cached = Cache::get("session:{$id}");
        if ($cached) return response()->json(['session'=>$cached,'source'=>'cache']);
        $exam = Exam::with('examQuestions.question')->find($session->exam_id);
        return response()->json(['session'=>$this->buildResponse($session,$exam),'source'=>'database']);
    }

    public function result(string $id): JsonResponse {
        $session = ExamSession::with(['exam','aiGradingResults.question','proctoringLogs'])->findOrFail($id);
        if ($session->status !== 'submitted') return response()->json(['message'=>'Not yet submitted.'], 422);
        return response()->json(['session'=>$session,'final_score'=>$session->final_score,'passed'=>$session->passed,'grade_status'=>$session->grade_status,'ai_results'=>$session->aiGradingResults,'violations'=>$session->proctoringLogs->count()]);
    }

    public function activeSession(): JsonResponse {
        $session = ExamSession::where('user_id',auth()->id())->where('status','in_progress')->with('exam:id,title')->latest()->first();
        return response()->json(['active_session'=>$session]);
    }

    private function buildResponse(ExamSession $session, Exam $exam): array {
        $user = auth()->user();
        $qMap = $exam->examQuestions->keyBy('question_id');
        $questions = collect($session->question_order??[])->map(fn($qId)=>$qMap->get($qId)?->question)->filter()
            ->map(function($q) use($user,$exam) {
                $d = $q->toArray();
                if ($user->role==='student' && !$exam->results_published) {
                    if (isset($d['options'])) $d['options']=collect($d['options'])->map(fn($o)=>array_diff_key($o,['correct'=>true,'explanation'=>true]))->toArray();
                    unset($d['answer'],$d['explanation']);
                }
                return $d;
            })->values();
        return ['id'=>$session->id,'exam_id'=>$session->exam_id,'exam_title'=>$exam->title,'exam_duration'=>$exam->duration,'time_left'=>$session->time_left,'current_question'=>$session->current_question,'answers'=>$session->answers??[],'flagged'=>$session->flagged_questions??[],'status'=>$session->status,'questions'=>$questions,'total_questions'=>$questions->count(),'last_saved_at'=>$session->last_saved_at,'attempt_number'=>$session->attempt_number,'proctoring_level'=>$exam->proctoring_level];
    }
}
