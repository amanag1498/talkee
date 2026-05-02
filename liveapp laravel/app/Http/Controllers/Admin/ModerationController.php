<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModerationRule;
use App\Models\UnblockRequest;
use App\Models\User;
use App\Models\UserReport;
use App\Services\ModerationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ModerationController extends Controller
{
    public function __construct(private ModerationService $moderation)
    {
    }

    public function blockedUsers(Request $request)
    {
        $rows = $this->moderation->adminBlockedUsersQuery()
            ->when($request->filled('host_user_id'), fn ($q) => $q->where('host_user_id', $request->integer('host_user_id')))
            ->when($request->filled('blocked_user_id'), fn ($q) => $q->where('blocked_user_id', $request->integer('blocked_user_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->paginate(25);

        return view('admin.moderation.blocked-users', [
            'rows' => $rows,
            'hosts' => User::query()->role('host')->orderBy('name')->limit(200)->get(['id', 'name']),
        ]);
    }

    public function adminUnblock(Request $request)
    {
        $data = $request->validate([
            'host_user_id' => 'required|integer|exists:users,id',
            'blocked_user_id' => 'required|integer|exists:users,id',
        ]);

        $hostUser = User::query()->findOrFail((int) $data['host_user_id']);
        $blockedUser = User::query()->findOrFail((int) $data['blocked_user_id']);
        $this->moderation->unblockUserForHost($hostUser, $blockedUser, $request->user(), 'Admin override');

        return back()->with('ok', 'User unblocked.');
    }

    public function reports(Request $request)
    {
        $rows = $this->moderation->adminReportsQuery()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->trim()))
            ->when($request->filled('reason_type'), fn ($q) => $q->where('reason_type', $request->string('reason_type')->trim()))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->paginate(25);

        return view('admin.moderation.reports', ['rows' => $rows]);
    }

    public function reviewReport(Request $request, UserReport $report)
    {
        $data = $request->validate([
            'status' => 'required|in:reviewed,dismissed,action_taken',
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        $this->moderation->reviewReport($report, $request->user(), $data['status'], $data['admin_notes'] ?? null);

        return back()->with('ok', 'Report updated.');
    }

    public function history(Request $request)
    {
        $rows = $this->moderation->adminModerationHistoryQuery()
            ->when($request->filled('actor_user_id'), fn ($q) => $q->where('actor_user_id', $request->integer('actor_user_id')))
            ->when($request->filled('target_user_id'), fn ($q) => $q->where('target_user_id', $request->integer('target_user_id')))
            ->when($request->filled('host_user_id'), fn ($q) => $q->where('host_user_id', $request->integer('host_user_id')))
            ->when($request->filled('action_type'), fn ($q) => $q->where('action_type', $request->string('action_type')->trim()))
            ->paginate(30);

        return view('admin.moderation.history', ['rows' => $rows]);
    }

    public function rules()
    {
        return view('admin.moderation.rules', [
            'rules' => ModerationRule::query()->latest('id')->paginate(30),
            'ruleTypes' => ModerationRule::RULE_TYPES,
            'actions' => ModerationRule::ACTIONS,
            'severities' => ModerationRule::SEVERITIES,
        ]);
    }

    public function storeRule(Request $request)
    {
        $data = $this->validateRule($request);
        ModerationRule::query()->create($data);
        $this->moderation->publishModerationCacheInvalidation('rules');
        return back()->with('ok', 'Moderation rule created.');
    }

    public function updateRule(Request $request, ModerationRule $moderationRule)
    {
        $data = $this->validateRule($request, $moderationRule->id);
        $moderationRule->update($data);
        $this->moderation->publishModerationCacheInvalidation('rules');
        return back()->with('ok', 'Moderation rule updated.');
    }

    public function destroyRule(ModerationRule $moderationRule)
    {
        $moderationRule->delete();
        $this->moderation->publishModerationCacheInvalidation('rules');
        return back()->with('ok', 'Moderation rule deleted.');
    }

    public function analytics()
    {
        return view('admin.moderation.analytics', [
            'analytics' => $this->moderation->analyticsPayload(),
        ]);
    }

    private function validateRule(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
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
            'severity' => ['required', Rule::in(ModerationRule::SEVERITIES)],
            'is_active' => 'nullable|boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        return $data;
    }
}
