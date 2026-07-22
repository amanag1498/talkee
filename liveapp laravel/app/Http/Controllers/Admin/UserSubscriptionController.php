<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserSubscriptionRequest;
use App\Http\Requests\Admin\UpdateUserSubscriptionRequest;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\WalletTransaction;
use App\Services\AdminAuditService;
use App\Services\SubscriptionService;
use App\Services\WalletService;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserSubscriptionController extends Controller
{
    public function __construct(private AdminAuditService $audits) {}

    public function index(Request $request)
    {
        $origin = $request->string('origin')->toString();
        $status = $request->string('status')->toString();

        $subs = UserSubscription::with(['user', 'plan'])
            ->when(in_array($status, ['active', 'expired', 'cancelled'], true), fn ($q) => $q->where('status', $status))
            ->origin($origin)
            ->orderByDesc('created_at')
            ->paginate(30, ['*'], 'entitlements_page')
            ->withQueryString();

        $activeEntitlements = UserSubscription::query()
            ->where('status', 'active')
            ->where(function ($builder) {
                $builder->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where('ends_at', '>', now());

        $summary = [
            'active' => (clone $activeEntitlements)->count(),
            'expired' => UserSubscription::query()
                ->where(function ($builder) {
                    $builder
                        ->where('status', 'expired')
                        ->orWhere(function ($activeButEnded) {
                            $activeButEnded
                                ->where('status', 'active')
                                ->whereNotNull('ends_at')
                                ->where('ends_at', '<=', now());
                        });
                })
                ->count(),
            'cancelled' => UserSubscription::query()->where('status', 'cancelled')->count(),
            'complimentary_active' => (clone $activeEntitlements)
                ->where(function ($builder) {
                    $builder
                        ->where('meta->charged', false)
                        ->orWhere(function ($legacy) {
                            $legacy
                                ->whereNull('meta->charged')
                                ->whereIn('meta->source', ['signup_gift', 'admin', 'admin_user_360', 'admin_grant']);
                        });
                })
                ->count(),
            'expiring_soon' => (clone $activeEntitlements)
                ->whereBetween('ends_at', [now(), now()->copy()->addDays(7)])
                ->count(),
        ];

        $salesQuery = $this->subscriptionSalesQuery($request);
        $soldCount = (clone $salesQuery)->count();
        $renewalCount = $this->applySaleKindFilter(clone $salesQuery, 'renewal')->count();
        $salesSummary = [
            'sold' => $soldCount,
            'coins' => (int) (clone $salesQuery)->sum('coins'),
            'buyers' => (clone $salesQuery)->distinct()->count('wallet_id'),
            'renewals' => $renewalCount,
            'renewal_rate' => $soldCount > 0 ? round(($renewalCount / $soldCount) * 100, 1) : 0,
        ];

        $sales = (clone $salesQuery)
            ->with(['wallet.user'])
            ->select('wallet_transactions.*')
            ->selectSub($this->priorSubscriptionSaleCountQuery(), 'prior_sale_count')
            ->orderByDesc('wallet_transactions.created_at')
            ->orderByDesc('wallet_transactions.id')
            ->paginate(30, ['*'], 'sales_page')
            ->withQueryString();

        $sales->getCollection()->each(function (WalletTransaction $transaction) {
            $transaction->setAttribute(
                'audit_kind',
                data_get($transaction->meta, 'purchase_kind')
                    ?: ((int) $transaction->getAttribute('prior_sale_count') > 0 ? 'renewal' : 'purchase')
            );
        });

        $plans = SubscriptionPlan::query()
            ->orderBy('name')
            ->get(['id', 'name', 'price_coins', 'duration_days']);
        $plansById = $plans->keyBy('id');
        $salesByPlan = (clone $salesQuery)
            ->selectRaw('reference, COUNT(*) as sold_count, COALESCE(SUM(coins), 0) as coins_total, COUNT(DISTINCT wallet_id) as buyer_count')
            ->groupBy('reference')
            ->orderByDesc('coins_total')
            ->get()
            ->map(function ($row) use ($plansById) {
                preg_match('/^SUB_PLAN:(\d+)$/', (string) $row->reference, $matches);
                $plan = isset($matches[1]) ? $plansById->get((int) $matches[1]) : null;

                return [
                    'name' => $plan?->name ?? ($row->reference ?: 'Unknown plan'),
                    'sold' => (int) $row->sold_count,
                    'coins' => (int) $row->coins_total,
                    'buyers' => (int) $row->buyer_count,
                ];
            });

        return view('admin.subscriptions.users.index', compact(
            'subs',
            'summary',
            'origin',
            'status',
            'sales',
            'salesSummary',
            'salesByPlan',
            'plans',
        ));
    }

    private function subscriptionSalesQuery(Request $request)
    {
        $query = WalletTransaction::query()
            ->where('wallet_transactions.category', 'subscription')
            ->where('wallet_transactions.type', 'debit');

        if ($search = $request->string('sale_q')->trim()->value()) {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('wallet_transactions.id', $search)
                    ->orWhere('wallet_transactions.reference', 'like', "%{$search}%")
                    ->orWhere('wallet_transactions.meta->plan_name', 'like', "%{$search}%")
                    ->orWhereHas('wallet.user', function ($userQuery) use ($search) {
                        $userQuery
                            ->where('users.id', $search)
                            ->orWhere('users.name', 'like', "%{$search}%")
                            ->orWhere('users.email', 'like', "%{$search}%");
                    });
            });
        }

        if ($planId = $request->integer('sale_plan_id')) {
            $query->where(function ($builder) use ($planId) {
                $builder
                    ->where('wallet_transactions.reference', "SUB_PLAN:{$planId}")
                    ->orWhere('wallet_transactions.meta->subscription_plan_id', $planId);
            });
        }

        if ($from = $request->string('sale_from')->trim()->value()) {
            $query->whereDate('wallet_transactions.created_at', '>=', $from);
        }

        if ($to = $request->string('sale_to')->trim()->value()) {
            $query->whereDate('wallet_transactions.created_at', '<=', $to);
        }

        if (in_array($request->string('sale_kind')->value(), ['purchase', 'renewal'], true)) {
            $this->applySaleKindFilter($query, $request->string('sale_kind')->value());
        }

        return $query;
    }

    private function applySaleKindFilter($query, string $kind)
    {
        return $query->where(function ($builder) use ($kind) {
            $builder->where('wallet_transactions.meta->purchase_kind', $kind);
            $builder->orWhere(function ($legacy) use ($kind) {
                $legacy->whereNull('wallet_transactions.meta->purchase_kind');
                $method = $kind === 'renewal' ? 'whereExists' : 'whereNotExists';
                $legacy->{$method}($this->priorSubscriptionSaleExistsQuery());
            });
        });
    }

    private function priorSubscriptionSaleExistsQuery()
    {
        return DB::table('wallet_transactions as prior_sales')
            ->selectRaw('1')
            ->whereColumn('prior_sales.wallet_id', 'wallet_transactions.wallet_id')
            ->whereColumn('prior_sales.reference', 'wallet_transactions.reference')
            ->where('prior_sales.category', 'subscription')
            ->where('prior_sales.type', 'debit')
            ->whereColumn('prior_sales.id', '<', 'wallet_transactions.id');
    }

    private function priorSubscriptionSaleCountQuery()
    {
        return DB::table('wallet_transactions as prior_sales')
            ->selectRaw('COUNT(*)')
            ->whereColumn('prior_sales.wallet_id', 'wallet_transactions.wallet_id')
            ->whereColumn('prior_sales.reference', 'wallet_transactions.reference')
            ->where('prior_sales.category', 'subscription')
            ->where('prior_sales.type', 'debit')
            ->whereColumn('prior_sales.id', '<', 'wallet_transactions.id');
    }

    public function create()
    {
        return view('admin.subscriptions.users.create', [
            'users' => User::orderBy('id', 'desc')->limit(200)->get(['id', 'name', 'email']),
            'plans' => SubscriptionPlan::where('is_active', true)->orderBy('price_coins')->get(),
        ]);
    }

    public function store(StoreUserSubscriptionRequest $req, SubscriptionService $svc)
    {
        $data = $req->validated();
        $user = User::findOrFail($data['user_id']);
        $plan = SubscriptionPlan::findOrFail($data['plan_id']);
        $charge = (bool) ($data['charge_coins'] ?? false);

        return DB::transaction(function () use ($user, $plan, $data, $charge, $req) {
            // optionally charge wallet
            $walletTx = null;
            if ($charge) {
                $walletTx = WalletService::spend(
                    user: $user,
                    coins: $plan->price_coins,
                    category: 'subscription',
                    counterparty: null,
                    reference: "SUB_PLAN:{$plan->id}",
                    meta: [
                        'plan_name' => $plan->name,
                        'subscription_plan_id' => $plan->id,
                        'event' => 'ADMIN_CREATE',
                        'purchase_kind' => 'purchase',
                        'source' => 'admin',
                    ]
                );
            }

            $now = now();
            $base = ! empty($data['starts_at']) ? \Carbon\Carbon::parse($data['starts_at']) : $now;
            $ends = ! empty($data['ends_at'])
                ? \Carbon\Carbon::parse($data['ends_at'])
                : $base->clone()->addDays($plan->duration_days);

            // compose meta
            $incomingMeta = (array) ($data['meta'] ?? $req->input('meta', []));
            $source = $charge ? 'admin_charged' : ($req->input('source_type') ?: 'admin_grant');
            $meta = array_merge($incomingMeta, [
                'source' => $source,
                'event' => $charge ? 'ADMIN_CREATE_CHARGED' : 'ADMIN_CREATE_GRANT',
                'charged' => $charge,
                'plan_name' => $plan->name,
                'price_coins' => (int) $plan->price_coins,
                'wallet_transaction_id' => $walletTx->id ?? null,
                'note' => $req->input('meta.note'),
                'created_at' => $now->toIso8601String(),
            ]);

            $subscription = UserSubscription::create([
                'user_id' => $user->id,
                'subscription_plan_id' => $plan->id,
                'status' => $data['status'],
                'starts_at' => $base,
                'ends_at' => $ends,
                'last_purchased_at' => $now,
                'meta' => $meta,
            ]);

            if ($walletTx) {
                $this->linkSaleTransaction($walletTx, $subscription, $plan, $base, $ends, 'purchase');
            }

            $this->audits->log('subscriptions', 'subscription_created', $req->user(), $user, $subscription, null, $subscription->fresh(['plan', 'user'])->toArray(), $req->input('reason'), [
                'charge_coins' => $charge,
            ]);

            return redirect()
                ->route('admin.user-subscriptions.index')
                ->with('success', 'Subscription created.');
        });
    }

    public function edit(UserSubscription $user_subscription)
    {
        return view('admin.subscriptions.users.edit', [
            'sub' => $user_subscription->load(['user', 'plan']),
            'plans' => SubscriptionPlan::orderBy('price_coins')->get(),
        ]);
    }

    public function update(UpdateUserSubscriptionRequest $req, UserSubscription $user_subscription)
    {
        $data = $req->validated();
        $plan = SubscriptionPlan::findOrFail($data['plan_id']);
        $charge = (bool) ($data['charge_coins'] ?? false);

        return DB::transaction(function () use ($user_subscription, $data, $plan, $charge, $req) {
            $before = $user_subscription->fresh(['plan', 'user'])->toArray();
            $walletTx = null;
            if ($charge) {
                $walletTx = WalletService::spend(
                    user: $user_subscription->user,
                    coins: $plan->price_coins,
                    category: 'subscription',
                    counterparty: null,
                    reference: "SUB_PLAN:{$plan->id}",
                    meta: [
                        'plan_name' => $plan->name,
                        'subscription_plan_id' => $plan->id,
                        'event' => 'ADMIN_UPDATE',
                        'purchase_kind' => 'renewal',
                        'source' => 'admin',
                    ]
                );
            }

            $starts = ! empty($data['starts_at']) ? \Carbon\Carbon::parse($data['starts_at']) : $user_subscription->starts_at;
            $ends = ! empty($data['ends_at']) ? \Carbon\Carbon::parse($data['ends_at']) : $user_subscription->ends_at;

            // merge meta (keep existing keys, update audit info)
            $existingMeta = (array) ($user_subscription->meta ?? []);
            $incomingMeta = (array) ($data['meta'] ?? $req->input('meta', []));
            $meta = array_merge($existingMeta, $incomingMeta, [
                'last_action' => $charge ? 'ADMIN_UPDATE_CHARGED' : 'ADMIN_UPDATE',
                'source' => $charge ? 'admin_charged' : ($incomingMeta['source'] ?? $existingMeta['source'] ?? 'admin_grant'),
                'charged' => $charge || filter_var($existingMeta['charged'] ?? false, FILTER_VALIDATE_BOOL),
                'plan_name' => $plan->name,
                'price_coins' => (int) $plan->price_coins,
                'wallet_transaction_id' => $charge ? ($walletTx->id ?? null) : ($existingMeta['wallet_transaction_id'] ?? null),
                'last_updated_at' => now()->toIso8601String(),
            ]);

            $user_subscription->update([
                'subscription_plan_id' => $plan->id,
                'status' => $data['status'],
                'starts_at' => $starts,
                'ends_at' => $ends,
                'meta' => $meta,
            ]);

            if ($walletTx) {
                $this->linkSaleTransaction($walletTx, $user_subscription, $plan, $starts, $ends, 'renewal');
            }

            $this->audits->log('subscriptions', 'subscription_updated', $req->user(), $user_subscription->user, $user_subscription, $before, $user_subscription->fresh(['plan', 'user'])->toArray(), $req->input('reason'), [
                'charge_coins' => $charge,
            ]);

            return redirect()
                ->route('admin.user-subscriptions.index')
                ->with('success', 'Subscription updated.');
        });
    }

    public function destroy(UserSubscription $user_subscription)
    {
        $before = $user_subscription->fresh(['plan', 'user'])->toArray();
        $targetUser = $user_subscription->user;
        $user_subscription->delete();
        $this->audits->log('subscriptions', 'subscription_deleted', request()->user(), $targetUser, $user_subscription, $before, null, request('reason'));

        return back()->with('success', 'Subscription deleted.');
    }

    public function cancel($id, SubscriptionService $svc)
    {
        $sub = UserSubscription::findOrFail($id);
        $before = $sub->fresh(['plan', 'user'])->toArray();
        $svc->cancelNow($sub);

        // append a meta audit entry on cancel
        $meta = (array) ($sub->meta ?? []);
        $meta['last_action'] = 'ADMIN_CANCEL';
        $meta['last_updated_at'] = now()->toIso8601String();
        $sub->update(['meta' => $meta]);
        $this->audits->log('subscriptions', 'subscription_cancelled', request()->user(), $sub->user, $sub, $before, $sub->fresh(['plan', 'user'])->toArray(), request('reason'));

        return back()->with('success', 'Subscription cancelled.');
    }

    private function linkSaleTransaction(
        WalletTransaction $walletTransaction,
        UserSubscription $subscription,
        SubscriptionPlan $plan,
        ?CarbonInterface $periodStartsAt,
        ?CarbonInterface $periodEndsAt,
        string $purchaseKind,
    ): void {
        $walletTransaction->update([
            'meta' => array_merge($walletTransaction->meta ?? [], [
                'subscription_id' => $subscription->id,
                'subscription_plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'purchase_kind' => $purchaseKind,
                'period_starts_at' => $periodStartsAt?->toIso8601String(),
                'period_ends_at' => $periodEndsAt?->toIso8601String(),
            ]),
        ]);
    }
}
