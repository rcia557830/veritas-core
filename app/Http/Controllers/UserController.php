<?php

namespace App\Http\Controllers;

use App\Models\EmployeeInvitation;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountSecurity;
use App\Services\Audit;
use App\Services\EmployeeInvitations;
use App\Services\Records;
use App\Services\SecurityMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', User::class);
        $request->validate(['q' => 'nullable|string|max:150', 'status' => 'nullable|in:Active,Inactive,Pending Invitation']);
        $users = User::with('role')->when(Records::searchText($request) !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.Records::searchText($request).'%')->orWhere('email', 'like', '%'.Records::searchText($request).'%')))->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))->orderBy('name')->paginate(Records::pageSize($request))->withQueryString();

        return view('admin.users.index', compact('users'));
    }

    public function create()
    {
        Gate::authorize('create', User::class);

        return $this->form(new User);
    }

    public function edit(User $user)
    {
        Gate::authorize('update', $user);

        return $this->form($user);
    }

    private function form(User $user)
    {
        return view('admin.users.form', ['user' => $user, 'roles' => Role::whereIn('slug', $user->exists ? ($user->role?->slug === 'super-admin' ? ['super-admin'] : ['owner', 'office-manager', 'bookkeeper']) : array_keys(config('rbac.roles')))->get()]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', User::class);

        return $this->save($request, new User);
    }

    public function update(Request $request, User $user)
    {
        Gate::authorize('update', $user);

        return $this->save($request, $user);
    }

    private function save(Request $request, User $user)
    {
        // Reject even empty fields: administrator-controlled passwords are never accepted.
        foreach (['password', 'password_confirmation', 'current_password', 'remember_token', 'session_version', 'invitation_accepted_at'] as $field) {
            abort_if($request->exists($field), 403, 'Account credentials cannot be set by administrators.');
        }
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->whereIn('slug', array_keys(config('rbac.roles')))],
            'status' => $user->exists ? 'required|in:Active,Inactive,Pending Invitation' : 'prohibited',
        ]);
        DB::transaction(function () use ($user, $data, $request) {
            $roles = Role::orderBy('id')->lockForUpdate()->get();
            $saved = $user->exists ? User::lockForUpdate()->findOrFail($user->id) : new User;
            Gate::authorize($saved->exists ? 'update' : 'create', $saved->exists ? $saved : User::class);
            $created = ! $saved->exists;
            $oldRole = $saved->role_id;
            $oldStatus = $saved->status;
            if (! $created && Str::lower($saved->email) !== $data['email']) {
                throw ValidationException::withMessages(['email' => 'Employees change their own email in their authenticated profile.']);
            }
            if ($created || (int) $data['role_id'] !== $saved->role_id) {
                Gate::authorize('changeRole', $saved);
                $technical = $roles->firstWhere('slug', 'super-admin')->id;
                abort_if(! $created && ($saved->role_id === $technical || (int) $data['role_id'] === $technical), 403, 'Technical and business identities must remain separate. Invite a separate account.');
            }
            if (! $created && $data['status'] !== $saved->status) {
                Gate::authorize($data['status'] === 'Active' ? 'activate' : 'deactivate', $saved);
                $unaccepted = EmployeeInvitation::where('user_id', $saved->id)->exists() && ! $saved->invitation_accepted_at;
                if ($data['status'] === 'Pending Invitation' || ($data['status'] === 'Active' && $unaccepted)) {
                    throw ValidationException::withMessages(['status' => 'Pending employees must accept an invitation to activate their account.']);
                }
            }
            if ($created) {
                if (User::whereRaw('LOWER(email) = ?', [$data['email']])->exists()) {
                    throw ValidationException::withMessages(['email' => 'This email already has an account.']);
                }
                $data['status'] = 'Pending Invitation';
                $data['password'] = Str::random(64); // Unrecoverable random placeholder; never delivered.
            }
            $saved->fill($data)->save();
            if (! $created && $oldStatus === 'Active' && ($oldRole !== $saved->role_id || $saved->status !== 'Active')) {
                foreach (['owner' => 'Owner', 'super-admin' => 'Super Admin'] as $slug => $name) {
                    $roleId = $roles->firstWhere('slug', $slug)->id;
                    if ($oldRole === $roleId && ! User::where('role_id', $roleId)->where('status', 'Active')->exists()) {
                        throw ValidationException::withMessages(['status' => 'At least one active '.$name.' is required.']);
                    }
                }
            }
            if ($oldRole !== $saved->role_id || $oldStatus !== $saved->status) {
                AccountSecurity::revoke($saved);
                EmployeeInvitations::revoke($saved);
            }
            Audit::record($created ? 'user.created' : 'user.updated', 'users', $saved, 'Account details saved.');
            AccountSecurity::record($created ? 'user.created' : 'user.updated', $saved);
            if ($oldRole !== $saved->role_id) {
                Audit::record('role.changed', 'users', $saved, 'Role changed to '.$saved->role->name.'.');
                AccountSecurity::record('role.changed', $saved);
            }
            if ($oldStatus !== $saved->status) {
                Audit::record($saved->status === 'Active' ? 'account.activated' : 'account.deactivated', 'users', $saved, 'Account '.$saved->status.'.');
                AccountSecurity::record('account.status-changed', $saved);
            }
            if ($saved->status === 'Pending Invitation' && ($created || $oldRole !== $saved->role_id)) {
                EmployeeInvitations::issue($saved, $request->user());
            }
        }, 3);

        return redirect()->route('admin.users.index')->with('success', $user->exists ? 'Account details saved.' : 'Invitation sent. The employee will choose their password.');
    }

    public function reset(User $user)
    {
        Gate::authorize('resetPassword', $user);
        abort_if($user->status === 'Pending Invitation' || (EmployeeInvitation::where('user_id', $user->id)->exists() && ! $user->invitation_accepted_at), 422, 'Resend the invitation instead.');
        if (! SecurityMail::resetLink($user)) {
            throw ValidationException::withMessages(['email' => 'Reset email delivery failed. Check mail configuration and retry.']);
        }
        Audit::record('password-link', 'users', $user, 'Password reset link requested.');

        return back()->with('success', 'Password reset link requested.');
    }

    public function resend(Request $request, User $user)
    {
        Gate::authorize('update', $user);
        DB::transaction(function () use ($user, $request) {
            Role::orderBy('id')->lockForUpdate()->get();
            $saved = User::lockForUpdate()->findOrFail($user->id);
            Gate::authorize('update', $saved);
            // A cancelled pending account can only be reactivated by a fresh invitation.
            if ($saved->status === 'Inactive' && ! $saved->invitation_accepted_at && EmployeeInvitation::where('user_id', $saved->id)->exists()) {
                Gate::authorize('activate', $saved);
                $saved->update(['status' => 'Pending Invitation']);
            }
            EmployeeInvitations::issue($saved, $request->user());
        }, 3);

        return back()->with('success', 'Invitation resent. Previous links are invalid.');
    }

    public function revoke(User $user)
    {
        Gate::authorize('revokeSessions', $user);
        DB::transaction(function () use ($user) {
            $saved = User::lockForUpdate()->findOrFail($user->id);
            AccountSecurity::revoke($saved);
            AccountSecurity::record('sessions.revoked', $saved);
        });

        return back()->with('success', 'Sessions revoked.');
    }
}
