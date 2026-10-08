<?php

namespace App\Services;

use App\Models\ComplianceRecord;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class Notify
{
    public static function send(User $user, $record, string $module, string $title, string $key): void
    {
        if (! Gate::forUser($user)->allows('view', $record)) {
            return;
        }
        DB::transaction(function () use ($user, $record, $module, $title, $key) {
            $added = DB::table('notification_events')->insertOrIgnore(['user_id' => $user->id, 'event_key' => hash('sha256', $key), 'created_at' => now(), 'updated_at' => now()]);
            if ($added) {
                $user->notify(new WorkspaceNotification(['module' => $module, 'record_id' => $record->id, 'title' => $title, 'url' => route($module.'.show', $record), 'client' => $record->client?->business_name]));
            }
        });
    }

    public static function record($record, string $module, string $title): void
    {
        if (Setting::value('notifications_enabled') === false) {
            return;
        }
        User::where('status', 'Active')->with('role')->each(fn ($user) => self::send($user, $record, $module, $title, $module.':'.$record->id.':'.$record->updated_at.':'.$title));
    }

    public static function due(?User $only = null): void
    {
        if (! Setting::value('notifications_enabled')) {
            return;
        }
        $users = $only ? collect([$only]) : User::where('status', 'Active')->with('role')->get();
        foreach ($users as $user) {
            Access::query(ComplianceRecord::class, $user)->where('status', '!=', 'Filed')->whereDate('due_date', '<=', today()->addDays(10))->with('client')->chunkById(100, function ($records) use ($user) {
                foreach ($records as $r) {
                    self::send($user, $r, 'compliance', $r->urgency.': '.$r->requirement, 'due:'.$r->id.':'.$r->due_date->toDateString().':'.$r->urgency);
                }
            });
            Access::query(Invoice::class, $user)->where('status', 'Open')->whereDate('due_date', '<', today())->with(['client', 'items', 'payments'])->chunkById(100, function ($records) use ($user) {
                foreach ($records as $r) {
                    if ($r->balance_cents > 0) {
                        self::send($user, $r, 'billing', 'Overdue invoice '.$r->invoice_number, 'invoice:'.$r->id.':'.$r->due_date->toDateString());
                    }
                }
            });
        }
    }
}
