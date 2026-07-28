<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminWalletTransactionLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin', 'web');
        config(['app.timezone' => 'Asia/Kolkata']);
    }

    public function test_admin_can_view_the_global_ledger_and_filtered_totals(): void
    {
        $admin = $this->admin();
        [$aman, $amanWallet] = $this->userWithWallet('Aman Ledger', 'aman-ledger@example.com', 1400);
        [, $otherWallet] = $this->userWithWallet('Other User', 'other-ledger@example.com', 200);

        $creditId = $this->insertTransaction($amanWallet, [
            'type' => 'credit',
            'coins' => 500,
            'category' => 'recharge',
            'reference' => 'payment_order:101',
            'balance_before' => 900,
            'balance_after' => 1400,
            'amount' => 99,
            'currency' => 'INR',
        ]);
        $this->insertTransaction($amanWallet, [
            'type' => 'debit',
            'coins' => 100,
            'category' => 'gift',
            'reference' => 'ROOM_GIFT:1',
            'balance_before' => 1400,
            'balance_after' => 1300,
        ]);
        $this->insertTransaction($otherWallet, [
            'type' => 'credit',
            'coins' => 200,
            'category' => 'adjustment',
            'reference' => 'other-entry',
            'balance_before' => 0,
            'balance_after' => 200,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.wallet-transactions.index', [
            'user_id' => $aman->id,
            'type' => 'credit',
        ]));

        $response->assertOk()
            ->assertSee('Platform Transaction Ledger')
            ->assertSee('payment_order:101')
            ->assertSee('Ledger #'.$creditId)
            ->assertDontSee('ROOM_GIFT:1')
            ->assertDontSee('other-entry');

        $summary = $response->viewData('summary');
        $this->assertSame(1, (int) $summary->transaction_count);
        $this->assertSame(500, (int) $summary->credit_coins);
        $this->assertSame(0, (int) $summary->debit_coins);
        $this->assertSame(1, (int) $summary->wallet_count);
        $this->assertSame(0, (int) $summary->anomaly_count);
    }

    public function test_full_to_date_includes_late_activity_in_the_configured_timezone(): void
    {
        $admin = $this->admin();
        [, $wallet] = $this->userWithWallet('Sunday User', 'sunday@example.com', 50);

        $this->insertTransaction($wallet, [
            'type' => 'credit',
            'coins' => 50,
            'category' => 'adjustment',
            'reference' => 'sunday-late',
            'balance_before' => 0,
            'balance_after' => 50,
            'created_at' => CarbonImmutable::parse('2026-07-26 23:45:00', 'Asia/Kolkata'),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.wallet-transactions.index', ['from' => '2026-07-26', 'to' => '2026-07-26']))
            ->assertOk()
            ->assertSee('sunday-late');
    }

    public function test_admin_can_audit_an_integrity_mismatch_and_metadata(): void
    {
        $admin = $this->admin();
        [, $wallet] = $this->userWithWallet('Audit User', 'audit@example.com', 700);
        $transactionId = $this->insertTransaction($wallet, [
            'type' => 'debit',
            'coins' => 200,
            'category' => 'video_call',
            'reference' => 'call_billing:88:2',
            'balance_before' => 1000,
            'balance_after' => 700,
            'description' => 'Two billed minutes',
            'meta' => json_encode(['call_session_id' => 88, 'billable_minutes' => 2]),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.wallet-transactions.show', $transactionId))
            ->assertOk()
            ->assertSee('does not reconcile')
            ->assertSee('Expected 800')
            ->assertSee('call_billing:88:2')
            ->assertSee('Billable Minutes')
            ->assertSee('Two billed minutes');
    }

    public function test_csv_export_respects_filters_and_non_admins_are_denied(): void
    {
        $admin = $this->admin();
        [$user, $wallet] = $this->userWithWallet('Export User', 'export-ledger@example.com', 400);
        $this->insertTransaction($wallet, [
            'type' => 'credit',
            'coins' => 400,
            'category' => 'recharge',
            'reference' => 'export-me',
            'balance_before' => 0,
            'balance_after' => 400,
        ]);
        $this->insertTransaction($wallet, [
            'type' => 'debit',
            'coins' => 50,
            'category' => 'gift',
            'reference' => 'do-not-export',
            'balance_before' => 400,
            'balance_after' => 350,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.wallet-transactions.export', [
            'user_id' => $user->id,
            'type' => 'credit',
        ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString('export-me', $csv);
        $this->assertStringContainsString('export-ledger@example.com', $csv);
        $this->assertStringNotContainsString('do-not-export', $csv);

        $regularUser = User::factory()->create();
        $this->actingAs($regularUser)
            ->get(route('admin.wallet-transactions.index'))
            ->assertForbidden();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function userWithWallet(string $name, string $email, int $balance): array
    {
        $user = User::factory()->create(compact('name', 'email'));
        $wallet = Wallet::query()->where('user_id', $user->id)->firstOrFail();
        $wallet->update(['balance' => $balance]);

        return [$user, $wallet];
    }

    private function insertTransaction(Wallet $wallet, array $attributes): int
    {
        $now = $attributes['created_at'] ?? now();

        return DB::table('wallet_transactions')->insertGetId(array_merge([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'coins' => 0,
            'amount' => null,
            'currency' => null,
            'category' => 'adjustment',
            'reference' => null,
            'transaction_id' => null,
            'gateway' => null,
            'counterparty_user_id' => null,
            'meta' => null,
            'reference_type' => null,
            'reference_id' => null,
            'description' => null,
            'balance_before' => null,
            'balance_after' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $attributes));
    }
}
