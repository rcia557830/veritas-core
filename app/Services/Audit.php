<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\DocumentRequirement;
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
            return $query;
        }

        // Recent-activity cards must not expose records from restricted modules.
        return $query->where(function ($q) use ($user) {
            $q->where(fn ($own) => $own->where('module', 'auth')->where('user_id', $user->id));
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

    public static function record(string $action, string $module, ?Model $record, string $description): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'client_id' => $record instanceof Client ? $record->id : $record?->client_id,
            'action' => $action, 'module' => $module, 'record_id' => $record?->id, 'description' => $description, 'ip_address' => request()->ip()]);
    }
}
