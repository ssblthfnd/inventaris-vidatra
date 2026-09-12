<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tahap 6.6 (H-1) — `POST /api/login` rate limiting. See
 * `AppServiceProvider::configureLoginRateLimiter()` for the dual
 * (email+IP: 5/min) + (IP-only: 20/min) design this exercises.
 *
 * `TestCase::setUp()` flushes the cache before every test (Tahap 6.6 — see its
 * own docblock) specifically so these tests never see hits left over from a
 * DIFFERENT test method/file that also happened to call `/api/login`.
 */
class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'operator@vidatra.test',
            'password' => Hash::make('correct-password-1'),
            'role' => UserRole::Operator,
            'is_active' => true,
        ], $overrides));
    }

    private function login(string $email, string $password, ?string $ip = null)
    {
        $request = $this->withHeader('Origin', 'http://localhost');
        if ($ip !== null) {
            $request = $request->withServerVariables(['REMOTE_ADDR' => $ip]);
        }

        return $request->postJson('/api/login', ['email' => $email, 'password' => $password]);
    }

    public function test_normal_login_still_works(): void
    {
        $this->makeUser();

        $this->login('operator@vidatra.test', 'correct-password-1')->assertOk();
    }

    public function test_repeated_failed_login_from_the_same_email_and_ip_eventually_returns_429(): void
    {
        $this->makeUser();

        for ($i = 1; $i <= 5; $i++) {
            $response = $this->login('operator@vidatra.test', 'wrong-guess', '203.0.113.10');
            $this->assertNotSame(429, $response->getStatusCode(), "Attempt {$i} was throttled too early.");
        }

        $response = $this->login('operator@vidatra.test', 'wrong-guess', '203.0.113.10');
        $response->assertStatus(429);
    }

    public function test_a_correct_password_still_succeeds_after_a_few_failed_attempts_under_the_limit(): void
    {
        $this->makeUser();

        for ($i = 1; $i <= 3; $i++) {
            $this->login('operator@vidatra.test', 'wrong-guess', '203.0.113.20')->assertStatus(422);
        }

        $this->login('operator@vidatra.test', 'correct-password-1', '203.0.113.20')->assertOk();
    }

    public function test_429_response_does_not_reveal_whether_the_email_exists(): void
    {
        $this->makeUser();

        // Exhaust the limit against a KNOWN email...
        for ($i = 1; $i <= 5; $i++) {
            $this->login('operator@vidatra.test', 'wrong-guess', '203.0.113.30');
        }
        $known = $this->login('operator@vidatra.test', 'wrong-guess', '203.0.113.30');
        $known->assertStatus(429);

        // ...and against an email that doesn't exist at all, from a fresh IP so
        // only the email+IP limiter (not the IP-wide one) is what trips here.
        for ($i = 1; $i <= 5; $i++) {
            $this->login('nobody-here@vidatra.test', 'wrong-guess', '203.0.113.31');
        }
        $unknown = $this->login('nobody-here@vidatra.test', 'wrong-guess', '203.0.113.31');
        $unknown->assertStatus(429);

        // Same status, and neither body leaks anything about account existence —
        // Laravel's default throttle response carries only a generic message.
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertArrayNotHasKey('errors', $known->json());
        $this->assertArrayNotHasKey('errors', $unknown->json());
    }

    public function test_different_ips_get_independent_email_ip_buckets(): void
    {
        $this->makeUser();

        // Exhaust the (email + IP-A) bucket...
        for ($i = 1; $i <= 5; $i++) {
            $this->login('operator@vidatra.test', 'wrong-guess', '198.51.100.1');
        }
        $this->login('operator@vidatra.test', 'wrong-guess', '198.51.100.1')->assertStatus(429);

        // ...a DIFFERENT IP trying the SAME email is on its own separate
        // (email + IP-B) bucket and is not blocked by IP-A's exhaustion.
        $this->login('operator@vidatra.test', 'correct-password-1', '198.51.100.2')->assertOk();
    }

    public function test_one_ip_cannot_bypass_the_email_limit_by_spraying_many_different_emails(): void
    {
        // 20 attempts against 20 DISTINCT nonexistent emails from one IP — none
        // of them individually reaches the per-(email+IP) limit of 5, but the
        // per-IP-only limit of 20 is what should trip.
        for ($i = 1; $i <= 20; $i++) {
            $response = $this->login("nobody-{$i}@vidatra.test", 'guess', '192.0.2.50');
            $this->assertNotSame(429, $response->getStatusCode(), "Attempt {$i} was throttled too early.");
        }

        $this->login('nobody-21@vidatra.test', 'guess', '192.0.2.50')->assertStatus(429);
    }

    public function test_viewer_operator_and_admin_accounts_are_all_subject_to_the_login_limiter(): void
    {
        $accounts = [
            'viewer@vidatra.test' => UserRole::Viewer,
            'operator2@vidatra.test' => UserRole::Operator,
            'admin@vidatra.test' => UserRole::Admin,
        ];

        foreach ($accounts as $email => $role) {
            $this->makeUser(['email' => $email, 'role' => $role]);
            $ip = '203.0.113.'.random_int(100, 200);

            for ($i = 1; $i <= 5; $i++) {
                $this->login($email, 'wrong-guess', $ip);
            }

            $this->login($email, 'wrong-guess', $ip)
                ->assertStatus(429, "Login limiter did not apply to role [{$role->value}].");
        }
    }
}
