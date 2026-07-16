<?php
namespace App\Http\Controllers;
use App\Models\AuditLog;
use Illuminate\Http\{Request, JsonResponse};

class AuditController extends Controller {
    public function index(Request $request): JsonResponse {
        return response()->json(AuditLog::with('user:id,name,email')->when($request->category,fn($q)=>$q->where('category',$request->category))->latest()->paginate($request->per_page??50));
    }
    public function export(): \Symfony\Component\HttpFoundation\StreamedResponse {
        return response()->stream(function(){
            $h=fopen('php://output','w'); fputcsv($h,['ID','User','Category','Action','Detail','Severity','IP','Time']);
            AuditLog::with('user')->chunk(500,fn($logs,$h)=>array_map(fn($l)=>fputcsv($h,[$l->id,$l->user?->email,$l->category,$l->action,$l->detail,$l->severity,$l->ip_address,$l->created_at]),$logs->all()));
            fclose($h);
        },200,['Content-Type'=>'text/csv','Content-Disposition'=>'attachment; filename=audit-log.csv']);
    }
}
