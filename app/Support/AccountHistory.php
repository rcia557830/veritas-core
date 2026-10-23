<?php

namespace App\Support;

final class AccountHistory
{
    public static function description(string $description): string
    {
        return AuditHistory::description($description);
    }
}
