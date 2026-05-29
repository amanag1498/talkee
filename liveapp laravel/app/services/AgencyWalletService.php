<?php

namespace App\Services;

use App\Models\AdminActionAudit;
use App\Models\Agency;
use App\Models\AgencyCoinTransfer;
use App\Models\AgencyWallet;
use App\Models\AgencyWalletTransaction;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AgencyWalletService
{
    public function __construct(private AdminAuditService $audits)
    {
    }

    public function getOrCreate(Agency $agency): AgencyWallet
    {
        return $agency->wallet()->firstOrCreate([], ['balance' => 0]);
    }

    public function adminLoad(
        Agency $agency,
        int $coins,
        User $admin,
        ?string $note = null,
        ?string $reference = null,
    ): AgencyCoinTransfer {
        if ($coins <= 0) {
            throw new InvalidArgumentException('Coins must be positive.');
        }

        return DB::transaction(function () use ($agency, $coins, $admin, $note, $reference) {
            $wallet = $this->lockWallet($agency);
            $before = (int) $wallet->balance;
            $after = $before + $coins;

            $wallet->update(['balance' => $after]);

            $transaction = $wallet->transactions()->create([
                'type' => 'credit',
                'coins' => $coins,
                'category' => 'admin_load',
                'reference' => $reference,
                'description' => 'Admin loaded agency wallet',
                'balance_before' => $before,
                'balance_after' => $after,
                'created_by_admin_user_id' => $admin->id,
                'meta' => [
                    'agency_id' => $agency->id,
                    'agency_name' => $agency->name,
                    'note' => $note,
                ],
            ]);

            $transfer = $wallet->transfers()->create([
                'agency_id' => $agency->id,
                'agency_wallet_transaction_id' => $transaction->id,
                'admin_user_id' => $admin->id,
                'direction' => 'admin_to_agency',
                'coins' => $coins,
                'note' => $note,
                'meta' => [
                    'reference' => $reference,
                ],
            ]);

            $this->audits->log(
                'agency_wallets',
                'agency_wallet_load',
                $admin,
                $agency->owner,
                $transaction,
                ['balance' => $before],
                ['balance' => $after],
                $note,
                [
                    'agency_id' => $agency->id,
                    'agency_name' => $agency->name,
                    'coins' => $coins,
                    'transfer_id' => $transfer->id,
                ],
            );

            return $transfer->load(['agency', 'agencyWalletTransaction', 'admin']);
        });
    }

    public function transferToUser(
        Agency $agency,
        User $targetUser,
        int $coins,
        ?User $admin = null,
        ?User $agencyUser = null,
        ?string $note = null,
        ?string $reference = null,
    ): AgencyCoinTransfer {
        if ($coins <= 0) {
            throw new InvalidArgumentException('Coins must be positive.');
        }

        if (!$admin && !$agencyUser) {
            throw new InvalidArgumentException('An actor is required.');
        }

        if ($targetUser->is_blocked) {
            throw new InvalidArgumentException('Blocked users cannot receive agency wallet credits.');
        }

        return DB::transaction(function () use ($agency, $targetUser, $coins, $admin, $agencyUser, $note, $reference) {
            $wallet = $this->lockWallet($agency);

            if ((int) $wallet->balance < $coins) {
                throw new InvalidArgumentException('Insufficient agency wallet balance.');
            }

            $before = (int) $wallet->balance;
            $after = $before - $coins;

            $wallet->update(['balance' => $after]);

            $agencyTransaction = $wallet->transactions()->create([
                'type' => 'debit',
                'coins' => $coins,
                'category' => 'agency_credit_to_user',
                'reference' => $reference,
                'description' => 'Agency wallet credited a user',
                'balance_before' => $before,
                'balance_after' => $after,
                'target_user_id' => $targetUser->id,
                'created_by_admin_user_id' => $admin?->id,
                'created_by_agency_user_id' => $agencyUser?->id,
                'meta' => [
                    'agency_id' => $agency->id,
                    'agency_name' => $agency->name,
                    'note' => $note,
                ],
            ]);

            $userTransaction = WalletService::credit(
                $targetUser,
                $coins,
                $reference ?: 'AGENCY_WALLET_TRANSFER',
                [
                    'agency_id' => $agency->id,
                    'agency_name' => $agency->name,
                    'agency_wallet_transaction_id' => $agencyTransaction->id,
                    'credited_by_admin_user_id' => $admin?->id,
                    'credited_by_admin_name' => $admin?->name,
                    'credited_by_agency_user_id' => $agencyUser?->id,
                    'credited_by_agency_user_name' => $agencyUser?->name,
                    'note' => $note,
                ],
                [
                    'category' => 'agency_credit',
                    'reference_type' => AgencyWalletTransaction::class,
                    'reference_id' => $agencyTransaction->id,
                    'counterparty_user_id' => $agencyUser?->id ?: $agency->owner_user_id,
                ],
                'Agency wallet credit',
            );

            $transfer = $wallet->transfers()->create([
                'agency_id' => $agency->id,
                'agency_wallet_transaction_id' => $agencyTransaction->id,
                'user_wallet_transaction_id' => $userTransaction->id,
                'target_user_id' => $targetUser->id,
                'admin_user_id' => $admin?->id,
                'agency_user_id' => $agencyUser?->id,
                'direction' => 'agency_to_user',
                'coins' => $coins,
                'note' => $note,
                'meta' => [
                    'reference' => $reference,
                ],
            ]);

            $auditAction = $admin ? 'agency_wallet_user_credit' : 'agency_wallet_self_service_credit';

            $this->audits->log(
                'agency_wallets',
                $auditAction,
                $admin,
                $targetUser,
                $agencyTransaction,
                ['balance' => $before],
                ['balance' => $after],
                $note,
                [
                    'agency_id' => $agency->id,
                    'agency_name' => $agency->name,
                    'coins' => $coins,
                    'transfer_id' => $transfer->id,
                    'agency_wallet_transaction_id' => $agencyTransaction->id,
                    'user_wallet_transaction_id' => $userTransaction->id,
                    'agency_user_id' => $agencyUser?->id,
                ],
            );

            return $transfer->load([
                'agency',
                'agencyWalletTransaction',
                'userWalletTransaction',
                'targetUser',
                'admin',
                'agencyUser',
            ]);
        });
    }

    public function summary(Agency $agency): array
    {
        $wallet = $this->getOrCreate($agency);
        $transactionBase = AgencyWalletTransaction::query()
            ->where('agency_wallet_id', $wallet->id);
        $transferBase = AgencyCoinTransfer::query()
            ->where('agency_wallet_id', $wallet->id);

        return [
            'balance' => (int) $wallet->balance,
            'total_loaded' => (int) (clone $transactionBase)->where('category', 'admin_load')->sum('coins'),
            'total_distributed' => (int) (clone $transferBase)->where('direction', 'agency_to_user')->sum('coins'),
            'credits_issued' => (int) (clone $transferBase)->where('direction', 'agency_to_user')->count(),
            'loads_recorded' => (int) (clone $transferBase)->where('direction', 'admin_to_agency')->count(),
        ];
    }

    public function paginatedTransactions(Agency $agency, int $perPage = 15, string $pageName = 'ledger_page'): LengthAwarePaginator
    {
        $wallet = $this->getOrCreate($agency);

        return AgencyWalletTransaction::query()
            ->with(['targetUser', 'admin', 'agencyUser'])
            ->where('agency_wallet_id', $wallet->id)
            ->latest('id')
            ->paginate($perPage, ['*'], $pageName);
    }

    public function paginatedTransfers(Agency $agency, int $perPage = 15, string $pageName = 'transfer_page'): LengthAwarePaginator
    {
        $wallet = $this->getOrCreate($agency);

        return AgencyCoinTransfer::query()
            ->with(['targetUser', 'admin', 'agencyUser', 'agencyWalletTransaction', 'userWalletTransaction'])
            ->where('agency_wallet_id', $wallet->id)
            ->latest('id')
            ->paginate($perPage, ['*'], $pageName);
    }

    public function recentAudits(Agency $agency, int $limit = 10)
    {
        return AdminActionAudit::query()
            ->with(['admin', 'targetUser'])
            ->where('area', 'agency_wallets')
            ->where('meta->agency_id', $agency->id)
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function reportQuery(array $filters = []): Builder
    {
        return AgencyCoinTransfer::query()
            ->with(['agency.owner', 'targetUser', 'admin', 'agencyUser', 'agencyWalletTransaction'])
            ->when(!empty($filters['agency_id']), fn (Builder $query) => $query->where('agency_id', (int) $filters['agency_id']))
            ->when(!empty($filters['direction']), fn (Builder $query) => $query->where('direction', (string) $filters['direction']))
            ->when(!empty($filters['target_user_id']), fn (Builder $query) => $query->where('target_user_id', (int) $filters['target_user_id']))
            ->when(!empty($filters['from']), fn (Builder $query) => $query->whereDate('created_at', '>=', $filters['from']))
            ->when(!empty($filters['to']), fn (Builder $query) => $query->whereDate('created_at', '<=', $filters['to']));
    }

    private function lockWallet(Agency $agency): AgencyWallet
    {
        $this->getOrCreate($agency);

        return AgencyWallet::query()
            ->where('agency_id', $agency->id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
