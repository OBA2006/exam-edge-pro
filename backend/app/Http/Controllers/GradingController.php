<?php
namespace App\Http\Controllers;
use App\Models\{ExamSession, AiGradingResult, Question};
use App\Services\{AiService, AuditService, WebhookService};
use App\Jobs\GradeEssaysJob;
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Str;

class GradingController extends Controller {
    public function __construct(private AiService $ai, private AuditService $audit, private WebhookService $webhook) {}

    public function queue(Request $request): JsonResponse {
        $q = ExamSession::with(['user:id,name,email','exam:id,title'])->where('grade_status','pending_ai')->where('status','submitted')->latest('submitted_at')->paginate($request->per_page??20);
        return response()->json($q);
    }

    public function gradeSession(string $sid): JsonResponse {
        $session = ExamSession::with(['exam.examQuestions.question'])->findOrFail($sid);
        GradeEssaysJob::dispatch($session,$session->exam);
        return response()->json(['message'=>'Session queued for AI grading.']);
    }

    public function gradeEssay(Request $request): JsonResponse {
        $data = $request->validate(['session_id'=>'required|uuid|exists:exam_sessions,id','question_id'=>'required|uuid|exists:questions,id','student_answer'=>'required|string','max_points'=>'required|integer|min:1']);
        $q = Question::findOrFail($data['question_id']);
        $result = $this->ai->gradeEssay($q->text, $q->answer??'', $data['student_answer'], $data['max_points']);
        $grade = AiGradingResult::updateOrCreate(['session_id'=>$data['session_id'],'question_id'=>$data['question_id']],['id'=>Str::uuid(),'student_answer'=>$data['student_answer'],'model_answer'=>$q->answer,'similarity_score'=>$result['similarity']??null,'ai_score'=>$result['score']??0,'awarded_points'=>$result['awarded']??0,'max_points'=>$data['max_points'],'grade'=>$result['grade']??'F','confidence'=>$result['confidence']??75,'rubric_breakdown'=>$result['rubric']??[],'keywords'=>$result['keywords']??[],'feedback'=>$result['feedback']??'','plagiarism_detected'=>$result['plagiarism']??false,'ai_generated_detected'=>$result['ai_generated']??false,'status'=>'ai_graded','ai_graded_at'=>now()]);
        $this->audit->log('grading','essay_graded',"Q:{$data['question_id']}");
        return response()->json(['result'=>$grade]);
    }

    public function approve(string $id): JsonResponse {
        $result = AiGradingResult::findOrFail($id);
        $result->update(['status'=>'instructor_approved','graded_by'=>auth()->id(),'approved_at'=>now()]);
        $this->finalizeIfComplete($result->session_id);
        $this->audit->log('grading','grade_approved',"Result:{$id}");
        return response()->json(['result'=>$result->fresh()]);
    }

    public function override(Request $request, string $id): JsonResponse {
        $result = AiGradingResult::findOrFail($id);
        $data = $request->validate(['points'=>"required|integer|min:0|max:{$result->max_points}",'notes'=>'nullable|string']);
        $result->update(['status'=>'instructor_overridden','instructor_override_points'=>$data['points'],'instructor_notes'=>$data['notes']??null,'awarded_points'=>$data['points'],'graded_by'=>auth()->id(),'approved_at'=>now()]);
        $this->finalizeIfComplete($result->session_id);
        return response()->json(['result'=>$result->fresh()]);
    }

    public function checkPlagiarism(Request $request): JsonResponse {
        $data = $request->validate(['text'=>'required|string|min:20','references'=>'nullable|array']);
        return response()->json(['result'=>$this->ai->checkPlagiarism($data['text'],$data['references']??[])]);
    }

    public function evaluateCode(Request $request): JsonResponse {
        $data = $request->validate(['question'=>'required|string','code'=>'required|string','tests'=>'nullable|string','language'=>'required|string','max_points'=>'required|integer|min:1','session_id'=>'nullable|uuid','question_id'=>'nullable|uuid']);
        $result = $this->ai->evaluateCode($data['question'],$data['code'],$data['tests']??'',$data['language'],$data['max_points']);
        if ($data['session_id']??null && $data['question_id']??null) {
            AiGradingResult::updateOrCreate(['session_id'=>$data['session_id'],'question_id'=>$data['question_id']],['id'=>Str::uuid(),'student_answer'=>$data['code'],'ai_score'=>$result['score']??0,'awarded_points'=>$result['awarded']??0,'max_points'=>$data['max_points'],'grade'=>$result['grade']??'F','confidence'=>$result['confidence']??80,'feedback'=>$result['feedback']??'','status'=>'ai_graded','ai_graded_at'=>now()]);
        }
        return response()->json(['result'=>$result]);
    }

    private function finalizeIfComplete(string $sessionId): void {
        $session = ExamSession::with('exam')->findOrFail($sessionId);
        $results = AiGradingResult::where('session_id',$sessionId)->get();
        if ($results->every(fn($r)=>in_array($r->status,['instructor_approved','instructor_overridden']))) {
            $essay = $results->sum('awarded_points');
            $total = ($session->auto_score??0)+$essay;
            $pct = $session->total_possible>0?round($total/$session->total_possible*100):0;
            $session->update(['essay_score'=>$essay,'final_score'=>$pct,'passed'=>$pct>=$session->exam->pass_mark,'grade_status'=>'graded']);
            $this->webhook->dispatch('grading.complete',['session_id'=>$sessionId,'final_score'=>$pct]);
        }
    }
}
