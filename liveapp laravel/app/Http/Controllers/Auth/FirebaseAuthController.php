<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Illuminate\Support\Facades\DB; 
use App\Models\DeviceBlock;
use App\Support\OpsMetrics;
use App\Support\FirebaseAdminConfig;

class FirebaseAuthController extends Controller
{
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

    /**
     * Verify Firebase ID token (Google sign-in), upsert user, start session.
     * Expects JSON: { idToken: string }
     * Returns JSON: { ok: bool, redirect?: string, msg?: string }
     */
    public function login(Request $request)
    {
        try {
            $request->validate(['idToken' => 'required|string']);
        } catch (ValidationException $e) {
            return response()->json([
                'ok' => false,
                'code' => 'auth_validation_failed',
                'msg' => 'Google sign-in payload is incomplete.',
                'errors' => $e->errors(),
            ], 422);
        }

        // 0) Device ID (header first, body fallback)
        $deviceId = $request->header('X-Device-Id') ?? $request->input('device_id');
        Log::info('AUTH_WEB_LOGIN_START', [
            'request_id' => $request->header('X-Request-Id'),
            'ip' => $request->ip(),
            'has_device_id' => !empty($deviceId),
        ]);

        // 1) Device-level block gate
        if (DeviceBlock::isBlocked($deviceId)) {
            OpsMetrics::increment(OpsMetrics::AUTH_FAILURES);
            Log::warning('AUTH_WEB_LOGIN_DEVICE_BLOCKED', ['device_id' => $deviceId]);
            return response()->json([
                'ok'  => false,
                'msg' => 'This device is blocked.',
            ], 423);
        }

        // 2) Firebase Admin init (unchanged)
        try {
            $serviceAccountPath = FirebaseAdminConfig::serviceAccountPath();
            if (!is_file($serviceAccountPath)) {
                OpsMetrics::increment(OpsMetrics::AUTH_FAILURES);
                Log::error('AUTH_WEB_FIREBASE_CONFIG_MISSING', [
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
                Log::error('AUTH_WEB_FIREBASE_PROJECT_ID_MISSING');
                return response()->json([
                    'ok' => false,
                    'code' => 'firebase_project_id_missing',
                    'msg' => 'Firebase project id is missing from server configuration.',
                ], 503);
            }
            $auth = (new Factory())->withServiceAccount($serviceAccountPath)->withProjectId($projectId)->createAuth();
            Log::info('AUTH_WEB_FIREBASE_INIT_OK', ['project_id' => $projectId]);
        } catch (\Throwable $e) {
            OpsMetrics::increment(OpsMetrics::AUTH_FAILURES);
            Log::error('AUTH_WEB_FIREBASE_INIT_FAIL', ['error' => $e->getMessage()]);
            return response()->json([
                'ok' => false,
                'code' => 'firebase_init_failed',
                'msg' => 'Firebase authentication service could not be initialized.',
            ], 503);
        }

        // 3) Verify ID token (unchanged)
        try {
            $verified = $auth->verifyIdToken($request->idToken);
            Log::info('AUTH_WEB_VERIFY_OK', ['token_meta' => $this->tokenMeta((string) $request->idToken)]);
        } catch (\Throwable $e) {
            OpsMetrics::increment(OpsMetrics::AUTH_FAILURES);
            Log::warning('AUTH_WEB_FIREBASE_VERIFY_FAIL', [
                'error' => $e->getMessage(),
                'token_meta' => $this->tokenMeta((string) $request->idToken),
                'configured_project_id' => $projectId ?? null,
            ]);
            return response()->json([
                'ok' => false,
                'code' => 'firebase_token_invalid',
                'msg' => 'The Google sign-in token could not be verified. Please sign in again.',
            ], 401);
        }

        $uid   = $verified->claims()->get('sub');
        $email = $verified->claims()->get('email');
        $name  = $verified->claims()->get('name');
        $pic   = $verified->claims()->get('picture');
        $ev    = (bool) ($verified->claims()->get('email_verified') ?? false);

        if (!$email) {
            OpsMetrics::increment(OpsMetrics::AUTH_FAILURES);
            Log::warning('Firebase token missing email for UID: '.$uid);
            return response()->json([
                'ok' => false,
                'code' => 'firebase_email_missing',
                'msg' => 'Google did not provide an email address for this account.',
            ], 422);
        }

        // 4) Upsert local user (NO full overwrite on existing)
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
            Log::info('AUTH_WEB_USER_CREATED', ['user_id' => $user->id, 'firebase_uid' => $uid]);
        } else {
            $patch = [];
            if ($deviceId)                                 $patch['device_id'] = $deviceId;
            if (!$user->firebase_uid)                      $patch['firebase_uid'] = $uid;
            if ($ev && is_null($user->email_verified_at))  $patch['email_verified_at'] = now();
            if (!$user->avatar_url && $pic)                $patch['avatar_url'] = $pic;
            if (!empty($patch)) $user->forceFill($patch)->save();
            Log::info('AUTH_WEB_USER_FOUND', [
                'user_id' => $user->id,
                'patched' => array_keys($patch),
            ]);
        }

        // 5) Hard gate: account-level block
        if ($user->is_blocked) {
            OpsMetrics::increment(OpsMetrics::AUTH_FAILURES);
            Log::warning('AUTH_WEB_USER_BLOCKED', ['user_id' => $user->id]);
            return response()->json(['ok' => false, 'msg' => 'Your account is blocked.'], 423);
        }

        // 6) Start session (unchanged)
        Auth::login($user, true);
        Log::info('AUTH_WEB_LOGIN_SESSION_OK', ['user_id' => $user->id]);

        // 7) OPTIONAL: enforce single session if you're using database sessions
        if (config('session.driver') === 'database') {
            try {
                DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $user->getAuthIdentifier())
                    ->where('id', '!=', session()->getId())
                    ->delete();
            } catch (\Throwable $e) {
                Log::warning('SESSION_CULL_FAIL', ['user_id' => $user->id, 'ex' => $e->getMessage()]);
            }
        }

        $redirect = route('home');
        if (method_exists($user, 'hasRole')) {
            if ($user->hasRole('admin')) {
                $redirect = route('admin.dashboard');
            } elseif ($user->hasRole('agency')) {
                $redirect = route('agency.dashboard');
            }
        }
        Log::info('AUTH_WEB_LOGIN_SUCCESS', ['user_id' => $user->id, 'redirect' => $redirect]);

        return response()->json(['ok' => true, 'redirect' => $redirect]);
    }

    /**
     * Destroy session and redirect to home.
     */
    public function logout()
    {
        Log::info('AUTH_WEB_LOGOUT', ['user_id' => auth()->id()]);
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
        return redirect()->route('home');
    }
}
