<?php
namespace App\Http\Controllers;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\{DB, Cache};

class HealthController extends Controller {
    public function check(): JsonResponse {
        $checks = []; $status = 200;
        try { DB::select('SELECT 1'); $checks['database'] = ['status'=>'ok']; }
        catch(\Exception $e) { $checks['database'] = ['status'=>'error','message'=>$e->getMessage()]; $status = 503; }
        try { Cache::set('health_check',1,10); $checks['redis'] = ['status'=>'ok']; }
        catch(\Exception $e) { $checks['redis'] = ['status'=>'error']; $status = 503; }
        return response()->json(['status'=>$status===200?'healthy':'degraded','version'=>'2.0.0','timestamp'=>now()->toIso8601String(),'checks'=>$checks], $status);
    }
}
