<?php
namespace App\Http\Controllers;
use App\Models\{GradeAppeal, ExamSession};
use App\Services\{AiService, AuditService};
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Str;

class AppealController extends Controller {
    public function __construct(private AiService $ai, private AuditService $audit) {}

    public function index(Request $request): JsonResponse {
        return response()->json(GradeAppeal::with(['user:id,name,email','session.exam:id,title'])->when($request->status,fn($q)=>$q->where('status',$request->status))->latest()->paginate(20));
    }

    public function store(Request $request): JsonResponse {
        $data = $request->validate(['session_id'=>'required|uuid|exists:exam_sessions,id','reason'=>'required|in:marking_error,technical,medical,ai_grading,other','statement'=>'required|string|min:20']);
        $session = ExamSession::findOrFail($data['session_id']);
        $appeal = GradeAppeal::create(['id'=>Str::uuid(),'user_id'=>auth()->id(),'original_score'=>$session->final_score]+$data);
        return response()->json(['appeal'=>$appeal,'reference'=>'#'.strtoupper(substr($appeal->id,0,8))], 201);
    }

    public function show(string $id): JsonResponse { return response()->json(['appeal'=>GradeAppeal::with(['user','session.exam'])->findOrFail($id)]); }

    public function decide(Request $request, string $id): JsonResponse {
        $data = $request->validate(['status'=>'required|in:upheld,modified,rejected','revised_score'=>'nullable|integer|min:0|max:100','notes'=>'nullable|string']);
        $appeal = GradeAppeal::findOrFail($id);
        $appeal->update(['status'=>$data['status'],'revised_score'=>$data['revised_score']??null,'reviewer_notes'=>$data['notes']??null,'reviewed_by'=>auth()->id(),'reviewed_at'=>now()]);
        $this->audit->log('admin','appeal_decided',"Appeal {$id}: {$data['status']}");
        return response()->json(['appeal'=>$appeal->fresh()]);
    }

    public function aiReview(string $id): JsonResponse {
        $appeal = GradeAppeal::with(['session.exam','user'])->findOrFail($id);
        $rec = $this->ai->reviewAppeal(['email'=>$appeal->user->email,'exam_title'=>$appeal->session?->exam?->title??'Unknown','original_score'=>$appeal->original_score,'reason'=>$appeal->reason,'statement'=>$appeal->statement]);
        $appeal->update(['ai_recommendation'=>$rec]);
        return response()->json(['recommendation'=>$rec]);
    }
}
