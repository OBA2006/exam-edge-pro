<?php
namespace App\Http\Controllers;
use App\Models\{User, Badge, Certificate};
use App\Services\AuditService;
use Illuminate\Http\{Request, JsonResponse};

class UserController extends Controller {
    public function __construct(private AuditService $audit) {}
    public function index(Request $request): JsonResponse {
        $users = User::when($request->role, fn($q)=>$q->where('role',$request->role))->when($request->search, fn($q)=>$q->where('name','ilike',"%{$request->search}%")->orWhere('email','ilike',"%{$request->search}%"))->latest()->paginate($request->per_page??20);
        return response()->json($users);
    }
    public function show(string $id): JsonResponse { return response()->json(['user'=>User::findOrFail($id)->toPublicArray()]); }
    public function update(Request $request, string $id): JsonResponse {
        $user = User::findOrFail($id); $user->update($request->only(['name','institution','timezone','locale']));
        return response()->json(['user'=>$user->fresh()->toPublicArray()]);
    }
    public function destroy(string $id): JsonResponse {
        $user = User::findOrFail($id); $this->audit->log('admin','user_deleted',$user->email); $user->delete();
        return response()->json(['message'=>'User deleted.']);
    }
    public function suspend(string $id): JsonResponse {
        $user = User::findOrFail($id); $user->update(['is_active'=>false]); $this->audit->log('admin','user_suspended',$user->email);
        return response()->json(['message'=>'User suspended.']);
    }
    public function activate(string $id): JsonResponse { User::findOrFail($id)->update(['is_active'=>true]); return response()->json(['message'=>'User activated.']); }
    public function progress(string $id): JsonResponse { return response()->json(['progress'=>\App\Models\ExamSession::where('user_id',$id)->where('status','submitted')->with('exam:id,title')->get()]); }
    public function badges(string $id): JsonResponse { return response()->json(['badges'=>Badge::where('user_id',$id)->get()]); }
    public function certificates(string $id): JsonResponse { return response()->json(['certificates'=>Certificate::where('user_id',$id)->with('exam:id,title')->get()]); }
}
