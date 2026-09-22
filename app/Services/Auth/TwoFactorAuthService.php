<?php

namespace App\Services\Auth;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP enrollment/verification for password_mfa accounts (Owner, General
 * Manager, Accountant — spec section 9). Implemented directly against
 * pragmarx/google2fa rather than a full auth-package integration so it
 * can be layered onto our three-mode (password-only / password+MFA / PIN)
 * login flow, which no single off-the-shelf package models.
 */
class TwoFactorAuthService
{
    private Google2FA $engine;

    public function __construct()
    {
        $this->engine = new Google2FA;
    }

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    public function otpAuthUrl(User $user, string $secret): string
    {
        return $this->engine->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret,
        );
    }

    /**
     * Renders the enrollment QR code as an inline SVG string (no external
     * image service or JS dependency needed on the enrollment page).
     */
    public function qrCodeSvg(string $otpAuthUrl): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd,
        );
        $writer = new Writer($renderer);

        return $writer->writeString($otpAuthUrl);
    }

    public function verifyCode(string $secret, string $code): bool
    {
        return $this->engine->verifyKey($secret, preg_replace('/\s+/', '', $code));
    }

    public function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => strtoupper(Str::random(4).'-'.Str::random(4)))
            ->all();
    }

    public function confirm(User $user, string $secret, string $code): bool
    {
        if (! $this->verifyCode($secret, $code)) {
            return false;
        }

        $user->forceFill([
            'totp_secret' => $secret,
            'totp_enabled' => true,
            'totp_confirmed_at' => now(),
            'totp_recovery_codes' => $this->generateRecoveryCodes(),
        ])->save();

        return true;
    }

    public function verifyForLogin(User $user, string $code): bool
    {
        if (! $user->totp_enabled || ! $user->totp_secret) {
            return false;
        }

        if ($this->verifyCode($user->totp_secret, $code)) {
            return true;
        }

        // Fall back to a one-time recovery code.
        $codes = $user->totp_recovery_codes ?? [];
        $normalized = strtoupper(trim($code));
        if (in_array($normalized, $codes, true)) {
            $user->update(['totp_recovery_codes' => array_values(array_diff($codes, [$normalized]))]);

            return true;
        }

        return false;
    }
}
