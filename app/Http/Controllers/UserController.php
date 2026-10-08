<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit;
use App\Services\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', User::class);
        $request->validate(['q' => 'nullable|string|max:150', 'status' => 'nullable|in:Active,Inactive']);
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
        return view('admin.users.form', ['user' => $user, 'roles' => Role::whereIn('slug', array_keys(config('rbac.roles')))->get()]);
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
        $data = $request->validate(['name' => 'required|string|max:255', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)], 'password' => [$user->exists ? 'nullable' : 'required', 'confirmed', \Illuminate\Validation\Rules\Password::min(10)], 'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->whereIn('slug', array_keys(config('rbac.roles')))], 'status' => 'required|in:Active,Inactive']);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        DB::transaction(function () use ($user, $data) {
            $ownerRole = Role::where('slug', 'owner')->lockForUpdate()->firstOrFail();
            $saved = $user->exists ? User::lockForUpdate()->findOrFail($user->id) : new User;
            Gate::authorize($saved->exists ? 'update' : 'create', $saved->exists ? $saved : User::class);
            if (! $saved->exists || (int) $data['role_id'] !== $saved->role_id) {
                Gate::authorize('changeRole', $saved);
            }
            if (! $saved->exists || $data['status'] !== $saved->status) {
                Gate::authorize($data['status'] === 'Active' ? 'activate' : 'deactivate', $saved);
            }
            $created = ! $saved->exists;
            $oldRole = $saved->role_id;
            $oldStatus = $saved->status;
            $saved->fill($data);
            $saved->save();
            if (! User::where('role_id', $ownerRole->id)->where('status', 'Active')->exists()) {
                throw ValidationException::withMessages(['role_id' => 'At least one active Owner is required.']);
            }
            if (isset($data['password']) || $oldRole !== $saved->role_id || $oldStatus !== $saved->status) {
                DB::table('sessions')->where('user_id', $saved->id)->delete();
                $saved->forceFill(['remember_token' => Str::random(60)])->save();
            }
            Audit::record($created ? 'user.created' : 'user.updated', 'users', $saved, 'Account saved: '.$saved->name.'.');
            if ($oldRole !== $saved->role_id) {
                Audit::record('role.changed', 'users', $saved, 'Role changed to '.$saved->role->name.'.');
            }
            if ($oldStatus !== $saved->status) {
                Audit::record($saved->status === 'Active' ? 'account.activated' : 'account.deactivated', 'users', $saved, 'Account '.$saved->status.'.');
            }
        });

        return redirect()->route('admin.users.index')->with('success', 'User saved successfully.');
    }

    public function reset(User $user)
    {
        Gate::authorize('resetPassword', $user);
        Password::sendResetLink(['email' => $user->email]);
        Audit::record('password-link', 'users', $user, 'Password reset link requested.');

        return back()->with('success', 'Password reset link requested.');
    }
}
