<?php

namespace Database\Seeders;

use App\Models\Theme;
use App\Services\ThemeUnlockService;
use Illuminate\Database\Seeder;

class ThemeSeeder extends Seeder
{
    public function run(): void
    {
        $themes = [
            [
                'key' => 'midnight',
                'name' => 'Midnight',
                'description' => 'Default app theme available to everyone.',
                'unlock_type' => 'free',
                'token_source' => 'local',
                'is_active' => true,
                'is_default' => true,
                'is_limited' => false,
                'sort_order' => 0,
            ],
            [
                'key' => 'aurora',
                'name' => 'Aurora',
                'description' => 'Unlocked with an active premium subscription.',
                'unlock_type' => 'subscription',
                'token_source' => 'local',
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'key' => 'gold',
                'name' => 'Gold',
                'description' => 'VIP-only premium theme.',
                'unlock_type' => 'vip_subscription',
                'token_source' => 'local',
                'is_active' => true,
                'sort_order' => 20,
            ],
            [
                'key' => 'ocean',
                'name' => 'Ocean',
                'description' => 'Unlock after maintaining a long login streak.',
                'unlock_type' => 'login_streak',
                'token_source' => 'local',
                'required_login_streak_days' => 14,
                'is_active' => true,
                'sort_order' => 30,
            ],
            [
                'key' => 'inferno',
                'name' => 'Inferno',
                'description' => 'Rewarded for proving yourself in PK battles.',
                'unlock_type' => 'pk_event',
                'token_source' => 'local',
                'is_active' => true,
                'sort_order' => 40,
                'metadata' => ['pk_event_type' => 'win'],
            ],
            [
                'key' => 'emerald',
                'name' => 'Emerald',
                'description' => 'Unlocked after your first successful recharge.',
                'unlock_type' => 'first_recharge',
                'token_source' => 'local',
                'is_active' => true,
                'sort_order' => 50,
            ],
            [
                'key' => 'ice',
                'name' => 'Ice',
                'description' => 'Unlocked with an active subscription.',
                'unlock_type' => 'subscription',
                'token_source' => 'local',
                'is_active' => true,
                'sort_order' => 60,
            ],
            [
                'key' => 'cyberpunk',
                'name' => 'Cyberpunk',
                'description' => 'Rare top-spender or admin grant reward.',
                'unlock_type' => 'top_spender',
                'token_source' => 'local',
                'is_active' => true,
                'sort_order' => 70,
                'metadata' => [
                    'top_rank_threshold' => 3,
                    'min_lifetime_spend_coins' => 250000,
                ],
            ],
            [
                'key' => 'ruby_sky',
                'name' => 'Ruby Sky',
                'description' => 'Unlocked after reaching the configured gift spend milestone.',
                'unlock_type' => 'gift_spend',
                'token_source' => 'local',
                'required_total_gift_spend' => 100000,
                'is_active' => true,
                'sort_order' => 80,
            ],
            [
                'key' => 'violet_lime',
                'name' => 'Violet Lime',
                'description' => 'Unlocked by reaching a higher user level.',
                'unlock_type' => 'user_level',
                'token_source' => 'local',
                'required_user_level' => 12,
                'is_active' => true,
                'sort_order' => 90,
            ],
            [
                'key' => 'sunset_pop',
                'name' => 'Sunset Pop',
                'description' => 'Weekend or event reward theme.',
                'unlock_type' => 'event_reward',
                'token_source' => 'local',
                'is_active' => true,
                'is_limited' => true,
                'sort_order' => 100,
                'event_key' => 'weekend_reward',
            ],
            [
                'key' => 'teal_rose',
                'name' => 'Teal Rose',
                'description' => 'Unlocked after hitting a host follower milestone.',
                'unlock_type' => 'host_follower_milestone',
                'token_source' => 'local',
                'required_host_followers' => 2500,
                'is_active' => true,
                'sort_order' => 110,
            ],
            [
                'key' => 'gold_black',
                'name' => 'Gold Black',
                'description' => 'High-tier VIP theme.',
                'unlock_type' => 'vip_high_tier',
                'token_source' => 'local',
                'is_active' => true,
                'sort_order' => 120,
                'metadata' => ['min_plan_price_coins' => 3000],
            ],
            [
                'key' => 'obsidian_rose',
                'name' => 'Obsidian Rose',
                'description' => 'Limited-time paid premium theme.',
                'unlock_type' => 'limited_paid',
                'token_source' => 'local',
                'is_active' => true,
                'is_limited' => true,
                'price' => 7500,
                'sort_order' => 130,
            ],
            [
                'key' => 'royal_sapphire',
                'name' => 'Royal Sapphire',
                'description' => 'Agency-linked host elite reward.',
                'unlock_type' => 'agency_host_elite',
                'token_source' => 'local',
                'is_active' => true,
                'sort_order' => 140,
            ],
            [
                'key' => 'noir_opal',
                'name' => 'Noir Opal',
                'description' => 'Unlocked through successful referrals.',
                'unlock_type' => 'referral',
                'token_source' => 'local',
                'required_referrals' => 12,
                'is_active' => true,
                'sort_order' => 150,
            ],
            [
                'key' => 'imperial_jade',
                'name' => 'Imperial Jade',
                'description' => 'Unlocked by total recharge milestone.',
                'unlock_type' => 'recharge_milestone',
                'token_source' => 'local',
                'required_total_recharge' => 15000,
                'is_active' => true,
                'sort_order' => 160,
            ],
            [
                'key' => 'molten_pearl',
                'name' => 'Molten Pearl',
                'description' => 'Festival event theme available during the configured window.',
                'unlock_type' => 'festival_event',
                'token_source' => 'local',
                'is_active' => true,
                'is_limited' => true,
                'sort_order' => 170,
                'event_key' => 'festival_event',
            ],
            [
                'key' => 'amethyst_chrome',
                'name' => 'Amethyst Chrome',
                'description' => 'Long-term loyalty reward theme.',
                'unlock_type' => 'loyalty',
                'token_source' => 'local',
                'is_active' => true,
                'sort_order' => 180,
                'metadata' => ['required_loyalty_days' => 365],
            ],
            [
                'key' => 'crimson_velvet',
                'name' => 'Crimson Velvet',
                'description' => 'Special festival theme available during its configured window.',
                'unlock_type' => 'festival_event',
                'token_source' => 'local',
                'is_active' => true,
                'is_limited' => true,
                'sort_order' => 190,
                'event_key' => 'valentine',
            ],
        ];

        foreach ($themes as $theme) {
            Theme::query()->updateOrCreate(
                ['key' => $theme['key']],
                $theme
            );
        }

        if (app()->bound(ThemeUnlockService::class)) {
            app(ThemeUnlockService::class)->flushCache();
        }
    }
}
