<?php

namespace App\Providers;

use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(AuditLogger::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Owner has unrestricted access — every other role's authorization
        // is still enforced explicitly via permissions, never via UI hiding
        // (spec section 8).
        Gate::before(function ($user, string $ability) {
            return $user->hasRole('owner') ? true : null;
        });

        // Session timeout is a configurable policy value (spec section 23),
        // not hardcoded — pulled from the settings table (falling back to
        // config/session.php's default when the table isn't migrated yet,
        // e.g. during the very first `migrate` run).
        try {
            if (Schema::hasTable('settings')) {
                $minutes = $this->app->make(SettingsService::class)->sessionTimeoutMinutes();
                config(['session.lifetime' => $minutes]);
            }
        } catch (\Throwable $e) {
            // Database not ready yet (fresh install) — keep config default.
        }
    }
}
