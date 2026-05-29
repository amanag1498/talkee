<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\User;
use App\Services\AgencyWalletService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class WalletController extends Controller
{
    public function __construct(private AgencyWalletService $wallets)
    {
    }

    public function show(Request $request)
    {
        $agency = Agency::query()->where('owner_user_id', $request->user()->id)->firstOrFail();

        return view('agency.wallets.dashboard', [
            'agency' => $agency,
            'walletSummary' => $this->wallets->summary($agency),
            'walletTransactions' => $this->wallets->paginatedTransactions($agency, 15, 'ledger_page'),
            'walletTransfers' => $this->wallets->paginatedTransfers($agency, 15, 'transfer_page'),
            'walletAudits' => collect(),
            'canLoadWallet' => false,
            'canCreditUsers' => true,
            'walletRoute' => route('agency.wallet.show'),
        ]);
    }

    public function creditUser(Request $request)
    {
        $agency = Agency::query()->where('owner_user_id', $request->user()->id)->firstOrFail();
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
                null,
                $request->user(),
                $data['note'] ?? null,
                $data['reference'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('err', $e->getMessage());
        }

        return redirect()
            ->route('agency.wallet.show')
            ->with('ok', 'User credited from agency wallet successfully.');
    }
}
