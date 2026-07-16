<?php
namespace App\Http\Controllers;
use App\Models\{Certificate, ExamSession};
use App\Services\AuditService;
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Str;

class CertificateController extends Controller {
    public function __construct(private AuditService $audit) {}

    public function index(): JsonResponse {
        $user = auth()->user();
        $certs = Certificate::with(['exam:id,title','user:id,name'])->when($user->isStudent(),fn($q)=>$q->where('user_id',$user->id))->latest('issued_at')->get();
        return response()->json(['certificates'=>$certs]);
    }

    public function issue(Request $request): JsonResponse {
        $data = $request->validate(['user_id'=>'required|uuid|exists:users,id','exam_id'=>'required|uuid|exists:exams,id','session_id'=>'required|uuid|exists:exam_sessions,id','type'=>'required|in:completion,excellence,distinction,participation','institution'=>'required|string']);
        $session = ExamSession::findOrFail($data['session_id']);
        if (!$session->passed) return response()->json(['message'=>'Student has not passed this exam.'], 422);
        $prev = Certificate::latest()->first();
        $prevHash = $prev?->hash ?? str_repeat('0',64);
        $hash = hash('sha256',implode('|',[$data['user_id'],$data['exam_id'],$data['type'],now()->timestamp,$data['institution']]).$prevHash);
        $cert = Certificate::create(['id'=>Str::uuid(),'hash'=>$hash,'prev_hash'=>$prevHash,'block_number'=>Certificate::count()+1,'final_score'=>$session->final_score,'issued_at'=>now()]+$data);
        $this->audit->log('exam','certificate_issued',"Hash:{$hash}");
        return response()->json(['certificate'=>$cert->load(['user:id,name','exam:id,title'])], 201);
    }

    public function show(string $id): JsonResponse { return response()->json(['certificate'=>Certificate::with(['user:id,name','exam:id,title'])->findOrFail($id)]); }

    public function verify(string $hash): JsonResponse {
        $cert = Certificate::with(['user:id,name','exam:id,title'])->where('hash',$hash)->first();
        if (!$cert) return response()->json(['valid'=>false,'message'=>'Certificate not found or invalid.'], 404);
        return response()->json(['valid'=>true,'certificate'=>['student'=>$cert->user->name,'exam'=>$cert->exam->title,'type'=>$cert->type,'institution'=>$cert->institution,'score'=>$cert->final_score,'issued_at'=>$cert->issued_at,'block'=>$cert->block_number]]);
    }

    public function ledger(): JsonResponse { return response()->json(['ledger'=>Certificate::with(['user:id,name','exam:id,title'])->latest('issued_at')->take(20)->get()]); }

    public function download(string $id): JsonResponse {
        $cert = Certificate::with(['user','exam'])->findOrFail($id);
        return response()->json(['message'=>'PDF generation triggered.','cert'=>$cert]);
    }
}
