# Fortune Wheel Implementation Plan

## Goal

Build a new Fortune Wheel game that gives each user one free spin per day and then charges coins for additional spins. The backend must decide every result, and the app should only animate to the selected backend segment.

Fortune Wheel must use the same specific-user game access model as Teen Patti and Greedy. Global/platform settings can enable the game, but a user should only see or call it when their `user_game_accesses` row includes `game_key = fortune_wheel`.

## Reward Rules

Every spin lands on a configured reward segment. There is no `try again` or empty result.

Allowed reward types:

- `coins`
- `entry_pack`
- `subscription`

`0 Coins` is allowed as a real `coins` reward with `reward_value_coins = 0`.

## Admin Control

Admin controls:

- Game enabled/disabled.
- Visibility in the games surface.
- User-specific access from the admin user detail page.
- Free spins per day.
- Paid spin cost.
- Whether paid spins are enabled.
- Timezone used for daily free-spin reset.
- Wheel segments.
- Segment label, type, value, duration, weight, color, icon, active status, and order.

## Backend Tables

### `fortune_wheel_segments`

Stores wheel reward configuration.

- `id`
- `label`
- `reward_type`
- `reward_value_coins`
- `entry_pack_id`
- `subscription_plan_id`
- `reward_duration_hours`
- `weight`
- `color`
- `icon_url`
- `is_active`
- `sort_order`
- `meta`
- timestamps

### `fortune_wheel_spins`

Stores gameplay history needed for free-spin limits, idempotency, and entitlement references. This is not a separate audit module.

- `id`
- `user_id`
- `fortune_wheel_segment_id`
- `spin_type`
- `spin_cost_coins`
- `reward_type`
- `reward_value_coins`
- `entry_pack_id`
- `subscription_plan_id`
- `reward_duration_hours`
- `wallet_debit_transaction_id`
- `wallet_credit_transaction_id`
- `user_entry_pack_id`
- `user_subscription_id`
- `idempotency_key`
- `spun_for_date`
- `meta`
- timestamps

## Spin Flow

1. Lock the user wallet.
2. Count today's spins in the configured timezone.
3. If free spins remain, use `spin_type = free` and cost `0`.
4. Otherwise, require paid spins to be enabled and debit the configured spin cost.
5. Select one active segment using weighted random.
6. Apply reward:
   - `coins`: credit wallet when reward is greater than `0`; do not create a credit transaction for `0 Coins`.
   - `entry_pack`: create `user_entry_packs` with `expires_at = now + reward_duration_hours`.
   - `subscription`: create or extend a `user_subscriptions` row with `ends_at = base + reward_duration_hours`.
7. Save the spin result.
8. Return selected segment, reward details, free spins remaining, and wallet balance.

## Reward Entitlements

### Entry Pack

Create a normal `UserEntryPack` row:

- `purchased_at = now`
- `expires_at = now + reward_duration_hours`
- `purchase_key = fortune_wheel:{spin_id or idempotency key}`

Default activation behavior:

- If the user has no active entry pack, activate the rewarded pack.
- If the user already has an active entry pack, keep the current active pack and add the new reward as owned.

### Subscription

Create or extend a normal `UserSubscription` row:

- If the same plan is active and has a future `ends_at`, extend from that `ends_at`.
- Otherwise start now.
- Store `source = fortune_wheel` in `meta`.

## API

- `GET /api/games/fortune-wheel`
- `POST /api/games/fortune-wheel/spin`
- `GET /api/games/fortune-wheel/history`

All three APIs require:

- authenticated user
- platform `fortune_wheel_enabled`
- global `games.fortune_wheel.enabled`
- user-specific `fortune_wheel` access

## Admin Pages

Add `Admin > Games > Fortune Wheel`.

The page should show:

- Enabled status.
- Free spins per day.
- Paid spin cost.
- Spins today.
- Free spins today.
- Paid spins today.
- Coins collected.
- Coins rewarded.
- Active segment count.
- Segment list and forms.

## Flutter

The frontend should:

- Load snapshot from backend as soon as the app opens after login, only when app config returns `features.fortune_wheel_enabled = true`.
- Render segments from backend config.
- Show a Fortune Wheel link in the games bottom sheet only when the same `fortune_wheel_enabled` flag is true.
- Show `Free Spin` when available.
- Show `Spin for X coins` after free spin is used.
- Call backend before animation.
- Animate to backend-selected segment.
- Show reward result:
  - `You won 0 coins`
  - `You won 50 coins`
  - `You won Entry Pack for 1 day`
  - `You won Subscription for 1 day`

## Tests

Backend tests:

- Free spin can be used once per day.
- Second spin charges coins.
- Insufficient balance blocks paid spin.
- Idempotency does not double debit or double reward.
- Coin reward credits wallet.
- `0 Coins` reward does not create a wallet credit.
- Entry pack reward creates a timed user entry pack.
- Subscription reward creates or extends a timed subscription.
- App config only exposes Fortune Wheel for allowed users.
- Fortune Wheel APIs are locked until the user has `fortune_wheel` access.
- Inactive segments are never selected.
- Disabled game blocks spin.
- Paid spins disabled blocks after free spin is used.
