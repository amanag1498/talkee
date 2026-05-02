<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{SubscriptionPlan, UserSubscription};
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;


class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $svc) {}

    public function purchase(Request $req)
    {
        try {
            // ✅ use validate() on Illuminate\Http\Request
            $data = $req->validate([
                'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            ]);

            $plan = SubscriptionPlan::findOrFail($data['plan_id']);

            $sub = $this->svc->purchase($req->user(), $plan);

            return response()->json(['ok' => true, 'subscription' => $sub], 201);

        } catch (\InvalidArgumentException $e) {
            // e.g. your WalletService throws "Insufficient ..."
            if (str_contains($e->getMessage(), 'Insufficient')) {
                return response()->json(['ok' => false, 'error' => 'INSUFFICIENT_FUNDS'], 402);
            }
            Log::error('SUB_PURCHASE_FAIL', [
                'user_id' => $req->user()?->id,
                'plan_id' => $req->input('plan_id'),
                'message' => $e->getMessage(),
                'type'    => get_class($e),
            ]);
            throw $e; // let Laravel show the real error in logs

        } catch (\Throwable $e) {
            // Any other unexpected error -> 500
            Log::error('SUB_PURCHASE_FAIL', [
                'user_id' => $req->user()?->id,
                'plan_id' => $req->input('plan_id'),
                'message' => $e->getMessage(),
                'type'    => get_class($e),
            ]);
            return response()->json(['ok' => false, 'error' => 'SERVER_ERROR'], 500);
        }
    }

    public function mine(Request $req)
    {
        return UserSubscription::with('plan')
            ->where('user_id', $req->user()->id)
            ->orderByDesc('created_at')
            ->get();
    }

    public function cancel(Request $req, $id)
    {
        $sub = UserSubscription::where('id', $id)
            ->where('user_id', $req->user()->id)
            ->firstOrFail();

        app(SubscriptionService::class)->cancelNow($sub);

        return ['ok' => true];
    }
    // app/Http/Controllers/Api/SubscriptionController.php

public function welcomeTip(Request $req)
{
    // latest active signup_gift subscription that hasn't been acknowledged
    $sub = UserSubscription::with('plan')
        ->where('user_id', $req->user()->id)
        ->where('status','active')
        ->where('subscription_plan_id','>',0)
        ->where(function ($q) {
            $q->whereNull('meta->welcome_popup_ack_at')
              ->orWhere('meta->welcome_popup_ack_at', '');
        })
        ->where('meta->source','signup_gift')   // <- set when you created the gift
        ->orderByDesc('created_at')
        ->first();

    if (!$sub) {
        // Nothing to show
        return response()->json(['ok'=>true,'show'=>false], 200);
    }

    return response()->json([
        'ok'    => true,
        'show'  => true,
        'sub_id'=> $sub->id,
        'plan'  => [
            'id'       => $sub->plan?->id,
            'name'     => $sub->plan?->name ?? 'Premium',
            'ends_at'  => optional($sub->ends_at)->toIso8601String(),
            'meta'     => $sub->meta, // optional
        ],
    ], 200);
}

public function ackWelcomeTip(Request $req)
{
    $data = $req->validate([
        'sub_id' => 'required|integer',
    ]);

    $sub = UserSubscription::where('id', $data['sub_id'])
        ->where('user_id', $req->user()->id)
        ->firstOrFail();

    $meta = $sub->meta ?? [];
    $meta['welcome_popup_ack_at'] = now()->toIso8601String();
    $meta['welcome_popup_seen_count'] = ($meta['welcome_popup_seen_count'] ?? 0) + 1;

    $sub->update(['meta' => $meta]);

    return ['ok'=>true];
}
}
