<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class WalletTransaction extends Model
{
    protected $fillable = [
        'wallet_id',
        'type',                // credit/debit
        'coins',               // +/- coins
        'amount',              // MONEY paid (decimal) - usually on purchases
        'currency',            // 'INR', 'USD'...
        'category',            // purchase/gift/audio_call/video_call/adjustment/...
        'reference',
        'transaction_id',      // gateway txn id
        'gateway',             // razorpay/stripe/...
        'counterparty_user_id',
        'meta',
        'reference_type',
        'reference_id',
        'description',
        'balance_before',
        'balance_after',
    ];

    protected $casts = [
        'meta'    => 'array',
        'amount'  => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::created(function (WalletTransaction $transaction) {
            $callback = static function () use ($transaction): void {
                app(\App\Services\UserLevelService::class)->processWalletTransaction($transaction);
            };

            if (DB::transactionLevel() > 0) {
                DB::afterCommit($callback);
                return;
            }

            $callback();
        });
    }

    public function wallet(): BelongsTo { return $this->belongsTo(Wallet::class); }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counterparty_user_id');
    }
}
