<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Theme;
use App\Models\User;
use App\Services\ThemeTokenService;
use App\Services\ThemeUnlockService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ThemeAdminController extends Controller
{
    public function __construct(
        private ThemeUnlockService $themes,
        private ThemeTokenService $themeTokens,
    )
    {
    }

    public function index()
    {
        return view('admin.themes.index', [
            'themes' => Theme::query()
                ->withCount('userUnlocks')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function create()
    {
        return view('admin.themes.edit', [
            'theme' => new Theme([
                'token_source' => 'local',
                'is_active' => true,
                'is_default' => false,
                'is_limited' => false,
                'sort_order' => 0,
            ]),
            'isCreate' => true,
            'themeKeys' => $this->themes->themeKeys(),
            'requiredTokenKeys' => $this->themeTokens->requiredKeys(),
            'subscriptionPlans' => \App\Models\SubscriptionPlan::query()
                ->where('is_active', true)
                ->orderBy('price_coins')
                ->get(),
        ]);
    }

    public function show(Theme $theme)
    {
        $theme->load([
            'requiredSubscriptionPlan',
            'userUnlocks' => fn ($query) => $query->with(['user', 'grantedBy'])->latest('id')->limit(100),
        ]);

        return view('admin.themes.show', [
            'theme' => $theme,
            'users' => $theme->userUnlocks,
        ]);
    }

    public function edit(Theme $theme)
    {
        return view('admin.themes.edit', [
            'theme' => $theme->load('requiredSubscriptionPlan'),
            'isCreate' => false,
            'themeKeys' => $this->themes->themeKeys(),
            'requiredTokenKeys' => $this->themeTokens->requiredKeys(),
            'subscriptionPlans' => \App\Models\SubscriptionPlan::query()
                ->where('is_active', true)
                ->orderBy('price_coins')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $theme = new Theme();
        $data = $this->validatedPayload($request, $theme, true);
        $theme->fill($data)->save();
        $this->afterThemeSave($theme, (bool) $data['is_default']);

        return redirect()
            ->route('admin.themes.edit', $theme)
            ->with('ok', 'Theme created.');
    }

    public function update(Request $request, Theme $theme)
    {
        $data = $this->validatedPayload($request, $theme, false);
        $theme->update($data);
        $this->afterThemeSave($theme, (bool) $data['is_default']);

        return redirect()
            ->route('admin.themes.edit', $theme)
            ->with('ok', 'Theme updated.');
    }

    public function grant(Request $request, Theme $theme)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'source' => 'nullable|string|max:80',
            'expires_at' => 'nullable|date',
            'metadata_json' => 'nullable|string',
        ]);

        $metadata = [];
        if (!empty($data['metadata_json'])) {
            $metadata = json_decode($data['metadata_json'], true);
            if (!is_array($metadata)) {
                return back()->withErrors(['metadata_json' => 'Metadata must be valid JSON.'])->withInput();
            }
        }

        $user = User::query()->findOrFail($data['user_id']);

        $this->themes->grantTheme(
            user: $user,
            themeKey: $theme->key,
            source: $data['source'] ?: 'admin_grant',
            grantedBy: $request->user(),
            expiresAt: !empty($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
            metadata: $metadata,
        );

        return redirect()
            ->route('admin.themes.show', $theme)
            ->with('ok', 'Theme granted.');
    }

    public function revoke(Request $request, Theme $theme, User $user)
    {
        if ($theme->key === ThemeUnlockService::FALLBACK_THEME_KEY) {
            return back()->withErrors(['user' => 'Midnight cannot be revoked.']);
        }

        $this->themes->revokeTheme($user, $theme->key);

        return redirect()
            ->route('admin.themes.show', $theme)
            ->with('ok', 'Theme revoked.');
    }

    private function validatedPayload(Request $request, Theme $theme, bool $isCreate): array
    {
        $data = $request->validate([
            'key' => [
                Rule::requiredIf($isCreate),
                'nullable',
                'string',
                'max:80',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('themes', 'key')->ignore($theme->id),
            ],
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:1000',
            'unlock_type' => ['required', 'string', Rule::in([
                'free',
                'subscription',
                'vip_subscription',
                'vip_high_tier',
                'first_recharge',
                'recharge_milestone',
                'gift_spend',
                'user_level',
                'host_level',
                'login_streak',
                'pk_event',
                'referral',
                'host_follower_milestone',
                'agency_host_elite',
                'admin_grant',
                'event_reward',
                'festival_event',
                'limited_paid',
                'loyalty',
                'top_spender',
            ])],
            'token_source' => ['required', 'string', Rule::in(['local', 'remote', 'hybrid'])],
            'is_active' => 'required|boolean',
            'is_default' => 'required|boolean',
            'is_limited' => 'required|boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'price' => 'nullable|numeric|min:0',
            'required_subscription_plan_id' => 'nullable|integer|exists:subscription_plans,id',
            'required_total_recharge' => 'nullable|numeric|min:0',
            'required_total_gift_spend' => 'nullable|integer|min:0',
            'required_user_level' => 'nullable|integer|min:0',
            'required_host_level' => 'nullable|integer|min:0',
            'required_login_streak_days' => 'nullable|integer|min:0',
            'required_referrals' => 'nullable|integer|min:0',
            'required_host_followers' => 'nullable|integer|min:0',
            'event_key' => 'nullable|string|max:120',
            'sort_order' => 'required|integer|min:0',
            'min_app_version' => 'nullable|integer|min:1',
            'max_app_version' => 'nullable|integer|min:1|gte:min_app_version',
            'metadata_json' => 'nullable|string',
            'remote_tokens_json' => 'nullable|string',
            'save_as_draft' => 'nullable|boolean',
        ]);

        $themeKey = strtolower(trim((string) ($data['key'] ?? $theme->key)));
        if ($themeKey === '') {
            $themeKey = ThemeUnlockService::FALLBACK_THEME_KEY;
        }
        $data['key'] = $themeKey;

        if ($themeKey === ThemeUnlockService::FALLBACK_THEME_KEY && !$data['is_active']) {
            throw ValidationException::withMessages([
                'is_active' => 'Midnight cannot be disabled.',
            ]);
        }

        if ($data['is_default']) {
            Theme::query()
                ->when(!$isCreate, fn ($query) => $query->whereKeyNot($theme->id))
                ->where('is_default', true)
                ->update(['is_default' => false]);
        } elseif ($themeKey === ThemeUnlockService::FALLBACK_THEME_KEY) {
            $data['is_default'] = true;
        }

        if ($data['token_source'] === 'local' && !in_array($themeKey, $this->themes->themeKeys(), true)) {
            throw ValidationException::withMessages([
                'token_source' => 'Local token source requires a built-in Flutter theme key.',
            ]);
        }

        try {
            $remoteTokens = $this->themeTokens->validateAndSanitizeRemoteTokens($data['remote_tokens_json'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'remote_tokens_json' => $e->getMessage(),
            ]);
        }

        if (
            !$data['is_active']
            && $data['token_source'] !== 'local'
            && $remoteTokens !== null
            && !$request->boolean('save_as_draft')
        ) {
            throw ValidationException::withMessages([
                'save_as_draft' => 'Inactive remote or hybrid themes require Save as Draft to keep remote tokens.',
            ]);
        }

        $requiresRemoteTokens = $data['token_source'] === 'remote'
            || ($data['token_source'] === 'hybrid' && !in_array($themeKey, $this->themes->themeKeys(), true));

        if ($requiresRemoteTokens && $remoteTokens === null) {
            throw ValidationException::withMessages([
                'remote_tokens_json' => 'Remote tokens are required for this token source.',
            ]);
        }

        if ($data['token_source'] === 'local') {
            $remoteTokens = null;
        }

        $metadata = null;
        if (!empty($data['metadata_json'])) {
            $metadata = json_decode($data['metadata_json'], true);
            if (!is_array($metadata)) {
                throw ValidationException::withMessages([
                    'metadata_json' => 'Metadata must be valid JSON.',
                ]);
            }
        }

        unset($data['metadata_json'], $data['remote_tokens_json'], $data['save_as_draft']);
        $data['metadata'] = $metadata;
        $data['remote_tokens'] = $remoteTokens;
        $data['preview_tokens'] = $remoteTokens;

        return $data;
    }

    private function afterThemeSave(Theme $theme, bool $isDefault): void
    {
        if ($isDefault) {
            Theme::query()
                ->whereKeyNot($theme->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        if (!Theme::query()->where('is_default', true)->exists()) {
            Theme::query()
                ->where('key', ThemeUnlockService::FALLBACK_THEME_KEY)
                ->update(['is_default' => true, 'is_active' => true]);
        }

        $this->themes->flushCache();
    }
}
