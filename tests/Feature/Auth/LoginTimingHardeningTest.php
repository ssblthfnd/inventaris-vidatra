<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Api\AuthController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use ReflectionClassConstant;
use Tests\TestCase;

/**
 * Tahap 6.9 R8.2 (P3-1) — login timing side-channel hardening.
 *
 * `AuthController::login()` used to call `Hash::check()` only when the email
 * lookup succeeded, so a nonexistent-email request returned as soon as the DB
 * query resolved while a real-email-wrong-password request paid the full bcrypt
 * cost — a timing oracle for account enumeration. The fix always calls
 * `Hash::check()`, against the real user's hash when one exists or a constant
 * precomputed dummy hash otherwise.
 *
 * Per this stage's explicit instruction, this is a CODE-PATH hardening proof
 * (Hash::check() is actually invoked, and invoked against the intended hash),
 * not a statistical timing benchmark — PHPUnit cannot reliably assert timing
 * equality, and no attempt is made to here.
 */
class LoginTimingHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function dummyHash(): string
    {
        return (new ReflectionClassConstant(AuthController::class, 'DUMMY_PASSWORD_HASH'))->getValue();
    }

    public function test_nonexistent_email_still_invokes_hash_check_against_the_dummy_hash(): void
    {
        Hash::spy();

        $this->postJson('/api/login', ['email' => 'nobody-at-all@vidatra.test', 'password' => 'whatever-guess'])
            ->assertStatus(422);

        Hash::shouldHaveReceived('check')->once()->with('whatever-guess', $this->dummyHash());
    }

    public function test_existing_user_wrong_password_invokes_hash_check_against_their_real_hash(): void
    {
        $user = User::factory()->create([
            'email' => 'real-user@vidatra.test',
            'password' => Hash::make('the-real-password'),
            'role' => UserRole::Operator,
            'is_active' => true,
        ]);

        Hash::spy();

        $this->postJson('/api/login', ['email' => 'real-user@vidatra.test', 'password' => 'wrong-guess'])
            ->assertStatus(422);

        Hash::shouldHaveReceived('check')->once()->with('wrong-guess', $user->password);
    }

    public function test_dummy_hash_is_never_a_real_users_password_hash(): void
    {
        $user = User::factory()->create([
            'email' => 'another-real-user@vidatra.test',
            'password' => Hash::make('some-password'),
        ]);

        $this->assertNotSame($this->dummyHash(), $user->password);
    }

    public function test_nonexistent_email_and_wrong_password_return_the_exact_same_error(): void
    {
        User::factory()->create([
            'email' => 'existing@vidatra.test',
            'password' => Hash::make('correct-password'),
            'role' => UserRole::Operator,
            'is_active' => true,
        ]);

        $unknown = $this->postJson('/api/login', ['email' => 'unknown@vidatra.test', 'password' => 'guess']);
        $wrong = $this->postJson('/api/login', ['email' => 'existing@vidatra.test', 'password' => 'guess']);

        $unknown->assertStatus(422);
        $wrong->assertStatus(422);
        $this->assertSame($unknown->json('errors.email'), $wrong->json('errors.email'));
    }

    public function test_correct_password_for_an_existing_active_user_still_logs_in(): void
    {
        User::factory()->create([
            'email' => 'happy-path@vidatra.test',
            'password' => Hash::make('correct-password-1'),
            'role' => UserRole::Operator,
            'is_active' => true,
        ]);

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/login', ['email' => 'happy-path@vidatra.test', 'password' => 'correct-password-1'])
            ->assertOk();
    }

    public function test_rate_limiting_still_applies_to_nonexistent_email_attempts(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
                ->postJson('/api/login', ['email' => 'nobody-77@vidatra.test', 'password' => 'guess']);
            $this->assertNotSame(429, $response->getStatusCode(), "Attempt {$i} was throttled too early.");
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
            ->postJson('/api/login', ['email' => 'nobody-77@vidatra.test', 'password' => 'guess'])
            ->assertStatus(429);
    }
}
