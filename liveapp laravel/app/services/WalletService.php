<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WalletService
{
    public static function getOrCreate(User $user): Wallet
    {
        return $user->wallet()->firstOrCreate([]);
    }

    private static function lockWallet(User $user): Wallet
    {
        self::getOrCreate($user);

        return Wallet::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private static function createTransaction(
        Wallet $wallet,
        array $attributes,
        int $balanceBefore,
        int $balanceAfter,
        ?string $description = null
    ): WalletTransaction {
        return $wallet->transactions()->create(array_merge([
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'description' => $description,
        ], $attributes));
    }

    /** Admin/manual coin credit (no money involved) */
    public static function credit(
        User $user,
        int $coins,
        ?string $reference = null,
        array $meta = [],
        array $attributes = [],
        ?string $description = 'Admin credit',
    ): WalletTransaction
    {
        if ($coins <= 0) throw new InvalidArgumentException('Coins must be positive.');
        return DB::transaction(function () use ($user, $coins, $reference, $meta, $attributes, $description) {
            $wallet = self::lockWallet($user);
            $balanceBefore = (int) $wallet->balance;
            $balanceAfter = $balanceBefore + $coins;
            $wallet->update(['balance' => $balanceAfter]);

            return self::createTransaction($wallet, array_merge([
                'type'     => 'credit',
                'coins'    => $coins,
                'category' => 'adjustment',
                'reference'=> $reference,
                'meta'     => $meta,
            ], $attributes), $balanceBefore, $balanceAfter, $description);
        });
    }

    /** Admin/manual coin debit (no money involved) */
    public static function debit(User $user, int $coins, ?string $reference = null, array $meta = []): WalletTransaction
    {
        if ($coins <= 0) throw new InvalidArgumentException('Coins must be positive.');
        return DB::transaction(function () use ($user, $coins, $reference, $meta) {
            $wallet = self::lockWallet($user);
            if ($wallet->balance < $coins) throw new InvalidArgumentException('Insufficient balance.');
            $balanceBefore = (int) $wallet->balance;
            $balanceAfter = $balanceBefore - $coins;
            $wallet->update(['balance' => $balanceAfter]);

            return self::createTransaction($wallet, [
                'type'     => 'debit',
                'coins'    => $coins,
                'category' => 'adjustment',
                'reference'=> $reference,
                'meta'     => $meta,
            ], $balanceBefore, $balanceAfter, 'Admin debit');
        });
    }

    /** Purchase coins with money (coins credited, amount=money paid) */
    public static function purchase(
        User $user,
        int $coins,
        float $amount,
        string $currency = 'INR',
        ?string $transactionId = null,
        ?string $gateway = null,
        ?string $reference = 'purchase',
        array $meta = []
    ): WalletTransaction {
        if ($coins <= 0) throw new InvalidArgumentException('Coins must be positive.');
        if ($amount <= 0) throw new InvalidArgumentException('Money amount must be positive.');

        return DB::transaction(function () use ($user,$coins,$amount,$currency,$transactionId,$gateway,$reference,$meta) {
            $wallet = self::lockWallet($user);
            $balanceBefore = (int) $wallet->balance;
            $balanceAfter = $balanceBefore + $coins;
            $wallet->update(['balance' => $balanceAfter]);

            return self::createTransaction($wallet, [
                'type'           => 'credit',
                'coins'          => $coins,
                'amount'         => $amount,
                'currency'       => strtoupper($currency),
                'category'       => 'purchase',
                'transaction_id' => $transactionId,
                'gateway'        => $gateway,
                'reference'      => $reference,
                'meta'           => $meta,
            ], $balanceBefore, $balanceAfter, 'Wallet purchase');
        });
    }

    /** Credit coins for earnings (gift/agency/etc.) */
    public static function earn(
        User $user,
        int $coins,
        string $category,
        ?User $counterparty = null,
        ?string $reference = null,
        array $meta = []
    ): WalletTransaction {
        if ($coins <= 0) throw new InvalidArgumentException('Coins must be positive.');

        return DB::transaction(function () use ($user, $coins, $category, $counterparty, $reference, $meta) {
            $wallet = self::lockWallet($user);
            $balanceBefore = (int) $wallet->balance;
            $balanceAfter = $balanceBefore + $coins;
            $wallet->update(['balance' => $balanceAfter]);

            return self::createTransaction($wallet, [
                'type' => 'credit',
                'coins' => $coins,
                'category' => $category,
                'counterparty_user_id' => $counterparty?->id,
                'reference' => $reference,
                'meta' => $meta,
            ], $balanceBefore, $balanceAfter, ucfirst(str_replace('_', ' ', $category)).' credit');
        });
    }

    /** Spend coins (gift/audio_call/video_call/etc.) */
    public static function spend(
        User $user,
        int $coins,
        string $category,
        ?User $counterparty = null,
        ?string $reference = null,
        array $meta = []
    ): WalletTransaction {
        if ($coins <= 0) throw new InvalidArgumentException('Coins must be positive.');
        if (!in_array($category, ['gift','audio_call','video_call','other'])) {
            // Allow extension but keep a sane set
        }

        return DB::transaction(function () use ($user,$coins,$category,$counterparty,$reference,$meta) {
            $wallet = self::lockWallet($user);
            if ($wallet->balance < $coins) throw new InvalidArgumentException('Insufficient balance.');
            $balanceBefore = (int) $wallet->balance;
            $balanceAfter = $balanceBefore - $coins;
            $wallet->update(['balance' => $balanceAfter]);

            return self::createTransaction($wallet, [
                'type'                 => 'debit',
                'coins'                => $coins,
                'category'             => $category,
                'counterparty_user_id' => $counterparty?->id,
                'reference'            => $reference,
                'meta'                 => $meta,
            ], $balanceBefore, $balanceAfter, ucfirst(str_replace('_', ' ', $category)).' spend');
        });
    }
}
