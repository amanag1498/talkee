<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AppSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DemoAppSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_admin_page_exposes_demo_controls(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.settings.app.edit'))
            ->assertOk()
            ->assertSee('Demo Account Login')
            ->assertSee('Demo Account Email')
            ->assertSee('type="email"', false);
    }

    public function test_admin_can_enable_demo_login_for_an_existing_user(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $demoUser = User::factory()->create(['email' => 'Reviewer@talkieo.test']);

        $payload = $this->validAppSettingsPayload([
            'app_features.demo_login_enabled' => 1,
            'app_features.demo_login_email' => 'reviewer@talkieo.test',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.settings.app.update'), $payload)
            ->assertRedirect(route('admin.settings.app.edit'));

        $this->assertDatabaseHas('app_settings', [
            'key' => 'app_features.demo_login_enabled',
            'value' => '1',
        ]);
        $this->assertDatabaseHas('app_settings', [
            'key' => 'app_features.demo_login_email',
            'value' => $demoUser->email,
        ]);
    }

    public function test_admin_cannot_enable_demo_login_without_an_existing_user(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $payload = $this->validAppSettingsPayload([
            'app_features.demo_login_enabled' => 1,
            'app_features.demo_login_email' => 'missing@talkieo.test',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.settings.app.edit'))
            ->put(route('admin.settings.app.update'), $payload)
            ->assertRedirect(route('admin.settings.app.edit'))
            ->assertSessionHasErrors('app_features.demo_login_email');
    }

    public function test_public_app_config_exposes_only_the_demo_enable_flag(): void
    {
        config([
            'app_features.demo_login_enabled' => true,
            'app_features.demo_login_email' => 'private-reviewer@talkieo.test',
        ]);

        $this->getJson('/api/app-config')
            ->assertOk()
            ->assertJsonPath('data.demo_login_enabled', true)
            ->assertJsonMissingPath('data.demo_login_email');
    }

    private function validAppSettingsPayload(array $overrides = []): array
    {
        $payload = [];
        foreach (AppSettingsService::APP_DEFINITIONS as $key => $definition) {
            data_set($payload, $key, $definition['default']);
        }
        foreach ($overrides as $key => $value) {
            data_set($payload, $key, $value);
        }

        return $payload;
    }
}
