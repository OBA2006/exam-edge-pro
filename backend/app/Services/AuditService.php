<?php
namespace App\Services;
use Illuminate\Support\Str;

class AuditService {
    public function log(string $category, string $action, string $detail = '', string $severity = 'info', ?string $ip = null): void {
        try {
            \App\Models\AuditLog::create(['id'=>Str::uuid(),'user_id'=>auth()->id(),'category'=>$category,'action'=>$action,'detail'=>$detail,'severity'=>$severity,'ip_address'=>$ip??request()->ip(),'user_agent'=>request()->userAgent()]);
        } catch (\Exception $e) { \Log::warning('AuditService: '.$e->getMessage()); }
    }
}
