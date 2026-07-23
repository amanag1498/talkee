<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FirebaseAuthResilienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_transient_firebase_timeout_is_retried_and_returned_as_service_unavailable(): void
    {
        $auth = Mockery::mock(FirebaseAuth::class);
        $auth->shouldReceive('verifyIdToken')
            ->twice()
            ->andThrow(new RuntimeException('cURL error 28: Operation timed out'));
        $this->app->instance(FirebaseAuth::class, $auth);

        $this->postJson('/api/auth/firebase/login', [
            'idToken' => 'header.payload.signature',
            'device_name' => 'test-client',
        ])->assertStatus(503)
            ->assertJsonPath('code', 'firebase_temporarily_unavailable');
    }

    public function test_invalid_firebase_token_is_not_retried(): void
    {
        $auth = Mockery::mock(FirebaseAuth::class);
        $auth->shouldReceive('verifyIdToken')
            ->once()
            ->andThrow(new RuntimeException('The token is invalid'));
        $this->app->instance(FirebaseAuth::class, $auth);

        $this->postJson('/api/auth/firebase/login', [
            'idToken' => 'header.payload.signature',
            'device_name' => 'test-client',
        ])->assertStatus(401)
            ->assertJsonPath('code', 'firebase_token_invalid');
    }
}
