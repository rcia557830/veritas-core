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
    public static function send(User $user, $record, string $module, string $title, string $key, array $context = []): void
    {
        if (! Gate::forUser($user)->allows('view', $record)) {
            return;
        }
        DB::transaction(function () use ($user, $record, $module, $title, $key, $context) {
            $added = DB::table('notification_events')->insertOrIgnore(['user_id' => $user->id, 'event_key' => hash('sha256', $key), 'created_at' => now(), 'updated_at' => now()]);
            if ($added) {
                $user->notify(new WorkspaceNotification(['module' => $module, 'record_id' => $record->id, 'title' => $title, 'url' => route($module.'.show', $record), 'client' => $record->client?->business_name] + $context));
            }
        });
    }

    public static function isCurrent($record, array $data): bool
    {
        if (! $record instanceof ComplianceRecord) {
            return true;
        }
        if (($data['event'] ?? null) !== 'compliance.deadline') {
            // Legacy deadline alerts have no date snapshot. Keep their history but
            // replace their active display with dated alerts from due().
            return ! preg_match('/^(Overdue|Due Today|Due Soon|Upcoming): /', $data['title'] ?? '');
        }
        if (in_array($record->status, ['Filed', 'Completed'], true)) {
            return false;
        }

        return ($data['submission_deadline'] ?? null) === ($record->submission_deadline?->toDateString())
            && ($data['due_date'] ?? null) === $record->due_date->toDateString()
            && ($data['urgency'] ?? null) === $record->urgency
            && DeadlineCalculator::needsAlert($record);
    }

    public static function deadlineKey(ComplianceRecord $record): string
    {
        return 'due:v3:'.$record->id.':'.($record->submission_deadline?->toDateString() ?? 'none').':'.$record->due_date->toDateString().':'.$record->urgency;
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
            $leadWindow = today()->addDays(DeadlineCalculator::filingApproachDays() + DeadlineCalculator::leadDays());
            Access::query(ComplianceRecord::class, $user)
                ->whereNotIn('status', ['Filed', 'Completed'])
                ->where(function ($q) use ($leadWindow) {
                    $q->whereDate('due_date', '<=', $leadWindow)
                      ->orWhereDate('submission_deadline', '<=', today()->addDays(DeadlineCalculator::submissionApproachDays()));
                })
                ->with('client')
                ->chunkById(100, function ($records) use ($user) {
                    foreach ($records as $r) {
                        if (! DeadlineCalculator::needsAlert($r)) {
                            continue;
                        }
                        self::send($user, $r, 'compliance', $r->urgency.': '.$r->requirement, self::deadlineKey($r), [
                            'event' => 'compliance.deadline',
                            'due_date' => $r->due_date->toDateString(),
                            'submission_deadline' => $r->submission_deadline?->toDateString(),
                            'urgency' => $r->urgency,
                        ]);
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
