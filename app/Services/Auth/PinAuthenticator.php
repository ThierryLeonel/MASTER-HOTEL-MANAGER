<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Hash;

/**
 * PIN login for Cashier / Front Desk / Bar Staff / Restaurant Staff
 * (spec section 9), with brute-force lockout (spec section 28):
 * pinMaxAttempts consecutive failures locks the account for
 * pinLockoutMinutes, both configurable via SettingsService rather than
 * hardcoded.
 */
class PinAuthenticator
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {
    }

    public function attempt(User $user, string $pin): bool
    {
        if ($user->isPinLocked()) {
            return false;
        }

        if (! $user->pin_hash || ! Hash::check($pin, $user->pin_hash)) {
            $this->registerFailure($user);

            return false;
        }

        if ($user->failed_pin_attempts > 0) {
            $user->update(['failed_pin_attempts' => 0, 'pin_locked_until' => null]);
        }

        return true;
    }

    private function registerFailure(User $user): void
    {
        $attempts = $user->failed_pin_attempts + 1;
        $maxAttempts = $this->settings->pinMaxAttempts();

        $data = ['failed_pin_attempts' => $attempts];

        if ($attempts >= $maxAttempts) {
            $data['pin_locked_until'] = now()->addMinutes($this->settings->pinLockoutMinutes());
            $this->audit->log('auth.pin_locked', 'auth', $user, null, ['attempts' => $attempts], actor: $user);
        }

        $user->update($data);
    }
}
