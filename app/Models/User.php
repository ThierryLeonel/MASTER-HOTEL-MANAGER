<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use App\Services\Sync\Concerns\HasSyncUlid;

/**
 * A staff account. Which fields matter depends on auth_type (spec section 9):
 *
 *   password_only -> `password` set, `pin_hash` null, no TOTP required (Duty Manager)
 *   password_mfa  -> `password` set + TOTP enrolled (Owner, General Manager, Accountant)
 *   pin           -> `pin_hash` set, `password` null (Cashier, Front Desk, Bar Staff, Restaurant Staff)
 */
class User extends Authenticatable
{
    use HasSyncUlid;
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles, SoftDeletes;

    protected $fillable = [
        'name',
        'username',
        'email',
        'auth_type',
        'password',
        'pin_hash',
        'department_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'pin_hash',
        'remember_token',
        'totp_secret',
        'totp_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin_hash' => 'hashed',
            'is_active' => 'boolean',
            'totp_enabled' => 'boolean',
            'totp_secret' => 'encrypted',
            'totp_recovery_codes' => 'encrypted:array',
            'totp_confirmed_at' => 'datetime',
            'pin_locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function employeeProfile()
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    public function attendanceRecords()
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function leaveBalances()
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function payslips()
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * The login flow (App\Http\Controllers\Auth\LoginController) consults
     * this: does this account's auth_type
     * require TOTP before a session is considered fully authenticated?
     */
    public function requiresMfa(): bool
    {
        return $this->auth_type === 'password_mfa';
    }

    public function usesPinLogin(): bool
    {
        return $this->auth_type === 'pin';
    }

    public function isPinLocked(): bool
    {
        return $this->pin_locked_until !== null && $this->pin_locked_until->isFuture();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePinAuth($query)
    {
        return $query->where('auth_type', 'pin');
    }
}
