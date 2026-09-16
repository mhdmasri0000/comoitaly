<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailOtpVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role = 'user', bool $verified = false): User
    {
        static $counter = 0;
        $counter++;

        return User::create([
            'id' => (string) Str::uuid(),
            'first_name' => ucfirst($role),
            'last_name' => 'User',
            'email' => $role.$counter.'@example.com',
            'phone_number' => '0550000'.str_pad((string) $counter, 3, '0', STR_PAD_LEFT),
            'password_hash' => Hash::make('1234567890'),
            'role' => $role,
            'email_verified_at' => $verified ? now() : null,
        ]);
    }

    public function test_register_sends_otp_and_requires_verification(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Ali',
            'last_name' => 'Hassan',
            'email' => 'new.customer@example.com',
            'phone_number' => '0934128426',
            'password' => '1234567890',
            'confirm_password' => '1234567890',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.requires_verification', true);
        $response->assertJsonPath('data.email', 'new.customer@example.com');

        $this->assertDatabaseHas('email_otps', ['email' => 'new.customer@example.com', 'purpose' => 'register', 'used_at' => null]);

        Mail::assertSent(OtpMail::class, function (OtpMail $mail) {
            return strlen($mail->code) === 6 && ctype_digit($mail->code);
        });
    }

    public function test_login_is_blocked_until_email_is_verified(): void
    {
        $user = $this->makeUser('user');

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => '1234567890',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('data.requires_verification', true);
    }

    public function test_dealer_can_login_without_email_verification(): void
    {
        $dealer = $this->makeUser('dealer');

        $response = $this->postJson('/api/auth/login', [
            'email' => $dealer->email,
            'password' => '1234567890',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.role', 'dealer');
        $response->assertJsonPath('data.access_token', fn ($token) => is_string($token) && $token !== '');
    }

    public function test_otp_verification_is_skipped_when_disabled(): void
    {
        config(['app.otp_enabled' => false]);
        Mail::fake();

        $register = $this->postJson('/api/auth/register', [
            'first_name' => 'Nour',
            'last_name' => 'Hassan',
            'email' => 'skip.otp@example.com',
            'phone_number' => '0934129999',
            'password' => '1234567890',
            'confirm_password' => '1234567890',
        ]);

        $register->assertOk();
        $register->assertJsonPath('data.requires_verification', false);
        $register->assertJsonPath('data.access_token', fn ($token) => is_string($token) && $token !== '');

        $this->assertNotNull(User::where('email', 'skip.otp@example.com')->first()->email_verified_at);
        Mail::assertNothingSent();

        $unverified = $this->makeUser('user');

        $this->postJson('/api/auth/login', [
            'email' => $unverified->email,
            'password' => '1234567890',
        ])->assertOk();
    }

    public function test_verify_otp_activates_account_and_returns_token(): void
    {
        $user = $this->makeUser('user');
        $code = app(OtpService::class)->issue($user->email, $user->id, 'register');

        $response = $this->postJson('/api/auth/verify-otp', [
            'email' => $user->email,
            'otp' => $code,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.role', 'user');
        $response->assertJsonPath('data.access_token', fn ($token) => is_string($token) && $token !== '');

        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => '1234567890',
        ])->assertOk();
    }

    public function test_wrong_otp_is_rejected(): void
    {
        $user = $this->makeUser('user');
        $code = app(OtpService::class)->issue($user->email, $user->id, 'register');
        $wrong = $code === '000001' ? '000002' : '000001';

        $this->postJson('/api/auth/verify-otp', [
            'email' => $user->email,
            'otp' => $wrong,
        ])->assertStatus(400);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_resend_otp_invalidates_previous_code_and_sends_new_one(): void
    {
        $user = $this->makeUser('user');
        $oldCode = app(OtpService::class)->issue($user->email, $user->id, 'register');

        Mail::fake();

        $this->postJson('/api/auth/resend-otp', ['email' => $user->email])->assertOk();

        Mail::assertSent(OtpMail::class);
        $this->assertSame(1, \App\Models\EmailOtp::where('email', $user->email)->whereNull('used_at')->count());

        // The previous code no longer works (was invalidated).
        $this->postJson('/api/auth/verify-otp', ['email' => $user->email, 'otp' => $oldCode])
            ->assertStatus(400);
    }

    public function test_register_accepts_only_syrian_phone_formats(): void
    {
        Mail::fake();

        $base = [
            'first_name' => 'Ali',
            'last_name' => 'Hassan',
            'password' => '1234567890',
            'confirm_password' => '1234567890',
        ];

        // Local format: 09XXXXXXXX
        $this->postJson('/api/auth/register', $base + [
            'email' => 'local.phone@example.com',
            'phone_number' => '0934128426',
        ])->assertCreated();

        // Country-code format: +9639XXXXXXXX
        $this->postJson('/api/auth/register', $base + [
            'email' => 'intl.phone@example.com',
            'phone_number' => '+963934128426',
        ])->assertCreated();

        // Invalid
        $this->postJson('/api/auth/register', $base + [
            'email' => 'bad.phone@example.com',
            'phone_number' => '12345',
        ])->assertStatus(400)->assertJsonPath('errors.0.path', 'phone_number');
    }
}
