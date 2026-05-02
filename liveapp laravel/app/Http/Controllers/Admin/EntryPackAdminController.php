<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EntryPack;
use App\Models\UserEntryPack;
use App\Services\AdminAuditService;
use App\Services\EntryPackService;
use Illuminate\Http\Request;

class EntryPackAdminController extends Controller
{
    public function __construct(
        private EntryPackService $entryPacks,
        private AdminAuditService $audits,
    )
    {
    }

    public function index(Request $request)
    {
        $query = EntryPack::query()->orderBy('sort_order')->orderByDesc('priority')->orderBy('id');

        if ($search = $request->string('s')->trim()) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->filled('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        $packs = $query->paginate(20);
        $report = $this->entryPacks->reportSummary();
        $report['expired_owned'] = UserEntryPack::query()->whereNotNull('expires_at')->where('expires_at', '<=', now())->count();
        $report['expiry_churn_rate'] = $report['purchases'] > 0
            ? round(($report['expired_owned'] / max(1, $report['purchases'])) * 100, 1)
            : 0;

        return view('admin.entry-packs.index', compact('packs', 'report'));
    }

    public function create()
    {
        return view('admin.entry-packs.create');
    }

    public function store(Request $request)
    {
        $pack = EntryPack::query()->create($this->validated($request));
        $this->audits->log('entry_packs', 'entry_pack_created', $request->user(), null, $pack, null, $pack->toArray(), $request->input('reason'));

        return redirect()->route('admin.entry-packs.index')->with('ok', 'Entry pack created.');
    }

    public function edit(EntryPack $entry_pack)
    {
        return view('admin.entry-packs.edit', ['pack' => $entry_pack]);
    }

    public function update(Request $request, EntryPack $entry_pack)
    {
        $before = $entry_pack->toArray();
        $entry_pack->update($this->validated($request));
        $this->audits->log('entry_packs', 'entry_pack_updated', $request->user(), null, $entry_pack, $before, $entry_pack->fresh()->toArray(), $request->input('reason'));

        return redirect()->route('admin.entry-packs.index')->with('ok', 'Entry pack updated.');
    }

    public function destroy(EntryPack $entry_pack)
    {
        $before = $entry_pack->toArray();
        $entry_pack->delete();
        $this->audits->log('entry_packs', 'entry_pack_deleted', request()->user(), null, $entry_pack, $before, null, request('reason'));

        return redirect()->route('admin.entry-packs.index')->with('ok', 'Entry pack deleted.');
    }

    public function reports()
    {
        $report = $this->entryPacks->reportSummary();
        $report['expired_owned'] = UserEntryPack::query()->whereNotNull('expires_at')->where('expires_at', '<=', now())->count();
        $report['expiry_churn_rate'] = $report['purchases'] > 0
            ? round(($report['expired_owned'] / max(1, $report['purchases'])) * 100, 1)
            : 0;
        $recentPurchases = UserEntryPack::query()
            ->with(['user:id,name,email', 'entryPack:id,name,price_coins,animation_style'])
            ->latest('id')
            ->paginate(25);

        return view('admin.entry-packs.reports', compact('report', 'recentPurchases'));
    }

    public function editPurchase(UserEntryPack $userEntryPack)
    {
        $userEntryPack->load(['user:id,name,email', 'entryPack']);
        $packs = EntryPack::query()
            ->orderBy('sort_order')
            ->orderByDesc('priority')
            ->get(['id', 'name']);

        return view('admin.entry-packs.edit-purchase', [
            'userPack' => $userEntryPack,
            'packs' => $packs,
        ]);
    }

    public function updatePurchase(Request $request, UserEntryPack $userEntryPack)
    {
        $before = $userEntryPack->fresh(['user', 'entryPack'])->toArray();
        $data = $request->validate([
            'entry_pack_id' => 'required|exists:entry_packs,id',
            'is_active' => 'nullable|boolean',
            'purchased_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after_or_equal:purchased_at',
        ]);

        $userEntryPack->update([
            'entry_pack_id' => (int) $data['entry_pack_id'],
            'is_active' => $request->boolean('is_active'),
            'purchased_at' => $data['purchased_at'] ?? $userEntryPack->purchased_at,
            'expires_at' => $data['expires_at'] ?? $userEntryPack->expires_at,
        ]);

        if ($request->boolean('is_active')) {
            UserEntryPack::query()
                ->where('user_id', $userEntryPack->user_id)
                ->whereKeyNot($userEntryPack->id)
                ->update(['is_active' => false]);
        }

        $this->audits->log('entry_packs', 'user_entry_pack_updated', $request->user(), $userEntryPack->user, $userEntryPack, $before, $userEntryPack->fresh(['user', 'entryPack'])->toArray(), $request->input('reason'));

        return redirect()->route('admin.entry-packs.reports')->with('ok', 'User entry pack updated.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'price_coins' => 'required|integer|min:0',
            'svg_url' => 'nullable|string|max:2048',
            'animation_style' => 'required|in:banner,center,fullscreen',
            'priority' => 'nullable|integer|min:1|max:9999',
            'duration_ms' => 'nullable|integer|min:2000|max:4000',
            'duration_days' => 'nullable|integer|min:1|max:3650',
            'sort_order' => 'nullable|integer|min:0|max:100000',
            'is_active' => 'nullable|boolean',
        ]);

        $data['priority'] = (int) ($data['priority'] ?? 1);
        $data['duration_ms'] = (int) ($data['duration_ms'] ?? 3000);
        $data['duration_days'] = (int) ($data['duration_days'] ?? 30);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
