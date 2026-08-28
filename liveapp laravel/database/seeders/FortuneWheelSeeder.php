<?php

namespace Database\Seeders;

use App\Models\EntryPack;
use App\Models\FortuneWheelSegment;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class FortuneWheelSeeder extends Seeder
{
    public function run(): void
    {
        // Catalog rows are managed independently by the admin. This seeder
        // only links rewards to matching active rows and must never create or
        // overwrite production entry packs or subscription plans.
        $this->seedCoinRewards();
        $this->seedEntryPackRewards();
        $this->seedSubscriptionRewards();
    }

    private function seedCoinRewards(): void
    {
        $segments = [
            [
                'label' => '0 Coins',
                'reward_value_coins' => 0,
                'weight' => 42,
                'color' => '#334155',
                'sort_order' => 10,
            ],
            [
                'label' => '5 Coins',
                'reward_value_coins' => 5,
                'weight' => 24,
                'color' => '#14B8A6',
                'sort_order' => 20,
            ],
            [
                'label' => '10 Coins',
                'reward_value_coins' => 10,
                'weight' => 28,
                'color' => '#22C55E',
                'sort_order' => 30,
            ],
            [
                'label' => '25 Coins',
                'reward_value_coins' => 25,
                'weight' => 16,
                'color' => '#38BDF8',
                'sort_order' => 40,
            ],
            [
                'label' => '50 Coins',
                'reward_value_coins' => 50,
                'weight' => 8,
                'color' => '#A855F7',
                'sort_order' => 50,
            ],
            [
                'label' => '75 Coins',
                'reward_value_coins' => 75,
                'weight' => 3,
                'color' => '#EC4899',
                'sort_order' => 60,
            ],
            [
                'label' => '100 Coins',
                'reward_value_coins' => 100,
                'weight' => 3,
                'color' => '#F97316',
                'sort_order' => 70,
            ],
            [
                'label' => '200 Coins',
                'reward_value_coins' => 200,
                'weight' => 1,
                'color' => '#EAB308',
                'sort_order' => 80,
            ],
        ];

        foreach ($segments as $segment) {
            FortuneWheelSegment::query()->updateOrCreate(
                ['label' => $segment['label']],
                array_merge($segment, [
                    'reward_type' => FortuneWheelSegment::REWARD_COINS,
                    'entry_pack_id' => null,
                    'subscription_plan_id' => null,
                    'reward_duration_hours' => null,
                    'is_active' => true,
                    'meta' => [
                        'seeded' => true,
                        'profile' => 'default_coin_reward',
                    ],
                ]),
            );
        }
    }

    private function seedEntryPackRewards(): void
    {
        $packs = [
            ['name' => 'Royal Entry', 'label' => 'Royal Entry 1 Day', 'weight' => 4, 'color' => '#F59E0B', 'sort_order' => 90],
            ['name' => 'CAR', 'label' => 'CAR 1 Day', 'weight' => 2, 'color' => '#0EA5E9', 'sort_order' => 100],
            ['name' => 'CAR 2', 'label' => 'CAR 2 1 Day', 'weight' => 2, 'color' => '#2563EB', 'sort_order' => 110],
            ['name' => 'CAR 3', 'label' => 'CAR 3 1 Day', 'weight' => 2, 'color' => '#4F46E5', 'sort_order' => 120],
            ['name' => 'SPACESHIP', 'label' => 'Spaceship 1 Day', 'weight' => 1, 'color' => '#7C3AED', 'sort_order' => 130],
            ['name' => 'DRAGON', 'label' => 'Dragon 1 Day', 'weight' => 1, 'color' => '#DC2626', 'sort_order' => 140],
            ['name' => 'Leopard', 'label' => 'Leopard 1 Day', 'weight' => 1, 'color' => '#D97706', 'sort_order' => 150],
        ];

        foreach ($packs as $config) {
            $pack = EntryPack::query()
                ->where('name', $config['name'])
                ->where('is_active', true)
                ->first();

            if (! $pack) {
                $this->deactivateUnavailableSeededReward(
                    FortuneWheelSegment::REWARD_ENTRY_PACK,
                    $config['label'],
                    'default_entry_pack_reward',
                );

                continue;
            }

            FortuneWheelSegment::query()->updateOrCreate(
                ['label' => $config['label']],
                [
                    'reward_type' => FortuneWheelSegment::REWARD_ENTRY_PACK,
                    'reward_value_coins' => 0,
                    'entry_pack_id' => $pack->id,
                    'subscription_plan_id' => null,
                    'reward_duration_hours' => 24,
                    'weight' => $config['weight'],
                    'color' => $config['color'],
                    'icon_url' => null,
                    'is_active' => true,
                    'sort_order' => $config['sort_order'],
                    'meta' => [
                        'seeded' => true,
                        'profile' => 'default_entry_pack_reward',
                        'entry_pack_name' => $pack->name,
                    ],
                ],
            );
        }

        $this->deactivateRemovedSeededRewards(
            FortuneWheelSegment::REWARD_ENTRY_PACK,
            array_column($packs, 'label'),
            'default_entry_pack_reward',
        );
    }

    private function seedSubscriptionRewards(): void
    {
        $plans = [
            ['name' => 'Base', 'label' => 'Base 1 Day', 'weight' => 3, 'color' => '#64748B', 'sort_order' => 160],
            ['name' => 'Bronze', 'label' => 'Bronze 1 Day', 'weight' => 2, 'color' => '#CD7F32', 'sort_order' => 170],
            ['name' => 'Silver', 'label' => 'Silver 1 Day', 'weight' => 1, 'color' => '#94A3B8', 'sort_order' => 180],
            ['name' => 'Gold', 'label' => 'Gold 1 Day', 'weight' => 1, 'color' => '#EAB308', 'sort_order' => 190],
            ['name' => 'Platinum', 'label' => 'Platinum 1 Day', 'weight' => 1, 'color' => '#06B6D4', 'sort_order' => 200],
        ];

        foreach ($plans as $config) {
            $plan = SubscriptionPlan::query()
                ->where('name', $config['name'])
                ->where('is_active', true)
                ->first();

            if (! $plan) {
                $this->deactivateUnavailableSeededReward(
                    FortuneWheelSegment::REWARD_SUBSCRIPTION,
                    $config['label'],
                    'default_subscription_reward',
                );

                continue;
            }

            FortuneWheelSegment::query()->updateOrCreate(
                ['label' => $config['label']],
                [
                    'reward_type' => FortuneWheelSegment::REWARD_SUBSCRIPTION,
                    'reward_value_coins' => 0,
                    'entry_pack_id' => null,
                    'subscription_plan_id' => $plan->id,
                    'reward_duration_hours' => 24,
                    'weight' => $config['weight'],
                    'color' => $config['color'],
                    'icon_url' => null,
                    'is_active' => true,
                    'sort_order' => $config['sort_order'],
                    'meta' => [
                        'seeded' => true,
                        'profile' => 'default_subscription_reward',
                        'subscription_plan_name' => $plan->name,
                    ],
                ],
            );
        }

        $this->deactivateRemovedSeededRewards(
            FortuneWheelSegment::REWARD_SUBSCRIPTION,
            array_column($plans, 'label'),
            'default_subscription_reward',
        );
    }

    private function deactivateRemovedSeededRewards(string $rewardType, array $currentLabels, string $profile): void
    {
        FortuneWheelSegment::query()
            ->where('reward_type', $rewardType)
            ->whereNotIn('label', $currentLabels)
            ->get()
            ->filter(fn (FortuneWheelSegment $segment) => data_get($segment->meta, 'seeded') === true
                && data_get($segment->meta, 'profile') === $profile)
            ->each->update(['is_active' => false]);
    }

    private function deactivateUnavailableSeededReward(string $rewardType, string $label, string $profile): void
    {
        $segment = FortuneWheelSegment::query()
            ->where('reward_type', $rewardType)
            ->where('label', $label)
            ->first();

        if (
            $segment
            && data_get($segment->meta, 'seeded') === true
            && data_get($segment->meta, 'profile') === $profile
        ) {
            $segment->update(['is_active' => false]);
        }
    }
}
