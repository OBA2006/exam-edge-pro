<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;

class RoleMiddleware {
    public function handle(Request $request, Closure $next, string ...$roles): mixed {
        $user = auth()->user();
        if (!$user) return response()->json(['message'=>'Unauthenticated.'], 401);
        if (!in_array($user->role, $roles)) return response()->json(['message'=>'Forbidden. Required: '.implode('|',$roles)], 403);
        return $next($request);
    }
}
