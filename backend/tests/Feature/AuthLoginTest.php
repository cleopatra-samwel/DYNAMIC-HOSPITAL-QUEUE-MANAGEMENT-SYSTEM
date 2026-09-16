<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 10 hardening — the login endpoint must not let a caller
 * distinguish "no such email" from "wrong password" from "deactivated
 * account" by the error message returned. A distinct "deactivated"
 * message would leak which emails exist but are merely disabled. All
 * three rejection reasons collapse to the exact same generic message
 * (see AuthController::login()) — a deactivated user is expected to
 * learn why through a separate support channel, not this response.
 */
class AuthLoginTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_MESSAGE = 'Invalid credentials.';

    public function test_nonexistent_email_returns_the_generic_message(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nobody-here@verify.local',
            'password' => 'whatever',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.email.0', self::GENERIC_MESSAGE);
    }

    public function test_wrong_password_for_an_existing_active_user_returns_the_generic_message(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'definitely-not-the-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.email.0', self::GENERIC_MESSAGE);
    }

    public function test_correct_credentials_for_a_deactivated_account_returns_the_same_generic_message(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.email.0', self::GENERIC_MESSAGE);
    }

    public function test_the_three_rejection_reasons_are_textually_indistinguishable(): void
    {
        $deactivated = User::factory()->create(['is_active' => false]);
        $active = User::factory()->create(['is_active' => true]);

        $noSuchEmail = $this->postJson('/api/auth/login', [
            'email' => 'nobody-here@verify.local',
            'password' => 'whatever',
        ])->json('errors.email.0');

        $wrongPassword = $this->postJson('/api/auth/login', [
            'email' => $active->email,
            'password' => 'wrong',
        ])->json('errors.email.0');

        $deactivatedAccount = $this->postJson('/api/auth/login', [
            'email' => $deactivated->email,
            'password' => 'password',
        ])->json('errors.email.0');

        $this->assertSame(self::GENERIC_MESSAGE, $noSuchEmail);
        $this->assertSame(self::GENERIC_MESSAGE, $wrongPassword);
        $this->assertSame(self::GENERIC_MESSAGE, $deactivatedAccount);
    }

    public function test_correct_credentials_for_an_active_user_still_logs_in(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['token', 'user']);
    }
}
