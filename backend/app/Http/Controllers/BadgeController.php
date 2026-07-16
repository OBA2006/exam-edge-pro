<?php
namespace App\Http\Controllers;
use App\Models\Badge;
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Str;

class BadgeController extends Controller {
    public function index(Request $request): JsonResponse {
        return response()->json(Badge::with('user:id,name,email')->when($request->user_id,fn($q)=>$q->where('user_id',$request->user_id))->latest()->paginate(50));
    }
    public function award(Request $request): JsonResponse {
        $data = $request->validate(['user_id'=>'required|uuid|exists:users,id','type'=>'required|in:top_scorer,perfect,most_improved,fast_finisher,streak_3,integrity,first_pass,participation','reason'=>'nullable|string']);
        return response()->json(['badge'=>Badge::create(['id'=>Str::uuid(),'awarded_by'=>auth()->id()]+$data)], 201);
    }
    public function revoke(string $id): JsonResponse { Badge::findOrFail($id)->delete(); return response()->json(['message'=>'Badge revoked.']); }
}
