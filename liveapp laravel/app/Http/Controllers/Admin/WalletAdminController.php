<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentOrder;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\AdminAuditService;
use App\Services\BillingReconciliationService;
use App\Services\UserLevelService;
use App\Services\WalletService;
use Illuminate\Http\Request;

class WalletAdminController extends Controller
{
    public function __construct(
        private BillingReconciliationService $reconciliationService,
        private UserLevelService $levels,
        private AdminAuditService $audits,
    )
    {
    }

    public function index(Request $request)
    {
        $q = User::query()->with('wallet');
        if ($s = $request->get('q', $request->get('s'))) {
            $q->where(function ($query) use ($s) {
                $query->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%");
            });
        }
        if ($request->filled('has_balance')) {
            $request->boolean('has_balance')
                ? $q->whereHas('wallet', fn ($wallet) => $wallet->where('balance', '>', 0))
                : $q->where(function ($query) {
                    $query->whereDoesntHave('wallet')->orWhereHas('wallet', fn ($wallet) => $wallet->where('balance', 0));
                });
        }
        if ($request->filled('blocked')) {
            $q->where('is_blocked', $request->boolean('blocked'));
        }
        $users = $q->orderBy('id','desc')->paginate(20);
        $coinSupply = Wallet::query()->sum('balance');
        $reconciliation = $this->reconciliationService->anomalies();
        $walletSummary = [
            'total_credits' => (int) WalletTransaction::query()->where('type', 'credit')->sum('coins'),
            'total_debits' => (int) WalletTransaction::query()->where('type', 'debit')->sum('coins'),
            'positive_wallets' => Wallet::query()->where('balance', '>', 0)->count(),
            'top_spenders' => WalletTransaction::query()->where('type', 'debit')->distinct('wallet_id')->count('wallet_id'),
            'recharge_conversion' => PaymentOrder::query()->count() > 0
                ? round((PaymentOrder::query()->where('status', 'success')->count() / max(1, PaymentOrder::query()->count())) * 100, 1)
                : 0,
        ];

        return view('admin.wallets.index', compact('users', 'coinSupply', 'reconciliation', 'walletSummary'));
    }

    public function show(Request $request, User $user)
    {
        $user->loadMissing(['level', 'levelHistories.oldLevel', 'levelHistories.newLevel']);
        $wallet = WalletService::getOrCreate($user);
        $transactions = $this->reconciliationService->walletTransactionsQuery($request)
            ->where('wallet_id', $wallet->id)
            ->paginate(25)
            ->withQueryString();
        $reconciliation = $this->reconciliationService->anomalies();
        $levelProgress = $this->levels->profileProgress($user);
        $levelHistory = $user->levelHistories()->with(['oldLevel', 'newLevel'])->limit(20)->get();

        return view('admin.wallets.show', compact('user','wallet', 'transactions', 'reconciliation', 'levelProgress', 'levelHistory'));
    }

    public function credit(Request $request, User $user)
    {
        $data = $request->validate([
            'amount' => 'required|integer|min:1',
            'reference' => 'nullable|string|max:120',
            'note' => 'nullable|string|max:500',
        ]);
        $before = ['balance' => (int) (WalletService::getOrCreate($user)->balance ?? 0)];
        WalletService::credit($user, (int)$data['amount'], $data['reference'] ?: 'admin_adjust', ['note'=>$data['note'] ?? null]);
        $after = ['balance' => (int) (WalletService::getOrCreate($user)->fresh()->balance ?? 0)];
        $this->audits->log('wallets', 'wallet_credit', $request->user(), $user, $user->wallet, $before, $after, $data['note'] ?? null, [
            'amount' => (int) $data['amount'],
            'reference' => $data['reference'] ?? null,
        ]);
        return back()->with('ok','Credited successfully.');
    }

    public function debit(Request $request, User $user)
    {
        $data = $request->validate([
            'amount' => 'required|integer|min:1',
            'reference' => 'nullable|string|max:120',
            'note' => 'nullable|string|max:500',
        ]);
        $before = ['balance' => (int) (WalletService::getOrCreate($user)->balance ?? 0)];
        WalletService::debit($user, (int)$data['amount'], $data['reference'] ?: 'admin_adjust', ['note'=>$data['note'] ?? null]);
        $after = ['balance' => (int) (WalletService::getOrCreate($user)->fresh()->balance ?? 0)];
        $this->audits->log('wallets', 'wallet_debit', $request->user(), $user, $user->wallet, $before, $after, $data['note'] ?? null, [
            'amount' => (int) $data['amount'],
            'reference' => $data['reference'] ?? null,
        ]);
        return back()->with('ok','Debited successfully.');
    }
public function purchase(Request $request, \App\Models\User $user)
{
    $data = $request->validate([
        'coins'          => 'required|integer|min:1',
        'amount'         => 'required|numeric|min:0.01', // MONEY
        'currency'       => 'nullable|string|size:3',
        'transaction_id' => 'nullable|string|max:100',
        'gateway'        => 'nullable|string|max:50',
        'reference'      => 'nullable|string|max:120',
        'note'           => 'nullable|string|max:500',
    ]);

    $before = ['balance' => (int) (WalletService::getOrCreate($user)->balance ?? 0)];
    \App\Services\WalletService::purchase(
        $user,
        (int) $data['coins'],
        (float) $data['amount'],
        strtoupper($data['currency'] ?? 'INR'),
        $data['transaction_id'] ?? null,
        $data['gateway'] ?? null,
        $data['reference'] ?: 'purchase',
        ['note' => $data['note'] ?? null]
    );
    $after = ['balance' => (int) (WalletService::getOrCreate($user)->fresh()->balance ?? 0)];
    $this->audits->log('wallets', 'wallet_purchase_recorded', $request->user(), $user, $user->wallet, $before, $after, $data['note'] ?? null, [
        'coins' => (int) $data['coins'],
        'amount' => (float) $data['amount'],
        'currency' => strtoupper($data['currency'] ?? 'INR'),
        'gateway' => $data['gateway'] ?? null,
        'transaction_id' => $data['transaction_id'] ?? null,
        'reference' => $data['reference'] ?? null,
    ]);

    return back()->with('ok','Purchase recorded and coins credited.');
}

public function spend(Request $request, \App\Models\User $user)
{
    $data = $request->validate([
        'coins'                => 'required|integer|min:1',
        'category'             => 'required|string|in:gift,audio_call,video_call,other',
        'counterparty_user_id' => 'nullable|exists:users,id',
        'reference'            => 'nullable|string|max:120',
        'note'                 => 'nullable|string|max:500',
    ]);

    $counterparty = $data['counterparty_user_id']
        ? \App\Models\User::find($data['counterparty_user_id'])
        : null;

    $before = ['balance' => (int) (WalletService::getOrCreate($user)->balance ?? 0)];
    \App\Services\WalletService::spend(
        $user,
        (int) $data['coins'],
        $data['category'],
        $counterparty,
        $data['reference'] ?? null,
        ['note' => $data['note'] ?? null]
    );
    $after = ['balance' => (int) (WalletService::getOrCreate($user)->fresh()->balance ?? 0)];
    $this->audits->log('wallets', 'wallet_spend_recorded', $request->user(), $user, $user->wallet, $before, $after, $data['note'] ?? null, [
        'coins' => (int) $data['coins'],
        'category' => $data['category'],
        'counterparty_user_id' => $data['counterparty_user_id'] ?? null,
        'reference' => $data['reference'] ?? null,
    ]);

    return back()->with('ok','Spend recorded and coins debited.');
}

}
