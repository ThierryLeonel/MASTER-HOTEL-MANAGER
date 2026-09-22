<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Every important mutation writes exactly one row here (spec section 21).
 * No controller, policy, or route grants a normal user update/delete
 * access to the audit_logs table — this class is the only writer.
 */
class AuditLogger
{
    public function log(
        string $action,
        string $module,
        ?Model $record = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $reason = null,
        ?User $actor = null,
    ): AuditLog {
        $user = $actor ?? Auth::user();

        return AuditLog::create([
            'user_id' => $user?->id,
            'role_name' => $user?->getRoleNames()->first(),
            'action' => $action,
            'module' => $module,
            'record_type' => $record ? $record::class : null,
            'record_id' => $record?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => Request::ip(),
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
