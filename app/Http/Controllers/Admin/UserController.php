<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Services\Admin\UserAdminService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 9.2 (PHASE9_AUDIT_AND_PLAN.md §3.2) — see UserAdminService's
 * docblock for why this screen didn't exist before now.
 */
class UserController extends Controller
{
    public function __construct(private readonly UserAdminService $service)
    {
    }

    public function index(Request $request)
    {
        $users = User::query()
            ->with('department')
            ->when($request->search, fn ($q, $search) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        // Loaded once here rather than N+1 per row in the view.
        $users->getCollection()->each->loadMissing('roles');

        return view('admin.users.index', compact('users'));
    }

    public function create()
    {
        return view('admin.users.create', [
            'departments' => Department::orderBy('name')->get(),
            'roles' => RolesAndPermissionsSeeder::ROLES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username',
            'email' => 'required|email|max:255|unique:users,email',
            'department_id' => 'nullable|exists:departments,id',
            'role' => ['required', Rule::in(RolesAndPermissionsSeeder::ROLES)],
            'auth_type' => 'required|in:password_only,password_mfa,pin',
            'password' => 'required_unless:auth_type,pin|nullable|string|min:8',
            'pin' => 'required_if:auth_type,pin|nullable|digits_between:4,6',
        ]);

        $user = $this->service->create($data, $request->user());

        return redirect()->route('admin.users.edit', $user)->with('status', 'User account created.');
    }

    public function edit(User $user)
    {
        return view('admin.users.edit', [
            'targetUser' => $user->load('department', 'roles'),
            'departments' => Department::orderBy('name')->get(),
            'roles' => RolesAndPermissionsSeeder::ROLES,
        ]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'department_id' => 'nullable|exists:departments,id',
            'role' => ['required', Rule::in(RolesAndPermissionsSeeder::ROLES)],
        ]);

        $this->service->update($user, $data, $request->user());

        return back()->with('status', 'User account updated.');
    }

    public function activate(Request $request, User $user)
    {
        $this->service->setActive($user, true, $request->user());

        return back()->with('status', 'User account reactivated.');
    }

    public function deactivate(Request $request, User $user)
    {
        $this->service->setActive($user, false, $request->user());

        return back()->with('status', 'User account deactivated.');
    }

    public function resetPassword(Request $request, User $user)
    {
        $data = $request->validate(['password' => 'required|string|min:8']);

        $this->service->resetPassword($user, $data['password'], $request->user());

        return back()->with('status', "Password reset for {$user->name}.");
    }

    public function resetPin(Request $request, User $user)
    {
        $data = $request->validate(['pin' => 'required|digits_between:4,6']);

        $this->service->resetPin($user, $data['pin'], $request->user());

        return back()->with('status', "PIN reset for {$user->name}.");
    }

    public function unlockPin(Request $request, User $user)
    {
        $this->service->unlockPin($user, $request->user());

        return back()->with('status', "PIN lockout cleared for {$user->name}.");
    }
}
