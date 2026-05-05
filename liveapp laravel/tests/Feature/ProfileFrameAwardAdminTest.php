<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Host;
use App\Models\ProfileFrame;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProfileFrameAwardAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_admin_can_run_weekly_and_all_time_profile_frame_awards(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-11 10:00:00', 'Asia/Kolkata'));

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $weeklyUser = User::factory()->create(['name' => 'Weekly Gifter', 'lifetime_spend_coins' => 500]);
        $allTimeUser = User::factory()->create(['name' => 'All Time Gifter', 'lifetime_spend_coins' => 9999999]);
        $weeklyHostUser = User::factory()->create(['name' => 'Weekly Host']);
        $allTimeHostUser = User::factory()->create(['name' => 'All Time Host']);
        $weeklyAgencyOwner = User::factory()->create(['name' => 'Weekly Agency Owner']);
        $allTimeAgencyOwner = User::factory()->create(['name' => 'All Time Agency Owner']);

        $weeklyAgency = Agency::query()->create([
            'owner_user_id' => $weeklyAgencyOwner->id,
            'name' => 'Weekly Agency',
        ]);
        $allTimeAgency = Agency::query()->create([
            'owner_user_id' => $allTimeAgencyOwner->id,
            'name' => 'All Time Agency',
        ]);

        $weeklyHost = Host::query()->create([
            'user_id' => $weeklyHostUser->id,
            'stage_name' => 'Weekly Host Stage',
        ]);
        $allTimeHost = Host::query()->create([
            'user_id' => $allTimeHostUser->id,
            'stage_name' => 'All Time Host Stage',
        ]);

        $previousWeekStart = now('Asia/Kolkata')->copy()->subWeek()->startOfWeek(Carbon::MONDAY);
        $previousWeekMid = $previousWeekStart->copy()->addDays(2);
        $oldDay = $previousWeekStart->copy()->subWeeks(6);

        $this->insertLeaderboardRow('user', $weeklyUser->id, $previousWeekMid->toDateString(), 1800);
        $this->insertLeaderboardRow('host', $weeklyHost->id, $previousWeekMid->toDateString(), 2400);
        $this->insertLeaderboardRow('agency', $weeklyAgency->id, $previousWeekMid->toDateString(), 2600);

        $this->insertLeaderboardRow('host', $allTimeHost->id, $oldDay->toDateString(), 9000);
        $this->insertLeaderboardRow('agency', $allTimeAgency->id, $oldDay->toDateString(), 12000);

        $this->actingAs($admin)
            ->post(route('admin.profile-frames.awards.run'))
            ->assertRedirect(route('admin.profile-frames.index'));

        $this->assertAwardGranted($weeklyUser, 'blush-laurel-crown', 'weekly_top_gifter');
        $this->assertAwardGranted($weeklyHostUser, 'scarlet-regal-crown', 'weekly_top_host');
        $this->assertAwardGranted($weeklyAgencyOwner, 'silver-sapphire-crown', 'weekly_top_agency');
        $this->assertAwardGranted($allTimeUser, 'silver-amethyst-crown', 'alltime_top_gifter');
        $this->assertAwardGranted($allTimeHostUser, 'obsidian-crown-laurel', 'alltime_top_host');
        $this->assertAwardGranted($allTimeAgencyOwner, 'ivory-laurel-crown', 'alltime_top_agency');

        $this->assertSame(6, UserNotification::query()->where('type', 'profile_frame_unlocked')->count());
    }

    private function insertLeaderboardRow(string $subjectType, int $subjectId, string $statDate, int $totalCoins): void
    {
        DB::table('leaderboard_daily_stats')->insert([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'stat_date' => $statDate,
            'gift_coins' => $totalCoins,
            'call_coins' => 0,
            'subscription_coins' => 0,
            'entry_coins' => 0,
            'total_coins' => $totalCoins,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertAwardGranted(User $user, string $frameSlug, string $source): void
    {
        $frame = ProfileFrame::query()->where('slug', $frameSlug)->firstOrFail();

        $this->assertDatabaseHas('user_profile_frames', [
            'user_id' => $user->id,
            'profile_frame_id' => $frame->id,
            'source' => $source,
            'is_equipped' => true,
        ]);

        $notification = UserNotification::query()
            ->where('user_id', $user->id)
            ->where('type', 'profile_frame_unlocked')
            ->latest('id')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame($frameSlug, data_get($notification->meta, 'profile_frame.slug'));
    }
}
