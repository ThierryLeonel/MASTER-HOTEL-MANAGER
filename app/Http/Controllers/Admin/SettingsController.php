<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 9.2 (PHASE9_AUDIT_AND_PLAN.md §3.2): SettingsService and the
 * `settings` table have existed since Phase 1 and are read from
 * throughout the app, but nothing ever exposed an admin screen to edit
 * them — every value came from SettingsSeeder/.env at install time and
 * could only be changed by hand-editing the database. This is the first
 * such screen.
 *
 * Deliberately not delegated to a dedicated Admin service class the way
 * Users/Departments are: every write here is a single key/value replace
 * through the existing SettingsService::set(), which already owns cache
 * invalidation — there is no additional business logic to encapsulate.
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index()
    {
        $groups = Setting::orderBy('group')->orderBy('key')->get()->groupBy('group');

        return view('admin.settings.index', compact('groups'));
    }

    public function update(Request $request)
    {
        $incoming = $request->input('settings', []);
        $editable = Setting::where('is_editable', true)->get()->keyBy('key');

        $changed = [];

        foreach ($incoming as $key => $rawValue) {
            $setting = $editable->get($key);
            if (! $setting) {
                // Unknown or non-editable key — silently ignored rather
                // than erroring, so a stale/tampered form field can never
                // create an arbitrary settings row.
                continue;
            }

            $value = match ($setting->type) {
                'boolean' => $request->boolean("settings.{$key}"),
                'integer' => (string) (int) $rawValue,
                'decimal' => (string) (float) $rawValue,
                default => (string) $rawValue,
            };

            if ((string) $setting->value !== $value) {
                $this->settings->set($key, $value, $request->user()->id);
                $changed[$key] = ['old' => $setting->value, 'new' => $value];
            }
        }

        if ($changed !== []) {
            $this->audit->log(
                'admin.settings_updated',
                'admin',
                newValues: $changed,
                actor: $request->user(),
            );
        }

        return back()->with('status', $changed !== [] ? 'Settings updated.' : 'No changes to save.');
    }

    public function uploadLogo(Request $request)
    {
        // Security re-audit finding (PHASE9_AUDIT_AND_PLAN.md §9.8, fixed):
        // 'svg' was previously in the mimes allowlist. SVG is a scriptable
        // XML format — a crafted logo.svg containing inline <script> would
        // be accepted, stored on the public disk, and served at a direct
        // URL, a known stored-XSS vector if that URL is ever opened
        // directly rather than only used inside an <img src> (which does
        // not execute embedded script). Dropped; png/jpg/jpeg are the only
        // formats this hotel-logo feature actually needs.
        $request->validate([
            'logo' => 'required|image|mimes:png,jpg,jpeg|max:2048',
        ]);

        $oldPath = $this->settings->hotelLogoPath();

        $path = $request->file('logo')->store('settings', 'public');

        $this->settings->set('hotel_logo_path', $path, $request->user()->id);

        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $this->audit->log('admin.settings_logo_uploaded', 'admin', newValues: ['path' => $path], actor: $request->user());

        return back()->with('status', 'Logo updated.');
    }
}
