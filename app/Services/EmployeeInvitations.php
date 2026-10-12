<?php

namespace App\Services;

use App\Mail\EmployeeInvitationMail;
use App\Models\EmployeeInvitation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmployeeInvitations
{
    // The caller holds a user row lock; delivery happens only after commit.
    public static function issue(User $user, ?User $inviter): void
    {
        if ($user->status !== 'Pending Invitation') {
            throw ValidationException::withMessages(['status' => 'Only pending accounts can receive an invitation.']);
        }
        static::revoke($user);
        $token = bin2hex(random_bytes(32));
        $hours = max(1, (int) config('security.invitation_hours', 24));
        $invitation = EmployeeInvitation::create([
            'user_id' => $user->id, 'role_id' => $user->role_id, 'email' => Str::lower($user->email),
            'invited_by' => $inviter?->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours($hours),
        ]);
        AccountSecurity::record('invitation.issued', $user);
        $url = route('invitations.show', ['invitation' => $invitation->id, 'token' => $token]);
        DB::afterCommit(function () use ($user, $url, $hours) {
            try {
                SecurityMail::assertDeliveryTransport();
                Mail::to($user->email)->send(new EmployeeInvitationMail($url, $user->role->name, $hours));
                AccountSecurity::record('invitation.sent', $user);
            } catch (\Throwable $exception) {
                // Transport exceptions may embed message content. Never log the exception/token.
                AccountSecurity::record('invitation.delivery-failed', $user);
                throw ValidationException::withMessages(['email' => 'Account saved, but invitation delivery failed. Check mail configuration and resend the invitation.']);
            }
        });
    }

    public static function revoke(User $user): void
    {
        EmployeeInvitation::where('user_id', $user->id)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public static function accept(int $id, string $token, string $email, string $password): void
    {
        DB::transaction(function () use ($id, $token, $email, $password) {
            $reference = EmployeeInvitation::find($id);
            if (! $reference) {
                static::invalid();
            }
            // Match account administration lock order to serialize resends, role/status edits and acceptance.
            Role::orderBy('id')->lockForUpdate()->get();
            $user = User::lockForUpdate()->findOrFail($reference->user_id);
            $invitation = EmployeeInvitation::lockForUpdate()->findOrFail($id);
            if ($user->status !== 'Pending Invitation' || $invitation->accepted_at || $invitation->revoked_at
                || $invitation->expires_at->lte(now()) || ! hash_equals($invitation->token_hash, hash('sha256', $token))
                || $invitation->email !== Str::lower($email) || $invitation->email !== Str::lower($user->email)
                || $invitation->role_id !== $user->role_id) {
                static::invalid();
            }
            $user->forceFill(['password' => $password, 'status' => 'Active', 'email_verified_at' => now(), 'invitation_accepted_at' => now()])->save();
            $invitation->update(['accepted_at' => now()]);
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            AccountSecurity::revoke($user);
            AccountSecurity::record('invitation.accepted', $user);
        }, 3);
    }

    private static function invalid(): never
    {
        throw ValidationException::withMessages(['email' => 'This invitation is invalid or has expired. Ask your administrator to resend it.']);
    }
}
