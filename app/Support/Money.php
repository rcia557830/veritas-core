<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class Money
{
    public static function cents(string|int|float|null $value): int
    {
        $value = (string) ($value ?? '0');
        if (! preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'Use a non-negative amount with at most two decimal places.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public static function decimal(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    public static function format($value): string
    {
        return '₱'.number_format((float) $value, 2);
    }
}
