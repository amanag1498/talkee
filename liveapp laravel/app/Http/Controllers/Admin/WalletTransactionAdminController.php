<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgencyCoinTransfer;
use App\Models\CallSession;
use App\Models\GreedyBet;
use App\Models\GreedyPayout;
use App\Models\LevelSpendEvent;
use App\Models\LiveRoomGift;
use App\Models\LiveRoomPkEvent;
use App\Models\PaymentOrder;
use App\Models\TeenPattiBet;
use App\Models\TeenPattiPayout;
use App\Models\UserEntryPack;
use App\Models\UserSubscription;
use App\Models\WalletTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WalletTransactionAdminController extends Controller
{
    public function index(Request $request)
    {
        $query = $this->filteredQuery($request);
        $summaryQuery = clone $query;

        $summary = (clone $summaryQuery)
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'credit' THEN coins ELSE 0 END), 0) as credit_coins")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'debit' THEN coins ELSE 0 END), 0) as debit_coins")
            ->selectRaw('COUNT(DISTINCT wallet_id) as wallet_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN balance_before IS NULL OR balance_after IS NULL THEN 1 WHEN balance_after != CASE WHEN type = ? THEN balance_before + coins ELSE balance_before - coins END THEN 1 ELSE 0 END), 0) as anomaly_count', ['credit'])
            ->first();

        $moneyTotals = (clone $summaryQuery)
            ->whereNotNull('amount')
            ->where('amount', '>', 0)
            ->selectRaw("COALESCE(NULLIF(currency, ''), 'UNSPECIFIED') as currency_code, SUM(amount) as amount_total, COUNT(*) as transaction_count")
            ->groupBy('currency_code')
            ->orderBy('currency_code')
            ->get();

        $transactions = $query
            ->with(['wallet.user', 'counterparty'])
            ->latest('created_at')
            ->latest('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $options = [
            'categories' => WalletTransaction::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'gateways' => WalletTransaction::query()->whereNotNull('gateway')->where('gateway', '!=', '')->distinct()->orderBy('gateway')->pluck('gateway'),
            'reference_types' => WalletTransaction::query()->whereNotNull('reference_type')->where('reference_type', '!=', '')->distinct()->orderBy('reference_type')->pluck('reference_type'),
        ];

        return view('admin.wallet-transactions.index', compact('transactions', 'summary', 'moneyTotals', 'options'));
    }

    public function show(WalletTransaction $walletTransaction)
    {
        $walletTransaction->load(['wallet.user', 'counterparty']);

        return view('admin.wallet-transactions.show', [
            'transaction' => $walletTransaction,
            'integrity' => $this->integrity($walletTransaction),
            'relatedRecords' => $this->relatedRecords($walletTransaction),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $fileName = 'wallet-transaction-ledger-'.now()->format('Ymd-His').'.csv';
        $query = $this->filteredQuery($request)->with(['wallet.user', 'counterparty']);

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, [
                'ID', 'Created At', 'Wallet ID', 'User ID', 'User Name', 'User Email',
                'Type', 'Category', 'Coins', 'Amount', 'Currency', 'Balance Before',
                'Balance After', 'Balance Integrity', 'Counterparty User ID',
                'Counterparty Name', 'Reference', 'Reference Type', 'Reference ID',
                'Gateway', 'Gateway Transaction ID', 'Description', 'Metadata',
            ]);

            $query->orderBy('id')->chunkById(1000, function ($transactions) use ($output): void {
                foreach ($transactions as $transaction) {
                    $user = $transaction->wallet?->user;
                    fputcsv($output, [
                        $transaction->id,
                        $transaction->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                        $transaction->wallet_id,
                        $user?->id,
                        $user?->name,
                        $user?->email,
                        $transaction->type,
                        $transaction->category,
                        $transaction->coins,
                        $transaction->amount,
                        $transaction->currency,
                        $transaction->balance_before,
                        $transaction->balance_after,
                        $this->integrity($transaction)['status'],
                        $transaction->counterparty_user_id,
                        $transaction->counterparty?->name,
                        $transaction->reference,
                        $transaction->reference_type,
                        $transaction->reference_id,
                        $transaction->gateway,
                        $transaction->transaction_id,
                        $transaction->description,
                        json_encode($transaction->meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ]);
                }
            });

            fclose($output);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filteredQuery(Request $request): Builder
    {
        $query = WalletTransaction::query();

        if ($search = trim((string) $request->input('q'))) {
            $query->where(function (Builder $nested) use ($search): void {
                $nested
                    ->where('reference', 'like', "%{$search}%")
                    ->orWhere('transaction_id', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('wallet.user', fn (Builder $user) => $user
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));

                if (ctype_digit($search)) {
                    $nested->orWhere('id', (int) $search)
                        ->orWhere('wallet_id', (int) $search)
                        ->orWhere('reference_id', (int) $search);
                }
            });
        }

        if (in_array($request->input('type'), ['credit', 'debit'], true)) {
            $query->where('type', $request->input('type'));
        }

        foreach (['category', 'gateway', 'reference_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        foreach (['wallet_id', 'counterparty_user_id'] as $filter) {
            if ($request->filled($filter) && ctype_digit((string) $request->input($filter))) {
                $query->where($filter, (int) $request->input($filter));
            }
        }

        if ($request->filled('user_id') && ctype_digit((string) $request->input('user_id'))) {
            $userId = (int) $request->input('user_id');
            $query->whereHas('wallet', fn (Builder $wallet) => $wallet->where('user_id', $userId));
        }

        if ($request->filled('min_coins') && is_numeric($request->input('min_coins'))) {
            $query->where('coins', '>=', max(0, (int) $request->input('min_coins')));
        }

        if ($request->filled('max_coins') && is_numeric($request->input('max_coins'))) {
            $query->where('coins', '<=', max(0, (int) $request->input('max_coins')));
        }

        $timezone = config('app.timezone', 'UTC');
        if ($from = $this->date($request->input('from'), $timezone, false)) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $this->date($request->input('to'), $timezone, true)) {
            $query->where('created_at', '<=', $to);
        }

        $integrity = $request->input('integrity');
        if ($integrity === 'balanced') {
            $query->whereNotNull('balance_before')
                ->whereNotNull('balance_after')
                ->whereRaw("balance_after = CASE WHEN type = 'credit' THEN balance_before + coins ELSE balance_before - coins END");
        } elseif ($integrity === 'mismatch') {
            $query->whereNotNull('balance_before')
                ->whereNotNull('balance_after')
                ->whereRaw("balance_after != CASE WHEN type = 'credit' THEN balance_before + coins ELSE balance_before - coins END");
        } elseif ($integrity === 'missing') {
            $query->where(fn (Builder $nested) => $nested->whereNull('balance_before')->orWhereNull('balance_after'));
        }

        return $query;
    }

    private function date(mixed $value, string $timezone, bool $endOfDay): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);

            return $endOfDay ? $date->endOfDay() : $date->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->input('per_page', 25);

        return in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
    }

    private function integrity(WalletTransaction $transaction): array
    {
        if ($transaction->balance_before === null || $transaction->balance_after === null) {
            return ['status' => 'missing', 'expected' => null, 'difference' => null];
        }

        $expected = $transaction->type === 'credit'
            ? (int) $transaction->balance_before + (int) $transaction->coins
            : (int) $transaction->balance_before - (int) $transaction->coins;

        return [
            'status' => $expected === (int) $transaction->balance_after ? 'balanced' : 'mismatch',
            'expected' => $expected,
            'difference' => (int) $transaction->balance_after - $expected,
        ];
    }

    private function relatedRecords(WalletTransaction $transaction): array
    {
        $records = [];
        $meta = $transaction->meta ?? [];

        if ($transaction->reference_type === 'payment_order' && $transaction->reference_id) {
            if ($order = PaymentOrder::query()->with('rechargePlan')->find($transaction->reference_id)) {
                $records[] = [
                    'title' => 'Recharge order',
                    'fields' => [
                        'Order' => $order->order_id,
                        'Status' => $order->status,
                        'Plan' => $order->rechargePlan?->title,
                        'Gateway payment' => $order->gateway_payment_id,
                        'Amount' => $order->amount_rupees.' INR',
                    ],
                ];
            }
        }

        if (! empty($meta['call_session_id']) && $call = CallSession::query()->find($meta['call_session_id'])) {
            $records[] = [
                'title' => 'Video call session',
                'fields' => [
                    'Session ID' => $call->id,
                    'Status' => $call->status,
                    'Billable minutes' => $call->billable_minutes,
                    'Coins charged' => $call->total_coins_charged,
                    'End reason' => $call->end_reason,
                ],
            ];
        }

        if ($gift = LiveRoomGift::query()->with('gift')->where('transaction_id', (string) $transaction->id)->first()) {
            $records[] = [
                'title' => 'Live room gift',
                'fields' => [
                    'Gift event ID' => $gift->id,
                    'Room ID' => $gift->live_room_id,
                    'Gift' => $gift->gift?->name,
                    'Quantity' => $gift->quantity,
                    'Total coins' => $gift->total_coins,
                ],
            ];
        }

        if ($pkEvent = LiveRoomPkEvent::query()->where('wallet_transaction_id', $transaction->id)->first()) {
            $records[] = [
                'title' => 'PK battle event',
                'fields' => [
                    'Event ID' => $pkEvent->id,
                    'Battle ID' => $pkEvent->pk_battle_id,
                    'Room ID' => $pkEvent->room_id,
                    'Event type' => $pkEvent->event_type,
                    'Coins' => $pkEvent->coins,
                ],
            ];
        }

        $gameRecords = [
            [TeenPattiBet::class, 'Teen Patti bet', 'teen_patti_round_id'],
            [TeenPattiPayout::class, 'Teen Patti payout', 'teen_patti_bet_id'],
            [GreedyBet::class, 'Greedy bet', 'greedy_round_id'],
            [GreedyPayout::class, 'Greedy payout', 'greedy_bet_id'],
        ];
        foreach ($gameRecords as [$model, $title, $parentKey]) {
            if ($record = $model::query()->where('wallet_transaction_id', $transaction->id)->first()) {
                $records[] = [
                    'title' => $title,
                    'fields' => [
                        'Record ID' => $record->id,
                        str($parentKey)->replace('_id', '')->replace('_', ' ')->title()->toString() => $record->{$parentKey},
                        'Status' => $record->status,
                        'Amount' => $record->amount ?? $record->coins ?? $record->payout_coins,
                    ],
                ];
            }
        }

        if ($transfer = AgencyCoinTransfer::query()->where('user_wallet_transaction_id', $transaction->id)->first()) {
            $records[] = [
                'title' => 'Agency coin transfer',
                'fields' => [
                    'Transfer ID' => $transfer->id,
                    'Agency ID' => $transfer->agency_id,
                    'User ID' => $transfer->target_user_id,
                    'Coins' => $transfer->coins,
                    'Note' => $transfer->note,
                ],
            ];
        }

        if ($spend = LevelSpendEvent::query()->where('wallet_transaction_id', $transaction->id)->first()) {
            $records[] = [
                'title' => 'Level spend event',
                'fields' => [
                    'Spend event ID' => $spend->id,
                    'User ID' => $spend->user_id,
                    'Coins' => $spend->spend_coins,
                ],
            ];
        }

        if (! empty($meta['subscription_id']) && $subscription = UserSubscription::query()->with('plan')->find($meta['subscription_id'])) {
            $records[] = [
                'title' => 'User subscription',
                'fields' => [
                    'Subscription ID' => $subscription->id,
                    'Plan' => $subscription->plan?->name,
                    'Status' => $subscription->status,
                    'Starts' => $subscription->starts_at,
                    'Ends' => $subscription->ends_at,
                ],
            ];
        }

        $entryPackPurchaseId = $meta['user_entry_pack_id'] ?? $meta['purchase_id'] ?? null;
        if ($transaction->category === 'other' && $entryPackPurchaseId && $entryPack = UserEntryPack::query()->with('entryPack')->find($entryPackPurchaseId)) {
            $records[] = [
                'title' => 'Entry pack purchase',
                'fields' => [
                    'Purchase ID' => $entryPack->id,
                    'Entry pack' => $entryPack->entryPack?->name,
                    'Active' => $entryPack->is_active ? 'Yes' : 'No',
                    'Purchased' => $entryPack->purchased_at,
                    'Expires' => $entryPack->expires_at,
                ],
            ];
        }

        return $records;
    }
}
