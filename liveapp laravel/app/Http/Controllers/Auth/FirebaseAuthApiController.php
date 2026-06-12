<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\DeviceBlock; // 👈 add
use App\Support\OpsMetrics;
use App\Support\FirebaseAdminConfig;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use App\Services\ThemeUnlockService;

class FirebaseAuthApiController extends Controller
{
    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function tokenMeta(string $jwt): array
    {
        try {
            $parts = explode('.', $jwt);
            if (count($parts) < 2) {
                return ['parse' => 'invalid_parts'];
            }
            $payload = $parts[1];
            $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
            $decoded = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);

            if (!is_array($decoded)) {
                return ['parse' => 'invalid_payload'];
            }

            return [
                'iss' => $decoded['iss'] ?? null,
                'aud' => $decoded['aud'] ?? null,
                'sub' => $decoded['sub'] ?? null,
                'exp' => $decoded['exp'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['parse' => 'exception', 'message' => $e->getMessage()];
        }
    }

    public function login(Request $request)
    {
        $startedAt = microtime(true);
        $stepAt = $startedAt;
        $timings = [];

        try {
            $request->validate([
                'idToken'     => 'required|string',
                'device_name' => 'nullable|string',
                'referral_code' => 'nullable|string|max:32',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'ok' => false,
                'code' => 'auth_validation_failed',
                'msg' => 'Mobile login payload is incomplete.',
                'errors' => $e->errors(),
            ], 422);
        }
        $timings['validate_ms'] = $this->elapsedMs($stepAt);

        $deviceName = $request->input('device_name', 'mobile');
        $deviceId   = $request->header('X-Device-Id') ?? $request->input('device_id'); // 👈
        Log::info('AUTH_API_LOGIN_START', [
            'request_id' => $request->header('X-Request-Id'),
            'ip' => $request->ip(),
            'has_device_id' => !empty($deviceId),
            'device_name' => $deviceName,
        ]);

        // 0) Device-level block gate
        $stepAt = microtime(true);
        if (DeviceBlock::isBlocked($deviceId)) {
            OpsMetrics::increment(OpsMetrics::AUTH_FAILURES);
            Log::warning('AUTH_API_DEVICE_BLOCKED', ['device_id' => $deviceId]);
            return response()->json([
                'ok'      => false,
                'error'   => 'blocked',
                'message' => 'Your account is blocked.',
            ], 423);
        }
        $timings['device_gate_ms'] = $this->elapsedMs($stepAt);

        // 1) Firebase Admin init (same as before)
        $stepAt = microtime(true);
        try {
            $serviceAccountPath = FirebaseAdminConfig::serviceAccountPath();
            if (!is_file($serviceAccountPath)) {
                OpsMetrics::increment(OpsMetrics::AUTH_FAILURES);
                Log::error('AUTH_API_FIREBASE_CONFIG_MISSING', [
                    'path' => $serviceAccountPath,
                ]);
                return response()->json([
                    'ok' => false,
                    'code' => 'firebase_service_account_missing',
                    'msg' => 'Firebase admin credentials are not configured on the server.',
                ], 503);
            }
            $serviceAccountJson = json_decode((string) @file_get_contents($serviceAccountPath), true) ?: [];
            $fileProjectId = $serviceAccountJson['project_id'] ?? null;
            $envProjectId = env('FIREBASE_PROJECT_ID');

            if ($envProjectId && $fileProjectId && $envProjectId !== $fileProjectId) {
                Log::warning('FIREBASE_PROJECT_ID_MISMATCH', [
                    'env_project_id' => $envProjectId,
                    'file_project_id' => $fileProjectId,
                ]);
            }

            $projectId = $fileProjectId ?: $envProjectId;
            if (!$projectId) {
                OpsMetrics::increment(OpsMetrics::AUTH_FAILURES);
                Log::error('AUTH_API_FIREBASE_PROJECT_ID_MISSING');
                return response()->json([
                    'ok' => false,
                    'code' => 'firebase_project_id_missing',
                    'msg' => 'Firebase project id is missing from server configuration.',
                ], 503);
            }
            $auth = (new Factory())->withServiceAccount($serviceAccountPath)->withProjectId($projectId)->createAuth();
            Log::info('AUTH_API_FIREBASE_INIT_OK', ['project_id' => $projectId]);
        } catch (\Throwable $e) {
            OpsMetrics::increment(OpsMetrics::AUTH_FAILURES);
            Log::error('AUTH_API_FIREBASE_INIT_FAIL', ['error' => $e->getMessage()]);
            return response()->json([
                'ok' => false,
                'code' => 'firebase_init_failed',
                'msg' => 'Firebase authentication service could not be initialized.',
            ], 503);
        }
        $timings['firebase_init_ms'] = $this->elapsedMs($stepAt);

        // 2) Verify ID token (same)
        $stepAt = microtime(true);
        try {
            $verified = $auth->verifyIdToken($request->idToken);
            Log::info('AUTH_API_VERIFY_OK', ['token_meta' => $this->tokenMeta((string) $request->idToken)]);
        } catch (\Throwable $e) {
            OpsMetrics::increment(OpsMetrics::AUTH_FAILURES);
            Log::warning('AUTH_API_FIREBASE_VERIFY_FAIL', [
                'error' => $e->getMessage(),
                'token_meta' => $this->tokenMeta((string) $request->idToken),
                'configured_project_id' => $projectId ?? null,
            ]);
            return response()->json([
                'ok' => false,
                'code' => 'firebase_token_invalid',
                'msg' => 'The Firebase ID token could not be verified. Please sign in again.',
            ], 401);
        }
        $timings['firebase_verify_ms'] = $this->elapsedMs($stepAt);

        $uid   = $verified->claims()->get('sub');
        $email = $verified->claims()->get('email');
        $name  = $verified->claims()->get('name');
        $pic   = $verified->claims()->get('picture');
        $ev    = (bool) ($verified->claims()->get('email_verified') ?? false);

        if (!$email) {
            OpsMetrics::increment(OpsMetrics::AUTH_FAILURES);
            return response()->json([
                'ok' => false,
                'code' => 'firebase_email_missing',
                'msg' => 'Google did not provide an email address for this account.',
            ], 422);
        }

        // 3) Upsert local user (no full overwrite on existing)
        $stepAt = microtime(true);
        $user = User::where('firebase_uid', $uid)->orWhere('email', $email)->first();

        if (!$user) {
            $user = User::create([
                'name'              => $name ?? 'User',
                'email'             => $email,
                'firebase_uid'      => $uid,
                'avatar_url'        => $pic,
                'provider'          => 'google',
                'email_verified_at' => $ev ? now() : null,
                'device_id'         => $deviceId,
                'password'          => bcrypt(str()->random(32)),
            ]);
            Log::info('AUTH_API_USER_CREATED', ['user_id' => $user->id, 'firebase_uid' => $uid]);
        } else {
            $patch = [];
            if ($deviceId)                                 $patch['device_id'] = $deviceId;  // 👈 always current
            if (!$user->firebase_uid)                      $patch['firebase_uid'] = $uid;
            if ($ev && is_null($user->email_verified_at))  $patch['email_verified_at'] = now();
            if (!$user->avatar_url && $pic)                $patch['avatar_url'] = $pic;
            if (!empty($patch)) $user->forceFill($patch)->save();
            Log::info('AUTH_API_USER_FOUND', [
                'user_id' => $user->id,
                'patched' => array_keys($patch),
            ]);
        }
        $timings['user_upsert_ms'] = $this->elapsedMs($stepAt);

        $stepAt = microtime(true);
        try {
            app(ThemeUnlockService::class)->recordLogin(
                $user,
                $request->input('referral_code')
            );
            $user->refresh();
        } catch (\Throwable $e) {
            Log::warning('AUTH_API_THEME_PROGRESS_SYNC_FAIL', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
        $timings['theme_sync_ms'] = $this->elapsedMs($stepAt);

        // 4) Account-level block gate
        $stepAt = microtime(true);
        if ($user->is_blocked) {
            try { $user->tokens()->delete(); } catch (\Throwable $e) {}
            OpsMetrics::increment(OpsMetrics::AUTH_FAILURES);
            Log::warning('AUTH_API_USER_BLOCKED', ['user_id' => $user->id]);
            return response()->json([
                'ok'      => false,
                'error'   => 'blocked',
                'message' => 'Your account is blocked.',
            ], 423);
        }
        $timings['user_block_gate_ms'] = $this->elapsedMs($stepAt);

        // 5) Enforce single active token: kill old tokens, issue fresh one
        $stepAt = microtime(true);
        try {
            $user->tokens()->delete();
        } catch (\Throwable $e) {
            Log::warning('SANCTUM_TOKEN_REVOKE_FAIL', ['user_id' => $user->id, 'ex' => $e->getMessage()]);
        }

        $token = $user->createToken($deviceName, ['api','ws:connect'])->plainTextToken;
        Log::info('AUTH_API_TOKEN_ISSUED', ['user_id' => $user->id, 'abilities' => ['api','ws:connect']]);
        $timings['token_issue_ms'] = $this->elapsedMs($stepAt);

        // 6) Extras
        $stepAt = microtime(true);
        $roles       = $user->getRoleNames()->values();
        $permissions = $user->getAllPermissions()->pluck('name')->values();
        $canGoLive   = (!$user->is_blocked) && (
            $user->hasAnyRole(['host','admin']) || $user->can('go live')
        );
        $hostProfile = optional($user->host)->only([
            'id',
            'stage_name',
            'country',
            'city',
            'bio',
            'contact_phone',
            'video_rooms_enabled',
            'audio_rooms_enabled',
            'video_calls_enabled',
            'audio_calls_enabled',
        ]);
        $level = app(\App\Services\UserLevelService::class)->profileProgress($user);
        $profileFrame = app(\App\Services\ProfileFrameService::class)->equippedFramePayload($user);
        $timings['response_enrichment_ms'] = $this->elapsedMs($stepAt);
        $timings['total_ms'] = $this->elapsedMs($startedAt);

        Log::info('AUTH_API_LOGIN_SUCCESS', [
            'user_id' => $user->id,
            'timings' => $timings,
        ]);

        return response()->json([
            'ok'    => true,
            'token' => $token,
            'user'  => [
                'id'             => $user->id,
                'name'           => $user->name,
                'email'          => $user->email,
                'avatar_url'     => $user->avatar_url,
                'provider'       => $user->provider,
                'email_verified' => (bool) $user->email_verified_at,
                'is_blocked'     => (bool) $user->is_blocked,
                'roles'          => $roles,
                'permissions'    => $permissions,
                'can_go_live'    => $canGoLive,
                'host_profile'   => $hostProfile,
                'profile_frame'  => $profileFrame,
                'level'          => $level['level'],
                'level_title'    => $level['level_title'],
                'badge_icon'     => $level['badge_icon'],
                'badge_color'    => $level['badge_color'],
                'lifetime_spend_coins' => $level['lifetime_spend_coins'],
                'next_level'     => $level['next_level'],
                'next_level_title' => $level['next_level_title'],
                'next_level_required_spend' => $level['next_level_required_spend'],
                'remaining_spend_to_next_level' => $level['remaining_spend_to_next_level'],
                'progress_percent' => $level['progress_percent'],
            ],
        ]);
    }

    public function logout(Request $request)
    {
        Log::info('AUTH_API_LOGOUT', ['user_id' => $request->user()?->id]);
        $request->user()?->currentAccessToken()?->delete();
        return response()->json(['ok' => true]);
    }
}
