<?php

namespace App\Services\Accounting;

use App\Models\Client;
use Closure;
use Illuminate\Support\Facades\DB;

final class AccountingTransaction
{
    public static function forClient(int $clientId, Closure $operation): mixed
    {
        return DB::transaction(function () use ($clientId, $operation) {
            if (DB::getDriverName() === 'sqlite') {
                // SQLite ignores FOR UPDATE. Acquire the writer lock before any
                // overlap/history reads, including when nested in a transaction.
                DB::update('UPDATE clients SET id = id WHERE id = ?', [$clientId]);
            }
            Client::whereKey($clientId)->lockForUpdate()->firstOrFail();

            return $operation();
        });
    }
}
