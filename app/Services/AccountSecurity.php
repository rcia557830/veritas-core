<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountSecurity
{
    // Call while holding the user row lock. Version checks also revoke non-DB sessions.
    public static function revoke(User $user, ?string $except = null): void
    {
        $user->forceFill(['session_version' => $user->session_version + 1, 'remember_token' => Str::random(60)])->save();
        DB::table('sessions')->where('user_id', $user->id)->when($except, fn ($q) => $q->where('id', '!=', $except))->delete();
    }

    public static function record(string $action, ?User $subject = null): void
    {
        // Fixed event names and IDs only: no tokens, passwords, emails or financial payloads.
        DB::table('security_events')->insert([
            'actor_id' => auth()->id(), 'subject_id' => $subject?->id,
            'action' => $action, 'ip_address' => app()->runningInConsole() ? null : request()->ip(), 'created_at' => now(),
        ]);
    }
}
