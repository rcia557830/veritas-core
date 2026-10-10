<?php

namespace App\Support;

class Display
{
    public static function value($record, string $key): string
    {
        $value = match ($key) {
            'client' => $record->client?->business_name,'status' => $record->display_status ?? $record->status,default => $record->$key
        };
        if ($value instanceof \DateTimeInterface) {
            return $value->format('M j, Y');
        }
        if (is_array($value)) {
            return implode(', ', $value);
        }
        if (in_array($key, ['tax', 'total_amount', 'balance', 'amount_paid'])) {
            return Money::format($value ?? 0);
        }

        return (string) ($value ?? '—');
    }

    public static function tone(string $status): string
    {
        return match ($status) {
            'Paid','Reviewed','Approved','Filed','Active','Published','Verified' => 'success','Overdue','Needs Clarification','Rejected','Needs Correction','Incomplete' => 'danger','Draft','Open','Pending','Inactive','Archived','For Review','Awaiting verification','Not submitted','Missing' => 'warning',default => 'info'
        };
    }
}
