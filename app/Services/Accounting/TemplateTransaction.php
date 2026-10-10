<?php

namespace App\Services\Accounting;

use App\Models\AccountTemplate;
use Closure;
use Illuminate\Support\Facades\DB;

final class TemplateTransaction
{
    public static function run(int $id, Closure $operation): mixed
    {
        return DB::transaction(function () use ($id, $operation) {
            if (DB::getDriverName() === 'sqlite') {
                DB::update('UPDATE account_templates SET id = id WHERE id = ?', [$id]);
            }

            return $operation(AccountTemplate::whereKey($id)->lockForUpdate()->firstOrFail());
        });
    }
}
