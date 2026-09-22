<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * One demo account per role (spec section 8/9), documented in the README
 * "User Accounts" section. All demo passwords/PINs are intentionally
 * simple and MUST be rotated before any real deployment — this seeder is
 * for staging/evaluation only (spec section 40: never fake financial
 * history, and demo accounts must be clearly documented).
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $mgmt = Department::where('code', 'MGMT')->first();
        $fin = Department::where('code', 'FIN')->first();
        $fo = Department::where('code', 'FO')->first();
        $bar = Department::where('code', 'BAR')->first();
        $rest = Department::where('code', 'REST')->first();
        $barOut = Department::where('code', 'BAROUT')->first();
        $buca = Department::where('code', 'BUCA')->first();

        $this->makeUser('Olivia Owner', 'owner', 'owner@hms.test', $mgmt?->id, 'owner', 'password_mfa', password: 'OwnerPass!2026');
        $this->makeUser('Gabriel Manager', 'gm', 'gm@hms.test', $mgmt?->id, 'general_manager', 'password_mfa', password: 'ManagerPass!2026');
        $this->makeUser('Diane Duty', 'duty', 'duty@hms.test', $mgmt?->id, 'duty_manager', 'password_only', password: 'DutyPass!2026');
        $this->makeUser('Alain Accountant', 'accountant', 'accountant@hms.test', $fin?->id, 'accountant', 'password_mfa', password: 'AcctPass!2026');
        $this->makeUser('Chantal Cashier', 'cashier1', 'cashier1@hms.test', $fin?->id, 'cashier', 'pin', pin: '1234');
        $this->makeUser('Felix FrontDesk', 'frontdesk1', 'frontdesk1@hms.test', $fo?->id, 'front_desk', 'pin', pin: '1234');
        $this->makeUser('Boris Bartender', 'bar1', 'bar1@hms.test', $bar?->id, 'bar_staff', 'pin', pin: '1234');
        $this->makeUser('Rita Restaurant', 'rest1', 'rest1@hms.test', $rest?->id, 'restaurant_staff', 'pin', pin: '1234');
        // Post-9.8 second fix round: demo accounts for the two new
        // separately-run sales points.
        $this->makeUser('Pauline Outside', 'barout1', 'barout1@hms.test', $barOut?->id, 'bar_outside_staff', 'pin', pin: '1234');
        $this->makeUser('Bruno Bucareaux', 'buca1', 'buca1@hms.test', $buca?->id, 'bucareaux_staff', 'pin', pin: '1234');
    }

    private function makeUser(
        string $name,
        string $username,
        string $email,
        ?int $departmentId,
        string $role,
        string $authType,
        ?string $password = null,
        ?string $pin = null,
    ): void {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'username' => $username,
                'department_id' => $departmentId,
                'auth_type' => $authType,
                'password' => $password ? Hash::make($password) : null,
                'pin_hash' => $pin ? Hash::make($pin) : null,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }
    }
}
