<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\DeviceBlock;
use App\Models\User;
use App\Services\ProfileService;
use App\Services\ThemeUnlockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DemoAuthApiController extends Controller
{
    public function __invoke(Request $request, ProfileService $profiles)
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        if (! (bool) config('app_features.demo_login_enabled', false)) {
            return $this->unavailable();
        }

        $submittedEmail = mb_strtolower(trim((string) $validated['email']));
        $configuredEmail = mb_strtolower(trim((string) config('app_features.demo_login_email', '')));
        if ($configuredEmail === '' || ! hash_equals($configuredEmail, $submittedEmail)) {
            Log::warning('AUTH_API_DEMO_EMAIL_REJECTED', [
                'ip' => $request->ip(),
                'has_configured_email' => $configuredEmail !== '',
            ]);

            return $this->unavailable();
        }

        $deviceId = $request->header('X-Device-Id') ?? $request->input('device_id');
        if (DeviceBlock::isBlocked($deviceId)) {
            return response()->json([
                'ok' => false,
                'error' => 'blocked',
                'msg' => 'Your account has been blocked.',
            ], 423);
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$configuredEmail])
            ->first();
        if (! $user) {
            Log::error('AUTH_API_DEMO_USER_MISSING');

            return $this->unavailable();
        }

        if ($user->is_blocked) {
            try {
                $user->tokens()->delete();
            } catch (\Throwable) {
            }

            return response()->json([
                'ok' => false,
                'error' => 'blocked',
                'msg' => 'Your account has been blocked.',
            ], 423);
        }

        try {
            app(ThemeUnlockService::class)->recordLogin($user);
            $user->refresh();
        } catch (\Throwable $error) {
            Log::warning('AUTH_API_DEMO_ACTIVITY_SYNC_FAIL', [
                'user_id' => $user->id,
                'error' => $error->getMessage(),
            ]);
        }

        try {
            $user->tokens()->delete();
        } catch (\Throwable $error) {
            Log::warning('AUTH_API_DEMO_TOKEN_REVOKE_FAIL', [
                'user_id' => $user->id,
                'error' => $error->getMessage(),
            ]);
        }

        $deviceName = trim((string) ($validated['device_name'] ?? 'flutter-demo')) ?: 'flutter-demo';
        $token = $user->createToken($deviceName, ['api', 'ws:connect'])->plainTextToken;
        $profile = $profiles->payload($user, $user);
        $profile['provider'] = $user->provider ?? 'demo';
        $profile['email_verified'] = (bool) $user->email_verified_at;
        $profile['is_blocked'] = false;
        $profile['permissions'] = $user->getAllPermissions()->pluck('name')->values()->all();

        Log::info('AUTH_API_DEMO_LOGIN_SUCCESS', [
            'user_id' => $user->id,
            'device_name' => $deviceName,
        ]);

        return response()->json([
            'ok' => true,
            'token' => $token,
            'is_new_user' => false,
            'user' => $profile,
        ]);
    }

    private function unavailable()
    {
        return response()->json([
            'ok' => false,
            'code' => 'demo_login_unavailable',
            'msg' => 'Demo login is unavailable for this email.',
        ], 403);
    }
}
