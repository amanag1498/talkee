<?php

namespace Tests\Feature;

use App\Models\Host;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountDeletionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('user', 'web');
        Role::findOrCreate('host', 'web');
    }

    public function test_authenticated_user_can_delete_account_in_app(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/account-avatar.jpg', 'avatar');
        Storage::disk('public')->put('hosts/account-photo.jpg', 'photo');

        $user = User::factory()->create([
            'name' => 'Delete Me',
            'email' => 'delete-me@example.com',
            'firebase_uid' => 'firebase-delete-me',
            'avatar_url' => 'avatars/account-avatar.jpg',
            'provider' => 'apple',
            'device_id' => 'ios:private-device-id',
        ]);
        $user->assignRole(['user', 'host']);
        $user->createToken('existing-device');
        $host = Host::query()->create([
            'user_id' => $user->id,
            'stage_name' => 'Delete Host',
            'contact_phone' => '9999999999',
            'bio' => 'Private host bio',
            'kyc' => ['document' => 'private'],
        ]);
        $host->photos()->create(['path' => 'hosts/account-photo.jpg', 'sort' => 0]);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/account')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $user->refresh();
        $host->refresh();

        $this->assertSame('Deleted User', $user->name);
        $this->assertStringStartsWith('deleted-'.$user->id.'-', $user->email);
        $this->assertNull($user->firebase_uid);
        $this->assertNull($user->avatar_url);
        $this->assertNull($user->device_id);
        $this->assertTrue($user->is_blocked);
        $this->assertSame('deleted', $user->provider);
        $this->assertCount(0, $user->fresh()->roles);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
        ]);
        $this->assertSame('Deleted Host', $host->stage_name);
        $this->assertNull($host->contact_phone);
        $this->assertNull($host->kyc);
        $this->assertDatabaseMissing('host_photos', ['host_id' => $host->id]);
        Storage::disk('public')->assertMissing('avatars/account-avatar.jpg');
        Storage::disk('public')->assertMissing('hosts/account-photo.jpg');
    }

    public function test_account_deletion_requires_authentication(): void
    {
        $this->deleteJson('/api/account')->assertUnauthorized();
    }
}
