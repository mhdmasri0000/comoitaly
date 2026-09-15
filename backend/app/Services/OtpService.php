<?php

namespace App\Services;

use App\Models\EmailOtp;

class OtpService
{
    public const MAX_ATTEMPTS = 5;

    public const TTL_MINUTES = 10;

    /**
     * Generate a fresh 6-digit OTP, invalidating any previous unused one.
     */
    public function issue(string $email, ?string $userId = null, string $purpose = 'register'): string
    {
        EmailOtp::where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        EmailOtp::create([
            'email' => $email,
            'user_id' => $userId,
            'code_hash' => hash('sha256', $code),
            'purpose' => $purpose,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return $code;
    }

    /**
     * Verify a code. Returns the OTP record when valid, otherwise null.
     */
    public function verify(string $email, string $code, string $purpose = 'register'): ?EmailOtp
    {
        $otp = EmailOtp::where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->latest()
            ->first();

        if (! $otp) {
            return null;
        }

        if ($otp->expires_at && $otp->expires_at->isPast()) {
            return null;
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            $otp->update(['used_at' => now()]);

            return null;
        }

        $otp->increment('attempts');

        if (! hash_equals($otp->code_hash, hash('sha256', $code))) {
            return null;
        }

        $otp->update(['used_at' => now()]);

        return $otp;
    }
}
