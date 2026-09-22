<?php

namespace App\Services\Sync;

use App\Models\SyncDevice;
use App\Models\SyncLog;

/**
 * Phase 9.7.2. Sole writer of the sync_logs table — mirrors
 * App\Services\AuditLogger's single-writer pattern for the exact same
 * reason: one obvious place to look for every sync event, rather than
 * scattered `SyncLog::create()` calls across the sync engine's future
 * services (SyncService in 9.7.3, the transport controller in 9.7.4, the
 * scheduled commands in 9.7.5).
 */
class SyncLogger
{
    public function info(string $event, string $message, ?SyncDevice $device = null, ?array $context = null): SyncLog
    {
        return $this->log('info', $event, $message, $device, $context);
    }

    public function warning(string $event, string $message, ?SyncDevice $device = null, ?array $context = null): SyncLog
    {
        return $this->log('warning', $event, $message, $device, $context);
    }

    public function error(string $event, string $message, ?SyncDevice $device = null, ?array $context = null): SyncLog
    {
        return $this->log('error', $event, $message, $device, $context);
    }

    private function log(string $level, string $event, string $message, ?SyncDevice $device, ?array $context): SyncLog
    {
        return SyncLog::create([
            'sync_device_id' => $device?->id,
            'level' => $level,
            'event' => $event,
            'message' => $message,
            'context' => $context,
            'created_at' => now(),
        ]);
    }
}
