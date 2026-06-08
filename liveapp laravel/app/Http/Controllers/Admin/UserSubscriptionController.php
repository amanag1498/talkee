<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserSubscriptionRequest;
use App\Http\Requests\Admin\UpdateUserSubscriptionRequest;
use App\Models\User;
use App\Models\SubscriptionPlan;
use App\Models\UserSubscription;
use App\Services\AdminAuditService;
use App\Services\SubscriptionService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserSubscriptionController extends Controller
{
    public function __construct(private AdminAuditService $audits)
    {
    }

    public function index(Request $request)
    {
        $origin = $request->string('origin')->toString();
        $status = $request->string('status')->toString();

        $subs = UserSubscription::with(['user','plan'])
            ->when(in_array($status, ['active', 'expired', 'cancelled'], true), fn ($q) => $q->where('status', $status))
            ->origin($origin)
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $summary = [
            'active' => UserSubscription::query()->where('status', 'active')->count(),
            'expired' => UserSubscription::query()->where('status', 'expired')->count(),
            'cancelled' => UserSubscription::query()->where('status', 'cancelled')->count(),
            'purchased' => UserSubscription::query()->origin('purchased')->count(),
            'gifted' => UserSubscription::query()->origin('gifted')->count(),
            'admin_grant' => UserSubscription::query()->origin('admin_grant')->count(),
            'admin_charged' => UserSubscription::query()->origin('admin_charged')->count(),
        ];

        return view('admin.subscriptions.users.index', compact('subs', 'summary', 'origin', 'status'));
    }

    public function create()
    {
        return view('admin.subscriptions.users.create', [
            'users' => User::orderBy('id','desc')->limit(200)->get(['id','name','email']),
            'plans' => SubscriptionPlan::where('is_active',true)->orderBy('price_coins')->get(),
        ]);
    }

    public function store(StoreUserSubscriptionRequest $req, SubscriptionService $svc)
    {
        $data   = $req->validated();
        $user   = User::findOrFail($data['user_id']);
        $plan   = SubscriptionPlan::findOrFail($data['plan_id']);
        $charge = (bool)($data['charge_coins'] ?? false);

        return DB::transaction(function () use ($user,$plan,$data,$svc,$charge,$req) {
            // optionally charge wallet
            $walletTx = null;
            if ($charge) {
                $walletTx = WalletService::spend(
                    user: $user,
                    coins: $plan->price_coins,
                    category: 'subscription',
                    counterparty: null,
                    reference: "SUB_PLAN:{$plan->id}",
                    meta: ['plan_name'=>$plan->name,'event'=>'ADMIN_CREATE']
                );
            }

            $now  = now();
            $base = $data['starts_at'] ? \Carbon\Carbon::parse($data['starts_at']) : $now;
            $ends = $data['ends_at'] ?? $base->clone()->addDays($plan->duration_days);

            // compose meta
            $incomingMeta = (array)($data['meta'] ?? $req->input('meta', []));
            $source = $charge ? 'admin_charged' : ($req->input('source_type') ?: 'admin_grant');
            $meta = array_merge($incomingMeta, [
                'source'     => $source,
                'event'      => $charge ? 'ADMIN_CREATE_CHARGED' : 'ADMIN_CREATE_GRANT',
                'charged'    => $charge,
                'plan_name'  => $plan->name,
                'price_coins' => (int) $plan->price_coins,
                'wallet_transaction_id' => $walletTx->id ?? null,
                'note'       => $req->input('meta.note'),
                'created_at' => $now->toIso8601String(),
            ]);

            $subscription = UserSubscription::create([
                'user_id'              => $user->id,
                'subscription_plan_id' => $plan->id,
                'status'               => $data['status'],
                'starts_at'            => $base,
                'ends_at'              => $ends,
                'last_purchased_at'    => $now,
                'meta'                 => $meta,
            ]);

            $this->audits->log('subscriptions', 'subscription_created', $req->user(), $user, $subscription, null, $subscription->fresh(['plan', 'user'])->toArray(), $req->input('reason'), [
                'charge_coins' => $charge,
            ]);

            return redirect()
                ->route('admin.user-subscriptions.index')
                ->with('success','Subscription created.');
        });
    }

    public function edit(UserSubscription $user_subscription)
    {
        return view('admin.subscriptions.users.edit', [
            'sub'   => $user_subscription->load(['user','plan']),
            'plans' => SubscriptionPlan::orderBy('price_coins')->get(),
        ]);
    }

    public function update(UpdateUserSubscriptionRequest $req, UserSubscription $user_subscription)
    {
        $data   = $req->validated();
        $plan   = SubscriptionPlan::findOrFail($data['plan_id']);
        $charge = (bool)($data['charge_coins'] ?? false);

        return DB::transaction(function () use ($user_subscription,$data,$plan,$charge,$req) {
            $before = $user_subscription->fresh(['plan', 'user'])->toArray();
            $walletTx = null;
            if ($charge) {
                $walletTx = WalletService::spend(
                    user: $user_subscription->user,
                    coins: $plan->price_coins,
                    category: 'subscription',
                    counterparty: null,
                    reference: "SUB_PLAN:{$plan->id}",
                    meta: ['plan_name'=>$plan->name,'event'=>'ADMIN_UPDATE']
                );
            }

            $starts = $data['starts_at'] ? \Carbon\Carbon::parse($data['starts_at']) : $user_subscription->starts_at;
            $ends   = $data['ends_at'] ? \Carbon\Carbon::parse($data['ends_at']) : $user_subscription->ends_at;

            // merge meta (keep existing keys, update audit info)
            $existingMeta = (array)($user_subscription->meta ?? []);
            $incomingMeta = (array)($data['meta'] ?? $req->input('meta', []));
            $meta = array_merge($existingMeta, $incomingMeta, [
                'last_action'    => $charge ? 'ADMIN_UPDATE_CHARGED' : 'ADMIN_UPDATE',
                'source'         => $charge ? 'admin_charged' : ($incomingMeta['source'] ?? $existingMeta['source'] ?? 'admin_grant'),
                'charged'        => $charge || filter_var($existingMeta['charged'] ?? false, FILTER_VALIDATE_BOOL),
                'plan_name'      => $plan->name,
                'price_coins'    => (int) $plan->price_coins,
                'wallet_transaction_id' => $charge ? ($walletTx->id ?? null) : ($existingMeta['wallet_transaction_id'] ?? null),
                'last_updated_at'=> now()->toIso8601String(),
            ]);

            $user_subscription->update([
                'subscription_plan_id' => $plan->id,
                'status'               => $data['status'],
                'starts_at'            => $starts,
                'ends_at'              => $ends,
                'meta'                 => $meta,
            ]);

            $this->audits->log('subscriptions', 'subscription_updated', $req->user(), $user_subscription->user, $user_subscription, $before, $user_subscription->fresh(['plan', 'user'])->toArray(), $req->input('reason'), [
                'charge_coins' => $charge,
            ]);

            return redirect()
                ->route('admin.user-subscriptions.index')
                ->with('success','Subscription updated.');
        });
    }

    public function destroy(UserSubscription $user_subscription)
    {
        $before = $user_subscription->fresh(['plan', 'user'])->toArray();
        $targetUser = $user_subscription->user;
        $user_subscription->delete();
        $this->audits->log('subscriptions', 'subscription_deleted', request()->user(), $targetUser, $user_subscription, $before, null, request('reason'));
        return back()->with('success','Subscription deleted.');
    }

    public function cancel($id, SubscriptionService $svc)
    {
        $sub = UserSubscription::findOrFail($id);
        $before = $sub->fresh(['plan', 'user'])->toArray();
        $svc->cancelNow($sub);

        // append a meta audit entry on cancel
        $meta = (array)($sub->meta ?? []);
        $meta['last_action']     = 'ADMIN_CANCEL';
        $meta['last_updated_at'] = now()->toIso8601String();
        $sub->update(['meta' => $meta]);
        $this->audits->log('subscriptions', 'subscription_cancelled', request()->user(), $sub->user, $sub, $before, $sub->fresh(['plan', 'user'])->toArray(), request('reason'));

        return back()->with('success','Subscription cancelled.');
    }
}
