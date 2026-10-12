<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\EmployeeInvitations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class InviteSuperAdmin extends Command
{
    protected $signature = 'veritas:invite-super-admin {email} {--name=} {--resend : Resend the pending bootstrap invitation}';

    protected $description = 'Provision the initial Super Admin by expiring email invitation; never converts an existing business account';

    public function handle(): int
    {
        $data = Validator::make(['email' => Str::lower(trim($this->argument('email'))), 'name' => $this->option('name')], [
            'email' => 'required|email|max:255', 'name' => 'required|string|max:255',
        ])->validate();
        DB::transaction(function () use ($data) {
            $roles = Role::orderBy('id')->lockForUpdate()->get();
            $role = $roles->firstWhere('slug', 'super-admin');
            if (! $role || User::where('role_id', $role->id)->where('status', 'Active')->exists()) {
                throw new \RuntimeException('Run the security migrations first. Once an active Super Admin exists, use authenticated User Management.');
            }
            $user = User::whereRaw('LOWER(email) = ?', [$data['email']])->lockForUpdate()->first();
            if ($user) {
                if (! $this->option('resend') || $user->role_id !== $role->id || $user->status !== 'Pending Invitation') {
                    throw new \RuntimeException('Existing accounts cannot be converted or overwritten. Use --resend only for a pending Super Admin.');
                }
            } else {
                $user = User::create($data + ['role_id' => $role->id, 'status' => 'Pending Invitation', 'password' => Str::random(64)]);
            }
            EmployeeInvitations::issue($user, null);
        }, 3);
        $this->info('Super Admin invitation sent. The recipient must accept it before signing in.');

        return self::SUCCESS;
    }
}
