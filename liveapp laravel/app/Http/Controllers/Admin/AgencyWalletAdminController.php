<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\User;
use App\Services\AgencyWalletService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class AgencyWalletAdminController extends Controller
{
    public function __construct(private AgencyWalletService $wallets)
    {
    }

    public function show(Agency $agency)
    {
        $agency->load('owner');

        return view('agency.wallets.dashboard', [
            'agency' => $agency,
            'walletSummary' => $this->wallets->summary($agency),
            'walletTransactions' => $this->wallets->paginatedTransactions($agency, 15, 'ledger_page'),
            'walletTransfers' => $this->wallets->paginatedTransfers($agency, 15, 'transfer_page'),
            'walletAudits' => $this->wallets->recentAudits($agency),
            'canLoadWallet' => true,
            'canCreditUsers' => true,
            'walletRoute' => route('admin.agencies.wallet.show', $agency),
        ]);
    }

    public function load(Request $request, Agency $agency)
    {
        $data = $request->validate([
            'coins' => ['required', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->wallets->adminLoad(
                $agency,
                (int) $data['coins'],
                $request->user(),
                $data['note'] ?? null,
                $data['reference'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('err', $e->getMessage());
        }

        return redirect()
            ->route('admin.agencies.wallet.show', $agency)
            ->with('ok', 'Agency wallet loaded successfully.');
    }

    public function creditUser(Request $request, Agency $agency)
    {
        $data = $request->validate([
            'target_user_id' => ['required', 'integer', 'exists:users,id'],
            'coins' => ['required', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $targetUser = User::query()->findOrFail((int) $data['target_user_id']);

        try {
            $this->wallets->transferToUser(
                $agency,
                $targetUser,
                (int) $data['coins'],
                $request->user(),
                null,
                $data['note'] ?? null,
                $data['reference'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('err', $e->getMessage());
        }

        return redirect()
            ->route('admin.agencies.wallet.show', $agency)
            ->with('ok', 'Agency wallet credited the user successfully.');
    }

    public function report(Request $request)
    {
        $filters = $request->validate([
            'agency_id' => ['nullable', 'integer', 'exists:agencies,id'],
            'direction' => ['nullable', 'string', 'in:admin_to_agency,agency_to_user'],
            'target_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = $this->wallets->reportQuery($filters);

        $summary = [
            'total_rows' => (clone $query)->count(),
            'total_loaded' => (int) (clone $query)->where('direction', 'admin_to_agency')->sum('coins'),
            'total_distributed' => (int) (clone $query)->where('direction', 'agency_to_user')->sum('coins'),
        ];

        return view('admin.reports.agency-wallets.index', [
            'filters' => $filters,
            'agencies' => Agency::query()->orderBy('name')->get(['id', 'name']),
            'summary' => $summary,
            'transfers' => $query->latest('id')->paginate(20)->withQueryString(),
        ]);
    }
}
