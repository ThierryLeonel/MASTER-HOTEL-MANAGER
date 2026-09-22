<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Single point of access for every configurable business policy value
 * (spec section 23): room rates live on room_categories, but tax rate,
 * deposit percentage, expense approval threshold, session timeout, PIN
 * lockout rules, payroll rates, etc. all read through here instead of
 * being hardcoded in controllers/services.
 *
 * Backed by the `settings` table so Owner/GM can change policy from the
 * UI without a code deploy. Cached briefly per request cycle to avoid
 * re-querying the same key repeatedly.
 */
class SettingsService
{
    private array $cache = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $setting = Cache::remember("setting.{$key}", 300, function () use ($key) {
            return Setting::where('key', $key)->first();
        });

        $value = $setting ? $setting->castValue() : $default;

        return $this->cache[$key] = $value;
    }

    public function set(string $key, mixed $value, ?int $updatedBy = null): Setting
    {
        $setting = Setting::firstOrNew(['key' => $key]);
        $setting->value = is_array($value) ? json_encode($value) : (string) $value;
        $setting->updated_by = $updatedBy;
        $setting->save();

        unset($this->cache[$key]);
        Cache::forget("setting.{$key}");

        return $setting;
    }

    public function taxRatePercent(): float
    {
        return (float) $this->get('tax_rate_percent', 0);
    }

    public function depositPercent(): float
    {
        return (float) $this->get('deposit_percent', 0);
    }

    public function expenseApprovalThreshold(): float
    {
        return (float) $this->get('expense_approval_threshold', 0);
    }

    public function sessionTimeoutMinutes(): int
    {
        return (int) $this->get('session_timeout_minutes', 15);
    }

    public function pinMaxAttempts(): int
    {
        return (int) $this->get('pin_max_attempts', 5);
    }

    public function pinLockoutMinutes(): int
    {
        return (int) $this->get('pin_lockout_minutes', 15);
    }

    public function currency(): string
    {
        return (string) $this->get('currency', 'XAF');
    }

    /**
     * Phase 9.2 hotel-profile getters (PHASE9_AUDIT_AND_PLAN.md §3.2) —
     * intended for the future printable-documents phase (9.5) as well as
     * the Settings screen itself.
     */
    public function hotelName(): string
    {
        return (string) $this->get('hotel_name', config('app.name'));
    }

    public function hotelAddress(): string
    {
        return (string) $this->get('hotel_address', '');
    }

    public function hotelPhone(): string
    {
        return (string) $this->get('hotel_phone', '');
    }

    public function hotelEmail(): string
    {
        return (string) $this->get('hotel_email', '');
    }

    /**
     * Post-9.8 fix round: the redesigned printable receipt shows a
     * region/city alongside the address — see PHASE9_AUDIT_AND_PLAN.md's
     * receipt-redesign note.
     */
    public function hotelRegion(): string
    {
        return (string) $this->get('hotel_region', '');
    }

    public function hotelLogoPath(): ?string
    {
        $path = (string) $this->get('hotel_logo_path', '');

        return $path !== '' ? $path : null;
    }

    /**
     * Divides basic salary to get a daily rate for the unpaid-absence
     * deduction in payroll (Phase 5) — configurable rather than a
     * hardcoded /30 or /26, since that choice varies by employer.
     */
    public function payrollWorkingDaysPerMonth(): int
    {
        return (int) $this->get('payroll_working_days_per_month', 26);
    }
}
