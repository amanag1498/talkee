<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\EntryPack;
use App\Models\Host;
use App\Models\HostAvailability;
use App\Models\UserEntryPack;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class DevBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        $admin = $this->makeUser(
            env('SEED_ADMIN_EMAIL', 'admin@example.com'),
            env('SEED_ADMIN_NAME', 'Platform Admin'),
            ['admin']
        );

        $alwaysAdmin = $this->ensureAdminUser(
            env('SEED_ALWAYS_ADMIN_EMAIL', 'amanagarwal1498@gmail.com'),
            env('SEED_ALWAYS_ADMIN_NAME', 'Aman Agarwal')
        );

        $agencyOwner = $this->makeUser('agency@example.com', 'Agency Owner', ['agency']);
        $agency = Agency::query()->updateOrCreate(
            ['owner_user_id' => $agencyOwner->id],
            [
                'name' => 'Prime Talent Agency',
                'legal_name' => 'Prime Talent Agency LLP',
                'contact_email' => 'agency@example.com',
                'contact_phone' => '+91-9000000001',
                'notes' => 'Seeded development agency account',
                'is_blocked' => false,
                'payout_percentage' => 10,
                'weekly_bonus' => 500,
            ]
        );

        $hostUser = $this->makeUser('host@example.com', 'Seed Host', ['host']);
        $secondHostUser = $this->makeUser('host2@example.com', 'Seed Host Two', ['host']);
        $viewer = $this->makeUser('viewer@example.com', 'Seed Viewer', ['user']);
        $secondViewer = $this->makeUser('viewer2@example.com', 'Backup Viewer', ['user']);

        Host::query()->updateOrCreate(
            ['user_id' => $hostUser->id],
            [
                'agency_id' => $agency->id,
                'stage_name' => 'Host Nova',
                'contact_phone' => '+91-9000000002',
                'country' => 'India',
                'city' => 'Mumbai',
                'bio' => 'Seeded host profile for realtime and call testing.',
                'kyc' => ['status' => 'approved', 'source' => 'seed'],
                'is_blocked' => false,
                'payout_percentage' => 60,
                'weekly_bonus' => 200,
            ]
        );

        Host::query()->updateOrCreate(
            ['user_id' => $secondHostUser->id],
            [
                'agency_id' => $agency->id,
                'stage_name' => 'Host Orbit',
                'contact_phone' => '+91-9000000003',
                'country' => 'India',
                'city' => 'Delhi',
                'bio' => 'Second seeded host profile for agency room and PK testing.',
                'kyc' => ['status' => 'approved', 'source' => 'seed'],
                'is_blocked' => false,
                'payout_percentage' => 55,
                'weekly_bonus' => 150,
            ]
        );

        HostAvailability::query()->updateOrCreate(
            ['user_id' => $hostUser->id],
            [
                'manual_status' => 'online',
                'socket_status' => 'offline',
                'call_status' => 'available',
                'current_call_session_id' => null,
                'last_seen_at' => now(),
            ]
        );

        HostAvailability::query()->updateOrCreate(
            ['user_id' => $secondHostUser->id],
            [
                'manual_status' => 'online',
                'socket_status' => 'offline',
                'call_status' => 'available',
                'current_call_session_id' => null,
                'last_seen_at' => now(),
            ]
        );

        $this->seedWallet($admin, 2000, 'seed_admin');
        if ($alwaysAdmin->id !== $admin->id) {
            $this->seedWallet($alwaysAdmin, 2000, 'seed_always_admin');
        }
        $this->seedWallet($agencyOwner, 1500, 'seed_agency_owner');
        $this->seedWallet($hostUser, 800, 'seed_host');
        $this->seedWallet($secondHostUser, 900, 'seed_host_two');
        $this->seedWallet($viewer, 2500, 'seed_viewer');
        $this->seedWallet($secondViewer, 1200, 'seed_viewer');

        $defaultPack = EntryPack::query()->where('is_active', true)->orderByDesc('priority')->first();
        if ($defaultPack) {
            UserEntryPack::query()->updateOrCreate(
                ['user_id' => $viewer->id, 'entry_pack_id' => $defaultPack->id],
                [
                    'is_active' => true,
                    'purchased_at' => now()->subMinutes(5),
                    'expires_at' => null,
                    'purchase_key' => 'seed-viewer-entry-pack',
                ]
            );
        }
    }

    private function makeUser(string $email, string $name, array $roles): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password'),
                'provider' => 'seed',
                'email_verified_at' => now(),
                'is_blocked' => false,
            ]
        );

        try {
            $user->syncRoles($roles);
        } catch (\Throwable $e) {
            Log::warning('DEV_BOOTSTRAP_ROLE_SYNC_FAIL', [
                'user_id' => $user->id,
                'roles' => $roles,
                'error' => $e->getMessage(),
            ]);
        }

        return $user;
    }

    private function ensureAdminUser(string $email, string $name): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password'),
                'provider' => 'seed',
                'email_verified_at' => now(),
                'is_blocked' => false,
            ]
        );

        try {
            $user->assignRole('admin');
        } catch (\Throwable $e) {
            Log::warning('DEV_BOOTSTRAP_ALWAYS_ADMIN_ASSIGN_FAIL', [
                'user_id' => $user->id,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }

        return $user;
    }

    private function seedWallet(User $user, int $targetBalance, string $reference): void
    {
        $wallet = WalletService::getOrCreate($user);
        $currentBalance = (int) $wallet->balance;

        if ($currentBalance === $targetBalance) {
            return;
        }

        if ($currentBalance < $targetBalance) {
            WalletService::credit($user, $targetBalance - $currentBalance, $reference, [
                'note' => 'Development bootstrap seed credit',
            ]);
            return;
        }

        WalletService::debit($user, $currentBalance - $targetBalance, $reference, [
            'note' => 'Development bootstrap seed debit',
        ]);
    }
}
