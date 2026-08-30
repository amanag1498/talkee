<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyPayoutReport;
use App\Models\CallEarningLedger;
use App\Models\CallSession;
use App\Models\Gift;
use App\Models\Host;
use App\Models\LiveRoom;
use App\Models\LiveRoomGift;
use App\Models\LiveRoomGiftEarningLedger;
use App\Models\LiveRoomPkBattle;
use App\Models\LiveRoomPkEvent;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\AgencyWeeklyPayoutReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AgencyPayoutReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_command_generates_reports_idempotently_for_all_agencies(): void
    {
        [$agency, $owner, $host] = $this->seedAgencyFixture();
        $emptyOwner = User::factory()->create();
        $emptyOwner->assignRole('agency');
        Agency::query()->create([
            'owner_user_id' => $emptyOwner->id,
            'name' => 'Zero Orbit',
        ]);

        Artisan::call('agency:payout-reports:generate', [
            '--start' => '2026-04-21',
            '--end' => '2026-04-27',
        ]);

        $this->assertDatabaseCount('agency_payout_reports', 3);

        /** @var AgencyPayoutReport $report */
        $report = AgencyPayoutReport::query()
            ->where('agency_id', $agency->id)
            ->with('items')
            ->firstOrFail();

        $this->assertSame(200, $report->gross_earnings);
        $this->assertSame(0, $report->platform_commission);
        $this->assertSame(0, $report->agency_commission);
        $this->assertSame(200, $report->host_share);
        $this->assertSame(200, $report->final_payable);
        $this->assertSame(1, $report->total_hosts);
        $this->assertSame(1, $report->active_hosts_count);
        $this->assertSame(100, $report->total_call_earnings);
        $this->assertSame(50, $report->total_gift_earnings);
        $this->assertSame(50, $report->total_live_room_earnings);
        $this->assertSame(50, $report->total_pk_earnings);
        $this->assertSame(50, $report->total_video_gift_coins);
        $this->assertSame(0, $report->total_audio_gift_coins);
        $this->assertSame(50, $report->total_pk_gift_coins);
        $this->assertSame(100, $report->total_video_call_coins);
        $this->assertSame(0, $report->total_audio_call_coins);
        $this->assertSame(200, $report->total_coins);
        $this->assertSame(0, $report->total_agency_commission_coins);
        $this->assertSame(200, $report->total_coins_to_be_paid);

        $item = $report->items->firstOrFail();
        $this->assertSame($host->id, $item->host_id);
        $this->assertSame(100, $item->call_earnings);
        $this->assertSame(50, $item->gift_earnings);
        $this->assertSame(50, $item->live_room_earnings);
        $this->assertSame(50, $item->pk_earnings);
        $this->assertSame(200, $item->total_coins);
        $this->assertSame(200, $item->total_coins_to_be_paid);

        Artisan::call('agency:payout-reports:generate', [
            '--start' => '2026-04-21',
            '--end' => '2026-04-27',
        ]);

        $this->assertDatabaseCount('agency_payout_reports', 3);
    }

    public function test_force_regeneration_replaces_unpaid_report_and_paid_report_cannot_be_paid_twice(): void
    {
        [$agency, $owner] = $this->seedAgencyFixture();

        $service = app(AgencyWeeklyPayoutReportService::class);
        [$start, $end] = $service->resolvePeriod('2026-04-21', '2026-04-27');

        $first = $service->generate($start, $end, $agency->id, false)['reports'][0];
        $this->assertSame(200, $first->final_payable);

        CallEarningLedger::query()->create([
            'call_session_id' => CallSession::query()->create([
                'caller_id' => User::factory()->create()->id,
                'receiver_id' => $agency->hosts()->firstOrFail()->user_id,
                'host_id' => $agency->hosts()->firstOrFail()->id,
                'agency_id' => $agency->id,
                'type' => 'audio',
                'status' => 'ended',
                'coin_rate_per_minute' => 20,
                'billable_minutes' => 1,
                'total_coins_charged' => 20,
                'host_earning' => 12,
                'agency_earning' => 2,
                'platform_earning' => 6,
            ])->id,
            'caller_id' => User::factory()->create()->id,
            'host_id' => $agency->hosts()->firstOrFail()->id,
            'agency_id' => $agency->id,
            'total_coins' => 20,
            'host_earning' => 12,
            'agency_earning' => 2,
            'platform_earning' => 6,
            'duration_seconds' => 60,
            'billable_minutes' => 1,
            'created_at' => '2026-04-24 10:00:00',
            'updated_at' => '2026-04-24 10:00:00',
        ]);

        $forced = $service->generate($start, $end, $agency->id, true)['reports'][0];
        $this->assertSame(220, $forced->gross_earnings);
        $this->assertSame(220, $forced->final_payable);
        $this->assertDatabaseCount('agency_payout_reports', 1);

        $service->approve($forced, 2, 'Approved');
        $paid = $service->markPaid($forced, 'Paid');
        $owner->refresh();
        $this->assertSame('paid', $paid->status);
        $this->assertSame(0, (int) $owner->wallet->fresh()->balance);
        $this->assertDatabaseMissing('wallet_transactions', [
            'wallet_id' => $owner->wallet->id,
            'reference' => 'agency_payout_report:' . $paid->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $service->markPaid($paid, 'Duplicate pay');
    }

    public function test_paid_report_cannot_be_regenerated_with_force(): void
    {
        [$agency] = $this->seedAgencyFixture();

        $service = app(AgencyWeeklyPayoutReportService::class);
        [$start, $end] = $service->resolvePeriod('2026-04-21', '2026-04-27');
        $report = $service->generate($start, $end, $agency->id, false)['reports'][0];
        $service->approve($report, 0, 'Approved');
        $service->markPaid($report, 'Paid offline');

        $this->expectException(\InvalidArgumentException::class);
        $service->generate($start, $end, $agency->id, true);
    }

    public function test_amounts_cannot_be_modified_after_approval(): void
    {
        [$agency] = $this->seedAgencyFixture();

        $service = app(AgencyWeeklyPayoutReportService::class);
        [$start, $end] = $service->resolvePeriod('2026-04-21', '2026-04-27');
        $report = $service->generate($start, $end, $agency->id, false)['reports'][0];
        $service->approve($report, 1, 'Approved');

        $this->expectException(\InvalidArgumentException::class);
        $service->markPendingReview($report, 5, 'Should fail');
    }

    public function test_invalid_generate_input_is_rejected(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)
            ->from(route('admin.agency-payout-reports.index'))
            ->post(route('admin.agency-payout-reports.generate'), [
                'start' => '2026-04-27',
                'end' => '2026-04-21',
            ]);

        $response->assertRedirect(route('admin.agency-payout-reports.index'));
        $response->assertSessionHasErrors(['generate']);

        $exitCode = Artisan::call('agency:payout-reports:generate', [
            '--start' => '2026-04-27',
            '--end' => '2026-04-21',
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_agency_export_and_dashboard_preview_are_properly_scoped(): void
    {
        [$agency, $owner] = $this->seedAgencyFixture();
        $otherOwner = User::factory()->create();
        $otherOwner->assignRole('agency');
        Agency::query()->create([
            'owner_user_id' => $otherOwner->id,
            'name' => 'Other Agency',
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $service = app(AgencyWeeklyPayoutReportService::class);
        [$start, $end] = $service->resolvePeriod('2026-04-21', '2026-04-27');
        $report = $service->generate($start, $end, $agency->id, false)['reports'][0];

        $service->approve($report, 0, 'Approved');
        $service->publish($report, 'Published', $admin);

        $this->actingAs($owner)
            ->get(route('agency.payout-reports.export', $report))
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->actingAs($otherOwner)
            ->get(route('agency.payout-reports.export', $report))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.agencies.dashboard', $agency))
            ->assertOk()
            ->assertSee('Agency Dashboard')
            ->assertSee('Orbit Agency');
    }

    public function test_admin_and_agency_views_are_scoped(): void
    {
        [$agency, $owner, $host] = $this->seedAgencyFixture();
        $otherOwner = User::factory()->create();
        $otherOwner->assignRole('agency');
        Agency::query()->create([
            'owner_user_id' => $otherOwner->id,
            'name' => 'Other Agency',
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $service = app(AgencyWeeklyPayoutReportService::class);
        [$start, $end] = $service->resolvePeriod('2026-04-21', '2026-04-27');
        $report = $service->generate($start, $end, $agency->id, false)['reports'][0];

        $this->actingAs($admin)
            ->get(route('admin.agency-payout-reports.index'))
            ->assertOk()
            ->assertSee('Weekly Agency Payout Reports');

        $this->actingAs($admin)
            ->get(route('admin.agency-payout-reports.show', $report))
            ->assertOk()
            ->assertSee('Host Settlement Grid')
            ->assertSee("User ID: {$host->user_id}");

        $this->actingAs($owner)
            ->get(route('agency.payout-reports.index'))
            ->assertOk()
            ->assertSee('Weekly Payout Reports');

        $service->approve($report, 0, 'Approved');
        $service->publish($report, 'Published', $admin);

        $this->actingAs($owner)
            ->get(route('agency.payout-reports.show', $report))
            ->assertOk()
            ->assertSee('Payout Report #' . $report->id);

        $this->actingAs($otherOwner)
            ->get(route('agency.payout-reports.show', $report))
            ->assertForbidden();
    }

    public function test_admin_can_delete_a_host_settlement_row(): void
    {
        [$agency, , $host] = $this->seedAgencyFixture();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $service = app(AgencyWeeklyPayoutReportService::class);
        [$start, $end] = $service->resolvePeriod('2026-04-21', '2026-04-27');
        $report = $service->generate($start, $end, $agency->id, false)['reports'][0];
        $item = $report->fresh('items')->items->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.agency-payout-reports.show', $report))
            ->assertOk()
            ->assertSee('Delete')
            ->assertSee(route('admin.agency-payout-reports.items.destroy', [$report, $item]), false);

        $this->actingAs($admin)
            ->delete(route('admin.agency-payout-reports.items.destroy', [$report, $item]))
            ->assertRedirect(route('admin.agency-payout-reports.show', $report));

        $this->assertDatabaseMissing('agency_payout_report_items', ['id' => $item->id]);
        $this->assertDatabaseHas('admin_action_audits', [
            'admin_user_id' => $admin->id,
            'target_user_id' => $host->user_id,
            'action' => 'delete_item',
            'entity_id' => $report->id,
        ]);
    }

    public function test_admin_can_transfer_host_settlement_coins_to_wallet_once(): void
    {
        [$agency, , $host] = $this->seedAgencyFixture();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $service = app(AgencyWeeklyPayoutReportService::class);
        [$start, $end] = $service->resolvePeriod('2026-04-21', '2026-04-27');
        $report = $service->generate($start, $end, $agency->id, false)['reports'][0];
        $item = $report->fresh('items')->items->firstOrFail();
        $itemMeta = $item->meta ?? [];
        $itemMeta['total_coins'] = 250;
        $item->forceFill(['meta' => $itemMeta])->save();
        $item->refresh();
        $coins = (int) $item->total_coins;
        $balanceBefore = (int) $host->user->wallet->balance;

        $this->actingAs($admin)
            ->get(route('admin.agency-payout-reports.show', $report))
            ->assertOk()
            ->assertSee('Transfer to Wallet')
            ->assertSee(route('admin.agency-payout-reports.items.transfer-to-wallet', [$report, $item]), false);

        $this->actingAs($admin)
            ->post(route('admin.agency-payout-reports.items.transfer-to-wallet', [$report, $item]))
            ->assertRedirect(route('admin.agency-payout-reports.show', $report));

        $item->refresh();
        $transactionId = (int) data_get($item->meta, 'wallet_transfer.transaction_id');
        $this->assertGreaterThan(0, $transactionId);
        $this->assertSame($coins, (int) data_get($item->meta, 'wallet_transfer.coins'));
        $this->assertStringContainsString("Transferred {$coins} coins to wallet.", $item->admin_note);
        $this->assertSame($balanceBefore + $coins, (int) $host->user->wallet()->value('balance'));
        $this->assertDatabaseHas('wallet_transactions', [
            'id' => $transactionId,
            'wallet_id' => $host->user->wallet->id,
            'type' => 'credit',
            'coins' => $coins,
            'category' => 'agency_payout',
            'reference' => 'AGENCY_PAYOUT_ITEM_WALLET_TRANSFER:'.$item->id,
            'reference_type' => \App\Models\AgencyPayoutReportItem::class,
            'reference_id' => $item->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.agency-payout-reports.items.transfer-to-wallet', [$report, $item]))
            ->assertSessionHasErrors('transfer_to_wallet');
        $this->assertSame($balanceBefore + $coins, (int) $host->user->wallet()->value('balance'));
        $this->assertSame(1, WalletTransaction::query()
            ->where('reference', 'AGENCY_PAYOUT_ITEM_WALLET_TRANSFER:'.$item->id)
            ->count());
    }

    private function seedAgencyFixture(): array
    {
        $owner = User::factory()->create(['name' => 'Agency Owner']);
        $owner->assignRole('agency');

        $agency = Agency::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Orbit Agency',
            'payout_percentage' => 10,
        ]);

        $hostUser = User::factory()->create(['name' => 'Host Nova']);
        $hostUser->assignRole('host');
        $host = Host::query()->create([
            'user_id' => $hostUser->id,
            'agency_id' => $agency->id,
            'stage_name' => 'Nova',
            'payout_percentage' => 60,
        ]);

        $caller = User::factory()->create();
        $call = CallSession::query()->create([
            'caller_id' => $caller->id,
            'receiver_id' => $hostUser->id,
            'host_id' => $host->id,
            'agency_id' => $agency->id,
            'type' => 'video',
            'status' => 'ended',
            'coin_rate_per_minute' => 20,
            'billable_minutes' => 5,
            'total_coins_charged' => 100,
            'host_earning' => 60,
            'agency_earning' => 10,
            'platform_earning' => 30,
        ]);

        CallEarningLedger::query()->create([
            'call_session_id' => $call->id,
            'caller_id' => $caller->id,
            'host_id' => $host->id,
            'agency_id' => $agency->id,
            'total_coins' => 100,
            'host_earning' => 60,
            'agency_earning' => 10,
            'platform_earning' => 30,
            'duration_seconds' => 300,
            'billable_minutes' => 5,
            'created_at' => '2026-04-23 10:00:00',
            'updated_at' => '2026-04-23 10:00:00',
        ]);

        $room = LiveRoom::query()->create([
            'host_id' => $host->id,
            'room_id' => 'orbit-room-1',
            'title' => 'Orbit Live',
            'room_type' => 'video',
            'status' => 'ended',
            'started_at' => '2026-04-23 09:00:00',
            'ended_at' => '2026-04-23 09:30:00',
            'last_activity_at' => '2026-04-23 09:30:00',
        ]);

        $gift = Gift::query()->create([
            'name' => 'Rose',
            'coins' => 50,
            'gift_url' => 'https://example.com/rose.png',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $sender = User::factory()->create();
        $walletTx = WalletTransaction::query()->create([
            'wallet_id' => $sender->wallet->id,
            'type' => 'debit',
            'coins' => 50,
            'category' => 'gift',
            'reference' => 'pk-gift-1',
            'description' => 'PK gift spend',
            'balance_before' => 100,
            'balance_after' => 50,
        ]);

        $roomGift = LiveRoomGift::query()->create([
            'live_room_id' => $room->id,
            'gift_id' => $gift->id,
            'sender_user_id' => $sender->id,
            'quantity' => 1,
            'coins_per_unit' => 50,
            'total_coins' => 50,
            'transaction_id' => (string) $walletTx->id,
            'meta' => ['reference' => 'pk-gift-1'],
        ]);

        LiveRoomGiftEarningLedger::query()->create([
            'live_room_gift_id' => $roomGift->id,
            'live_room_id' => $room->id,
            'sender_user_id' => $sender->id,
            'host_id' => $host->id,
            'agency_id' => $agency->id,
            'total_coins' => 50,
            'host_payout_percentage' => 60,
            'agency_payout_percentage' => 10,
            'host_payout_coins' => 30,
            'agency_payout_coins' => 5,
            'platform_revenue_coins' => 15,
            'created_at' => '2026-04-23 11:00:00',
            'updated_at' => '2026-04-23 11:00:00',
        ]);

        $otherOwner = User::factory()->create();
        $otherAgency = Agency::query()->create([
            'owner_user_id' => $otherOwner->id,
            'name' => 'Rival Agency',
        ]);
        $otherHostUser = User::factory()->create();
        $otherHost = Host::query()->create([
            'user_id' => $otherHostUser->id,
            'agency_id' => $otherAgency->id,
            'stage_name' => 'Rival',
        ]);
        $otherRoom = LiveRoom::query()->create([
            'host_id' => $otherHost->id,
            'room_id' => 'rival-room-1',
            'title' => 'Rival Live',
            'room_type' => 'video',
            'status' => 'ended',
            'started_at' => '2026-04-23 09:00:00',
            'ended_at' => '2026-04-23 09:30:00',
            'last_activity_at' => '2026-04-23 09:30:00',
        ]);

        $battle = LiveRoomPkBattle::query()->create([
            'battle_id' => 'pk-orbit-1',
            'room_a_id' => $room->id,
            'room_b_id' => $otherRoom->id,
            'host_a_id' => $host->id,
            'host_b_id' => $otherHost->id,
            'invited_by_host_id' => $host->id,
            'status' => 'ended',
            'duration_seconds' => 120,
            'score_a' => 50,
            'score_b' => 0,
            'started_at' => '2026-04-23 11:00:00',
            'ended_at' => '2026-04-23 11:02:00',
        ]);

        LiveRoomPkEvent::query()->create([
            'pk_battle_id' => $battle->id,
            'room_id' => $room->id,
            'user_id' => $sender->id,
            'event_type' => 'gift',
            'coins' => 50,
            'wallet_transaction_id' => $walletTx->id,
            'gift_id' => $gift->id,
            'metadata' => ['wallet_reference' => 'pk-gift-1'],
        ]);

        return [$agency, $owner, $host];
    }
}
