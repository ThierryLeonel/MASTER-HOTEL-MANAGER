<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Seeds the settings table from safe defaults (spec section 23). These
 * are starting values only — Owner/GM change them from the Settings
 * screen afterward without a code deploy.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // Phase 9.2 (PHASE9_AUDIT_AND_PLAN.md §3.2): hotel profile
            // fields, previously nowhere in the system except the
            // unrelated APP_NAME env value used only as a page title.
            ['key' => 'hotel_name', 'group' => 'general', 'value' => (string) env('APP_NAME', 'Hotel Management System'), 'type' => 'string', 'description' => 'Displayed on printable documents and the sidebar.'],
            ['key' => 'hotel_address', 'group' => 'general', 'value' => '', 'type' => 'string', 'description' => 'Postal/physical address shown on printable documents.'],
            ['key' => 'hotel_phone', 'group' => 'general', 'value' => '', 'type' => 'string', 'description' => 'Contact phone number shown on printable documents.'],
            ['key' => 'hotel_email', 'group' => 'general', 'value' => '', 'type' => 'string', 'description' => 'Contact email shown on printable documents.'],
            // 'hotel_region' (post-9.8 fix round): the receipt redesign
            // ("the receipt is to carry also, the name of the hotel,
            // email, telephone, region, and the logo") needs a region/
            // location field distinct from the full postal address.
            ['key' => 'hotel_region', 'group' => 'general', 'value' => '', 'type' => 'string', 'description' => 'Region/city shown on printable receipts, alongside the hotel name and contact details.'],
            ['key' => 'hotel_logo_path', 'group' => 'general', 'value' => '', 'type' => 'string', 'description' => 'Storage path (public disk) of the uploaded hotel logo, set via the Settings screen — never edited by hand.'],
            ['key' => 'currency', 'group' => 'finance', 'value' => 'XAF', 'type' => 'string', 'description' => 'ISO currency code used across the system.'],
            // Post-9.8 fix round ("all VAT on food and drinks and even
            // services should be removed, it should not even appear on
            // receipts"): kept as a dormant field rather than deleted
            // outright, per the go-live decision — nothing in the system
            // reads this value anymore (OrderService::recalculateTotals()
            // always zeroes tax instead), so changing it here currently
            // has no effect anywhere.
            ['key' => 'tax_rate_percent', 'group' => 'finance', 'value' => (string) env('HMS_TAX_RATE_PERCENT', '19.25'), 'type' => 'decimal', 'description' => 'Currently unused — VAT/tax has been switched off everywhere in the system (no charge, and never shown on a receipt). This value has no effect.'],
            ['key' => 'deposit_percent', 'group' => 'front_office', 'value' => (string) env('HMS_DEPOSIT_PERCENT', '30'), 'type' => 'decimal', 'description' => 'Default reservation deposit as a percentage of total stay value.'],
            ['key' => 'expense_approval_threshold', 'group' => 'finance', 'value' => (string) env('HMS_EXPENSE_APPROVAL_THRESHOLD', '50000'), 'type' => 'decimal', 'description' => 'Expenses at or above this amount (XAF) require manager approval before posting.'],
            ['key' => 'session_timeout_minutes', 'group' => 'security', 'value' => (string) env('HMS_SESSION_TIMEOUT_MINUTES', '15'), 'type' => 'integer', 'description' => 'Idle session timeout in minutes.'],
            ['key' => 'pin_max_attempts', 'group' => 'security', 'value' => (string) env('HMS_PIN_MAX_ATTEMPTS', '5'), 'type' => 'integer', 'description' => 'Consecutive failed PIN attempts before lockout.'],
            ['key' => 'pin_lockout_minutes', 'group' => 'security', 'value' => (string) env('HMS_PIN_LOCKOUT_MINUTES', '15'), 'type' => 'integer', 'description' => 'Lockout duration in minutes after exceeding pin_max_attempts.'],
            ['key' => 'payroll_working_days_per_month', 'group' => 'hr', 'value' => (string) env('HMS_PAYROLL_WORKING_DAYS_PER_MONTH', '26'), 'type' => 'integer', 'description' => 'Divides basic salary to get a daily rate for the unpaid-absence payroll deduction.'],
        ];

        foreach ($defaults as $setting) {
            Setting::firstOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
