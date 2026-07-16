<?php
namespace App\Services;
use Illuminate\Support\Facades\Cache;

class CacheService {
    public function rememberExam(string $id, callable $cb): mixed { return Cache::remember("exam:{$id}",now()->addMinutes(30),$cb); }
    public function forgetExam(string $id): void { Cache::forget("exam:{$id}"); }
    public function setSession(string $id, array $data): void { Cache::put("session:{$id}",$data,now()->addHours(6)); }
    public function getSession(string $id): ?array { return Cache::get("session:{$id}"); }
    public function forgetSession(string $id): void { Cache::forget("session:{$id}"); }
}
