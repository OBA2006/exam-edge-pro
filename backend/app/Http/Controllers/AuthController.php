<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Facades\{Hash, Password};
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Str;

class AuthController extends Controller {
    public function __construct(private AuditService $audit) {}

    public function register(Request $request): JsonResponse {
        $data = $request->validate(['name'=>'required|string|max:120','email'=>'required|email|unique:users,email','password'=>'required|min:8|confirmed','role'=>'sometimes|in:student,instructor,admin','institution'=>'nullable|string|max:200']);
        $user = User::create(['id'=>Str::uuid(),'name'=>$data['name'],'email'=>$data['email'],'password'=>Hash::make($data['password']),'role'=>$data['role']??'student','institution'=>$data['institution']??null]);
        $token = JWTAuth::fromUser($user);
        $this->audit->log('auth','user_registered',$user->email,'success',$request->ip());
        return response()->json(['message'=>'Account created.','user'=>$user->toPublicArray(),'token'=>$token,'expires_in'=>config('jwt.ttl')*60], 201);
    }

    public function login(Request $request): JsonResponse {
        $creds = $request->validate(['email'=>'required|email','password'=>'required|string']);
        if (!$token = JWTAuth::attempt($creds)) {
            $this->audit->log('auth','login_failed',$creds['email'],'warning',$request->ip());
            return response()->json(['message'=>'Invalid credentials.'], 401);
        }
        $user = auth()->user();
        if (!$user->is_active) { JWTAuth::invalidate(); return response()->json(['message'=>'Account suspended.'], 403); }
        if ($user->two_factor_enabled) {
            cache()->put("2fa_pending:{$user->id}", true, now()->addMinutes(5));
            return response()->json(['two_factor_required'=>true,'user_id'=>$user->id]);
        }
        $this->audit->log('auth','login_success',$user->email,'success',$request->ip());
        return $this->tokenResponse($token, $user);
    }

    public function verifyTwoFactor(Request $request): JsonResponse {
        $data = $request->validate(['user_id'=>'required','code'=>'required|string']);
        $user = User::findOrFail($data['user_id']);
        if (!cache()->has("2fa_pending:{$user->id}")) return response()->json(['message'=>'2FA session expired.'], 401);
        $totp = \OTPHP\TOTP::create($user->two_factor_secret ?? '');
        if (!$totp->verify($data['code'], null, 1)) return response()->json(['message'=>'Invalid 2FA code.'], 401);
        cache()->forget("2fa_pending:{$user->id}");
        return $this->tokenResponse(JWTAuth::fromUser($user), $user);
    }

    public function logout(): JsonResponse { JWTAuth::invalidate(JWTAuth::getToken()); return response()->json(['message'=>'Logged out.']); }
    public function me(): JsonResponse { return response()->json(['user'=>auth()->user()->toPublicArray()]); }
    public function refresh(): JsonResponse { return $this->tokenResponse(JWTAuth::refresh(JWTAuth::getToken()), auth()->user()); }

    public function forgotPassword(Request $request): JsonResponse {
        Password::sendResetLink($request->validate(['email'=>'required|email']));
        return response()->json(['message'=>'If that email exists, a reset link has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse {
        $data = $request->validate(['token'=>'required','email'=>'required|email','password'=>'required|min:8|confirmed']);
        $status = Password::reset($data, fn($u,$pw) => $u->forceFill(['password'=>Hash::make($pw)])->save());
        return $status === Password::PASSWORD_RESET ? response()->json(['message'=>'Password reset.']) : response()->json(['message'=>'Invalid token.'], 422);
    }

    public function setupTwoFactor(): JsonResponse {
        $user = auth()->user();
        $secret = base64_encode(random_bytes(20));
        $user->update(['two_factor_secret'=>$secret]);
        return response()->json(['secret'=>$secret,'message'=>'Enter this secret in your authenticator app.']);
    }

    public function enableTwoFactor(Request $request): JsonResponse {
        $user = auth()->user();
        $totp = \OTPHP\TOTP::create($user->two_factor_secret ?? '');
        if (!$totp->verify($request->validate(['code'=>'required|string'])['code'], null, 1)) return response()->json(['message'=>'Invalid code.'], 422);
        $user->update(['two_factor_enabled'=>true]);
        return response()->json(['message'=>'2FA enabled.']);
    }

    public function disableTwoFactor(Request $request): JsonResponse {
        $data = $request->validate(['password'=>'required|string']);
        $user = auth()->user();
        if (!Hash::check($data['password'], $user->password)) return response()->json(['message'=>'Incorrect password.'], 403);
        $user->update(['two_factor_enabled'=>false,'two_factor_secret'=>null]);
        return response()->json(['message'=>'2FA disabled.']);
    }

    public function getBackupCodes(): JsonResponse {
        $codes = array_map(fn() => strtoupper(Str::random(4).'-'.Str::random(4)), range(1, 8));
        auth()->user()->update(['two_factor_backup_codes'=>$codes]);
        return response()->json(['backup_codes'=>$codes]);
    }

    private function tokenResponse(string $token, $user): JsonResponse {
        return response()->json(['token'=>$token,'token_type'=>'Bearer','expires_in'=>config('jwt.ttl')*60,'user'=>$user->toPublicArray()]);
    }
}
