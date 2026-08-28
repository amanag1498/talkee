<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EntryPack;
use App\Models\FortuneWheelSegment;
use App\Models\SubscriptionPlan;
use App\Services\FortuneWheelService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FortuneWheelAdminController extends Controller
{
    public function __construct(private FortuneWheelService $fortuneWheel) {}

    public function dashboard()
    {
        return view('admin.games.fortune-wheel.dashboard', [
            'payload' => $this->fortuneWheel->adminDashboardPayload(),
        ]);
    }

    public function storeSegment(Request $request)
    {
        $data = $this->validatedSegment($request, null);
        FortuneWheelSegment::query()->create($data);

        return back()->with('ok', 'Fortune Wheel segment created.');
    }

    public function updateSegment(Request $request, FortuneWheelSegment $segment)
    {
        $data = $this->validatedSegment($request, $segment);
        $this->guardLastSelectableSegment($segment, (bool) $data['is_active']);
        $segment->update($data);

        return back()->with('ok', 'Fortune Wheel segment updated.');
    }

    public function destroySegment(FortuneWheelSegment $segment)
    {
        $this->guardLastSelectableSegment($segment, false);
        $segment->delete();

        return back()->with('ok', 'Fortune Wheel segment deleted.');
    }

    private function validatedSegment(Request $request, ?FortuneWheelSegment $segment): array
    {
        $data = $request->validate([
            'label' => [
                'required',
                'string',
                'max:120',
                Rule::unique('fortune_wheel_segments', 'label')->ignore($segment?->id),
            ],
            'reward_type' => ['required', 'string', Rule::in(FortuneWheelSegment::REWARD_TYPES)],
            'reward_value_coins' => ['nullable', 'integer', 'min:0'],
            'entry_pack_id' => ['nullable', 'integer', 'exists:entry_packs,id'],
            'subscription_plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'reward_duration_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'weight' => ['required', 'integer', 'min:1', 'max:100000'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'icon_url' => ['nullable', 'url:http,https', 'max:2048'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        $data['label'] = trim($data['label']);
        $data['color'] = filled($data['color'] ?? null) ? strtoupper(trim($data['color'])) : null;
        $data['icon_url'] = filled($data['icon_url'] ?? null) ? trim($data['icon_url']) : null;
        $data['reward_value_coins'] = $data['reward_type'] === FortuneWheelSegment::REWARD_COINS
            ? (int) ($data['reward_value_coins'] ?? 0)
            : 0;
        $data['entry_pack_id'] = $data['reward_type'] === FortuneWheelSegment::REWARD_ENTRY_PACK
            ? $data['entry_pack_id']
            : null;
        $data['subscription_plan_id'] = $data['reward_type'] === FortuneWheelSegment::REWARD_SUBSCRIPTION
            ? $data['subscription_plan_id']
            : null;
        $data['reward_duration_hours'] = in_array($data['reward_type'], [FortuneWheelSegment::REWARD_ENTRY_PACK, FortuneWheelSegment::REWARD_SUBSCRIPTION], true)
            ? $data['reward_duration_hours']
            : null;

        if ($data['reward_type'] === FortuneWheelSegment::REWARD_ENTRY_PACK && empty($data['entry_pack_id'])) {
            throw ValidationException::withMessages([
                'entry_pack_id' => 'Choose an entry pack reward.',
            ]);
        }

        if (
            (bool) $data['is_active']
            && $data['reward_type'] === FortuneWheelSegment::REWARD_ENTRY_PACK
            && ! EntryPack::query()->whereKey($data['entry_pack_id'])->where('is_active', true)->exists()
        ) {
            throw ValidationException::withMessages([
                'entry_pack_id' => 'An active segment must use an active entry pack.',
            ]);
        }

        if ($data['reward_type'] === FortuneWheelSegment::REWARD_SUBSCRIPTION && empty($data['subscription_plan_id'])) {
            throw ValidationException::withMessages([
                'subscription_plan_id' => 'Choose a subscription reward.',
            ]);
        }

        if (
            (bool) $data['is_active']
            && $data['reward_type'] === FortuneWheelSegment::REWARD_SUBSCRIPTION
            && ! SubscriptionPlan::query()->whereKey($data['subscription_plan_id'])->where('is_active', true)->exists()
        ) {
            throw ValidationException::withMessages([
                'subscription_plan_id' => 'An active segment must use an active subscription plan.',
            ]);
        }

        if (in_array($data['reward_type'], [FortuneWheelSegment::REWARD_ENTRY_PACK, FortuneWheelSegment::REWARD_SUBSCRIPTION], true) && empty($data['reward_duration_hours'])) {
            throw ValidationException::withMessages([
                'reward_duration_hours' => 'Reward duration is required for timed rewards.',
            ]);
        }

        return $data;
    }

    private function guardLastSelectableSegment(FortuneWheelSegment $segment, bool $willRemainActive): void
    {
        if ($willRemainActive || ! $this->fortuneWheel->enabled()) {
            return;
        }

        $payload = $this->fortuneWheel->adminDashboardPayload();
        $eligibleIds = collect($payload['eligible_segment_ids'] ?? []);
        if ($eligibleIds->count() === 1 && $eligibleIds->contains((int) $segment->id)) {
            throw ValidationException::withMessages([
                'segment' => 'Disable the Fortune Wheel or activate another selectable segment before removing the final reward.',
            ]);
        }
    }
}
