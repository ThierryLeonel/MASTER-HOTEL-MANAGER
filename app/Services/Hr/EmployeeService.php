<?php

namespace App\Services\Hr;

use App\Exceptions\HmsException;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * HR-specific data about a staff account, kept separate from
 * authentication (see employee_profiles migration). `onboard()` is an
 * upsert: it both creates a first profile and edits an existing one,
 * since HR data legitimately changes (a raise, a title change) without
 * that being a new "employee".
 */
class EmployeeService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function onboard(User $user, array $data, User $actor): EmployeeProfile
    {
        $profile = EmployeeProfile::where('user_id', $user->id)->first();
        $wasNew = $profile === null;

        $profile ??= new EmployeeProfile([
            'user_id' => $user->id,
            'employee_number' => $this->generateNumber(),
        ]);

        $profile->fill([
            'job_title' => $data['job_title'],
            'employment_type' => $data['employment_type'],
            'hire_date' => $data['hire_date'],
            'basic_salary' => $data['basic_salary'] ?? null,
            'payment_method' => $data['payment_method'] ?? $profile->payment_method ?? 'bank_transfer',
            'bank_account' => $data['bank_account'] ?? null,
            'national_id' => $data['national_id'] ?? null,
        ])->save();

        $this->audit->log(
            $wasNew ? 'hr.employee_onboarded' : 'hr.employee_profile_updated',
            'hr',
            $profile,
            null,
            $profile->fresh()->toArray(),
            actor: $actor,
        );

        return $profile->fresh();
    }

    public function terminate(EmployeeProfile $profile, User $actor, ?Carbon $date = null): EmployeeProfile
    {
        if ($profile->employment_status === 'terminated') {
            throw new HmsException('This employee is already terminated.');
        }

        return DB::transaction(function () use ($profile, $actor, $date) {
            $profile->update([
                'employment_status' => 'terminated',
                'termination_date' => ($date ?? now())->toDateString(),
            ]);
            $profile->user()->update(['is_active' => false]);

            $this->audit->log('hr.employee_terminated', 'hr', $profile, null, $profile->fresh()->toArray(), actor: $actor);

            return $profile->fresh();
        });
    }

    private function generateNumber(): string
    {
        return 'EMP-'.strtoupper(Str::random(6));
    }
}
