<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HostUserBlock;
use App\Models\ModerationAction;
use App\Models\ModerationRule;
use App\Models\User;
use App\Models\UserReport;
use App\Services\ModerationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminModerationController extends Controller
{
    public function __construct(private ModerationService $moderation)
    {
    }

    private function assertAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole(['admin', 'super-admin']), 403);
    }

    public function blockedUsers(Request $request)
    {
        $this->assertAdmin($request);
        $rows = $this->moderation->adminBlockedUsersQuery()
            ->when($request->filled('host_user_id'), fn ($q) => $q->where('host_user_id', $request->integer('host_user_id')))
            ->when($request->filled('blocked_user_id'), fn ($q) => $q->where('blocked_user_id', $request->integer('blocked_user_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->paginate(30);

        return response()->json(['ok' => true, 'data' => $rows->items(), 'meta' => [
            'current_page' => $rows->currentPage(),
            'per_page' => $rows->perPage(),
            'has_more' => $rows->hasMorePages(),
            'total' => $rows->total(),
        ]]);
    }

    public function unblockUser(Request $request)
    {
        $this->assertAdmin($request);
        $data = $request->validate([
            'host_user_id' => 'required|integer|exists:users,id',
            'blocked_user_id' => 'required|integer|exists:users,id',
        ]);

        $hostUser = User::query()->findOrFail((int) $data['host_user_id']);
        $blockedUser = User::query()->findOrFail((int) $data['blocked_user_id']);
        $this->moderation->unblockUserForHost($hostUser, $blockedUser, $request->user(), 'Admin override');

        return response()->json(['ok' => true]);
    }

    public function reports(Request $request)
    {
        $this->assertAdmin($request);
        $rows = $this->moderation->adminReportsQuery()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->trim()))
            ->when($request->filled('reason_type'), fn ($q) => $q->where('reason_type', $request->string('reason_type')->trim()))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->paginate(30);

        return response()->json(['ok' => true, 'data' => $rows->items(), 'meta' => [
            'current_page' => $rows->currentPage(),
            'per_page' => $rows->perPage(),
            'has_more' => $rows->hasMorePages(),
            'total' => $rows->total(),
        ]]);
    }

    public function reviewReport(Request $request, int $id)
    {
        $this->assertAdmin($request);
        $data = $request->validate([
            'status' => 'required|in:reviewed,dismissed,action_taken',
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        $report = UserReport::query()->findOrFail($id);
        $report = $this->moderation->reviewReport($report, $request->user(), $data['status'], $data['admin_notes'] ?? null);

        return response()->json(['ok' => true, 'data' => $report]);
    }

    public function history(Request $request)
    {
        $this->assertAdmin($request);
        $rows = $this->moderation->adminModerationHistoryQuery()
            ->when($request->filled('actor_user_id'), fn ($q) => $q->where('actor_user_id', $request->integer('actor_user_id')))
            ->when($request->filled('target_user_id'), fn ($q) => $q->where('target_user_id', $request->integer('target_user_id')))
            ->when($request->filled('host_user_id'), fn ($q) => $q->where('host_user_id', $request->integer('host_user_id')))
            ->when($request->filled('action_type'), fn ($q) => $q->where('action_type', $request->string('action_type')->trim()))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->paginate(40);

        return response()->json(['ok' => true, 'data' => $rows->items(), 'meta' => [
            'current_page' => $rows->currentPage(),
            'per_page' => $rows->perPage(),
            'has_more' => $rows->hasMorePages(),
            'total' => $rows->total(),
        ]]);
    }

    public function rules(Request $request)
    {
        $this->assertAdmin($request);
        return response()->json(['ok' => true, 'data' => ModerationRule::query()->latest('id')->get()]);
    }

    public function storeRule(Request $request)
    {
        $this->assertAdmin($request);
        $data = $this->validateRulePayload($request);
        $data['is_active'] = $request->boolean('is_active', true);
        $rule = ModerationRule::query()->create($data);
        $this->moderation->publishModerationCacheInvalidation('rules');
        return response()->json(['ok' => true, 'data' => $rule], 201);
    }

    public function updateRule(Request $request, int $id)
    {
        $this->assertAdmin($request);
        $rule = ModerationRule::query()->findOrFail($id);
        $data = $this->validateRulePayload($request, $rule->id);
        $data['is_active'] = $request->boolean('is_active', true);
        $rule->update($data);
        $this->moderation->publishModerationCacheInvalidation('rules');
        return response()->json(['ok' => true, 'data' => $rule->fresh()]);
    }

    public function deleteRule(Request $request, int $id)
    {
        $this->assertAdmin($request);
        ModerationRule::query()->findOrFail($id)->delete();
        $this->moderation->publishModerationCacheInvalidation('rules');
        return response()->json(['ok' => true]);
    }

    public function analytics(Request $request)
    {
        $this->assertAdmin($request);
        return response()->json(['ok' => true, 'data' => $this->moderation->analyticsPayload()]);
    }

    private function validateRulePayload(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'rule_key' => [
                'required',
                'string',
                'max:120',
                Rule::unique('moderation_rules', 'rule_key')->ignore($ignoreId),
            ],
            'rule_type' => ['required', Rule::in(ModerationRule::RULE_TYPES)],
            'pattern' => [
                Rule::requiredIf(in_array($request->input('rule_type'), ['bad_word', 'custom'], true)),
                'nullable',
                'string',
                'max:1000',
            ],
            'threshold' => [
                Rule::requiredIf(in_array($request->input('rule_type'), ['spam', 'flooding'], true)),
                'nullable',
                'integer',
                'min:1',
                'max:1000',
            ],
            'action' => ['required', Rule::in(ModerationRule::ACTIONS)],
            'duration_minutes' => [
                Rule::requiredIf(in_array($request->input('rule_type'), ['spam', 'flooding'], true)),
                'nullable',
                'integer',
                'min:1',
                'max:10080',
            ],
            'is_active' => 'nullable|boolean',
            'severity' => ['required', Rule::in(ModerationRule::SEVERITIES)],
        ]);
    }
}
