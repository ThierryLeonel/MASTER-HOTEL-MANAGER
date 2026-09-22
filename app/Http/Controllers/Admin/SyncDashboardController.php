<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SyncConflict;
use App\Models\SyncDevice;
use App\Models\SyncLog;
use App\Services\Sync\SyncConflictResolver;
use Illuminate\Http\Request;

/**
 * Phase 9.7.6 (PHASE9_AUDIT_AND_PLAN.md §6, Phase 9.7 sub-plan): the
 * human-facing half of the sync engine everything from 9.7.1–9.7.5 built
 * headlessly — device list with last-sync times, pending conflict
 * counts, recent activity, and the one place a conflict can actually be
 * resolved (SyncConflictResolver does the resolving; this controller
 * only authenticates and renders). Gated on `manage-sync`, a new
 * permission (Owner/GM/Duty Manager — see RolesAndPermissionsSeeder),
 * following this project's established `admin/*`-prefixed,
 * permission-middleware pattern (see routes/web.php's `admin.users` /
 * `admin.settings` groups) rather than introducing a new authorization
 * mechanism.
 */
class SyncDashboardController extends Controller
{
    public function __construct(private readonly SyncConflictResolver $resolver)
    {
    }

    public function index()
    {
        $devices = SyncDevice::with('checkpoints')->orderBy('name')->get()->map(function (SyncDevice $device) {
            return [
                'device' => $device,
                'outbound' => $device->checkpointFor('outbound'),
                'inbound' => $device->checkpointFor('inbound'),
                'pending_conflicts' => SyncConflict::whereNull('resolved_at')
                    ->whereHas('item.batch', fn ($q) => $q->where('sync_device_id', $device->id))
                    ->count(),
            ];
        });

        $recentLogs = SyncLog::with('device')->orderByDesc('id')->limit(25)->get();
        $totalPendingConflicts = SyncConflict::whereNull('resolved_at')->count();

        return view('admin.sync.index', compact('devices', 'recentLogs', 'totalPendingConflicts'));
    }

    public function conflicts()
    {
        $conflicts = SyncConflict::whereNull('resolved_at')
            ->with('item.batch.device')
            ->orderBy('detected_at')
            ->get();

        return view('admin.sync.conflicts', compact('conflicts'));
    }

    public function resolveConflict(Request $request, SyncConflict $conflict)
    {
        $data = $request->validate([
            'resolution' => 'required|in:'.implode(',', SyncConflictResolver::RESOLUTIONS),
            'notes' => 'nullable|string|max:1000',
        ]);

        $this->resolver->resolve($conflict, $data['resolution'], $request->user(), $data['notes'] ?? null);

        return redirect()->route('admin.sync.conflicts')->with('status', 'Conflict resolved.');
    }
}
