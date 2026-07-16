<?php
namespace App\Http\Controllers;
use App\Models\Notification;
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Str;

class NotificationController extends Controller {
    public function index(Request $request): JsonResponse {
        return response()->json(Notification::where(fn($q)=>$q->where('user_id',auth()->id())->orWhere('recipient_type','all'))->latest()->paginate($request->per_page??20));
    }
    public function send(Request $request): JsonResponse {
        $data = $request->validate(['title'=>'required|string','body'=>'required|string','type'=>'in:info,success,warning,alert','recipient_type'=>'in:all,students,instructors,user','user_id'=>'nullable|uuid']);
        return response()->json(['notification'=>Notification::create(['id'=>Str::uuid()]+$data)], 201);
    }
    public function markRead(string $id): JsonResponse { Notification::where('id',$id)->where('user_id',auth()->id())->update(['read'=>true]); return response()->json(['message'=>'Marked read.']); }
    public function markAllRead(): JsonResponse { Notification::where('user_id',auth()->id())->update(['read'=>true]); return response()->json(['message'=>'All read.']); }
    public function destroy(string $id): JsonResponse { Notification::where('id',$id)->where('user_id',auth()->id())->delete(); return response()->json(['message'=>'Deleted.']); }
    public function unreadCount(): JsonResponse { return response()->json(['count'=>Notification::where('user_id',auth()->id())->where('read',false)->count()]); }
}
