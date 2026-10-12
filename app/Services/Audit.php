<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\DocumentRequirement;
use App\Models\LedgerEntry;
use App\Models\Notice;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    public static function visible(): Builder
    {
        $user = auth()->user();
        $query = AuditLog::query();
        if ($user->hasRole('owner') && $user->hasPermission('audit.view')) {
            return $query->whereNotIn('module', ['auth', 'users', 'workspace']);
        }

        // Recent-activity cards must not expose records from restricted modules.
        return $query->where(function ($q) use ($user) {
            $q->whereRaw('1 = 0');
            $modules = Modules::all();
            $modules['notices'] = ['model' => Notice::class];
            $modules['requirements'] = ['model' => DocumentRequirement::class];
            foreach ($modules as $module => $config) {
                if (! $user->hasPermission(Modules::permissionFor($config['model']).'.view')) {
                    continue;
                }
                $q->orWhere(fn ($records) => $records->where('module', $module)->whereIn('record_id', Access::query($config['model'], $user)->select('id')));
            }
        });
    }

    public static function snapshot(Model $record): array
    {
        // Explicit allowlist: never serialize credentials, tokens or file contents.
        $state = $record->only(['id', 'client_id', 'status', 'reference_number', 'transaction_date',
            'description', 'created_by', 'reviewed_by', 'reviewed_at', 'posted_by', 'posted_at',
            'review_digest', 'accounting_period_id', 'label', 'starts_on', 'ends_on', 'closed_by', 'closed_at']);
        if ($record instanceof LedgerEntry && $record->exists) {
            $state['lines'] = $record->items()->orderBy('id')->get()->map(fn ($line) => $line->only(['id', 'account_id', 'account_name', 'debit', 'credit']))->all();
            $state['voucher'] = $record->voucher()->first()?->only(['id', 'type', 'reference', 'amount', 'cash_account_id', 'party', 'check_number', 'check_date']);
            $state['evidence_ids'] = $record->evidence()->orderBy('id')->pluck('id')->all();
        }

        return $state;
    }

    public static function record(string $action, string $module, ?Model $record, string $description, ?array $before = null): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'client_id' => $record instanceof Client ? $record->id : $record?->client_id,
            'event_class' => in_array($module, ['auth', 'users', 'workspace']) ? 'technical' : (in_array($module, ['ledger', 'accounts', 'accounting-periods', 'billing']) ? 'financial' : 'business'),
            'entity_type' => $record ? get_class($record) : null,
            'evidence' => $record ? ['before' => $before, 'after' => self::snapshot($record)] : null,
            'action' => $action, 'module' => $module, 'record_id' => $record?->id, 'description' => $description, 'ip_address' => request()->ip()]);
    }
}
