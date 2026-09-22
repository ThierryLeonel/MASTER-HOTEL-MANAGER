<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Post-9.8 fix round ("let each account be able to modify their personal
 * pin, but only the master or main manager have the access to reset the
 * pin"): before this controller, the ONLY way a PIN-login account's PIN
 * ever changed was an admin doing it via
 * App\Http\Controllers\Admin\UserController::resetPin() — gated on
 * 'manage-users', which (see RolesAndPermissionsSeeder) only Owner and
 * General Manager hold, so that half of the request was already true
 * before this file existed. This adds the missing self-service half: any
 * authenticated PIN-login account can change its OWN PIN, after proving
 * it knows the current one — no permission needed beyond being logged in,
 * since this only ever touches auth()->user(), never another account.
 */
class MyPinController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function edit(Request $request)
    {
        return view('auth.my-pin', ['targetUser' => $request->user()]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        if ($user->auth_type !== 'pin') {
            return back()->withErrors(['error' => 'Your account does not use PIN login, so there is no PIN to change here.']);
        }

        $data = $request->validate([
            'current_pin' => 'required|digits_between:4,6',
            'new_pin' => 'required|digits_between:4,6|confirmed|different:current_pin',
        ]);

        if (! Hash::check($data['current_pin'], $user->pin_hash)) {
            return back()->withErrors(['current_pin' => 'Your current PIN is incorrect.']);
        }

        $user->update([
            'pin_hash' => Hash::make($data['new_pin']),
            'failed_pin_attempts' => 0,
            'pin_locked_until' => null,
        ]);

        $this->audit->log('user.pin_changed_self', 'auth', $user, actor: $user);

        return back()->with('status', 'Your PIN has been updated.');
    }
}
