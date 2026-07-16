<?php
namespace App\Http\Controllers;
use App\Models\{ProctoringLog, ExamSession};
use App\Services\{AuditService, WebhookService};
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\{Str, Facades\Storage};

class ProctoringController extends Controller {
    public function __construct(private AuditService $audit, private WebhookService $webhook) {}

    public function logViolation(Request $request): JsonResponse {
        $data = $request->validate(['session_id'=>'required|uuid|exists:exam_sessions,id','violation_type'=>'required|in:tab_switch,face_absent,multiple_faces,phone_detected,copy_attempt,keyboard_shortcut,right_click,fullscreen_exit,screen_share','severity'=>'required|in:low,medium,high','description'=>'nullable|string','metadata'=>'nullable|array']);
        $session = ExamSession::findOrFail($data['session_id']);
        $delta = match($data['severity']){'high'=>35,'medium'=>20,'low'=>10};
        $log = ProctoringLog::create(['id'=>Str::uuid(),'session_id'=>$data['session_id'],'user_id'=>$session->user_id,'exam_id'=>$session->exam_id,'violation_type'=>$data['violation_type'],'severity'=>$data['severity'],'description'=>$data['description']??null,'metadata'=>$data['metadata']??null,'risk_score_delta'=>$delta]);
        if ($data['violation_type']==='tab_switch') $session->increment('tab_switches');
        if ($data['violation_type']==='copy_attempt') $session->increment('copy_attempts');
        $this->webhook->dispatch('violation.detected',['session_id'=>$data['session_id'],'type'=>$data['violation_type'],'severity'=>$data['severity']]);
        $risk = $session->getRiskScore();
        if ($risk >= 95) { $session->update(['status'=>'abandoned']); $this->audit->log('violation','auto_terminated',"Risk:{$risk}"); }
        return response()->json(['log'=>$log,'risk_score'=>$risk]);
    }

    public function sessionLogs(string $sessionId): JsonResponse {
        $session = ExamSession::findOrFail($sessionId);
        return response()->json(['logs'=>ProctoringLog::where('session_id',$sessionId)->latest()->get(),'risk_score'=>$session->getRiskScore(),'tab_switches'=>$session->tab_switches]);
    }

    public function examLogs(string $examId): JsonResponse {
        $sessions = ExamSession::where('exam_id',$examId)->with(['user','proctoringLogs'])->withCount('proctoringLogs')->get()
            ->map(fn($s)=>['session_id'=>$s->id,'user'=>$s->user->toPublicArray(),'violations_count'=>$s->proctoring_logs_count,'risk_score'=>$s->getRiskScore(),'tab_switches'=>$s->tab_switches,'status'=>$s->status]);
        return response()->json(['sessions'=>$sessions,'total_violations'=>ProctoringLog::where('exam_id',$examId)->count(),'high_risk'=>$sessions->filter(fn($s)=>$s['risk_score']>=60)->count()]);
    }

    public function saveScreenshot(Request $request): JsonResponse {
        $data = $request->validate(['session_id'=>'required|uuid','image'=>'required|string']);
        $img = base64_decode(preg_replace('/^data:image\/\w+;base64,/','',$data['image']));
        $path = "proctoring/{$data['session_id']}/".Str::uuid().'.jpg';
        Storage::put($path,$img);
        return response()->json(['url'=>Storage::url($path)]);
    }

    public function terminateSession(string $sessionId): JsonResponse {
        $session = ExamSession::findOrFail($sessionId);
        $session->update(['status'=>'abandoned']);
        ProctoringLog::create(['id'=>Str::uuid(),'session_id'=>$sessionId,'user_id'=>$session->user_id,'exam_id'=>$session->exam_id,'violation_type'=>'fullscreen_exit','severity'=>'high','description'=>'Terminated by instructor']);
        $this->audit->log('violation','session_terminated',"Session:{$sessionId}");
        return response()->json(['message'=>'Session terminated.']);
    }
}
