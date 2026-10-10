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
        // Divide before taking the absolute value so PHP_INT_MIN stays an integer.
        return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', abs(intdiv($cents, 100)), abs($cents % 100));
    }

    public static function addCents(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right) || ($right < 0 && $left < PHP_INT_MIN - $right)) {
            throw ValidationException::withMessages(['ledger' => 'Ledger totals exceed the supported integer-cent range. No partial balance can be displayed.']);
        }

        return $left + $right;
    }

    public static function subtractCents(int $left, int $right): int
    {
        if (($right > 0 && $left < PHP_INT_MIN + $right) || ($right < 0 && $left > PHP_INT_MAX + $right)) {
            throw ValidationException::withMessages(['ledger' => 'Ledger totals exceed the supported integer-cent range. No partial balance can be displayed.']);
        }

        return $left - $right;
    }

    public static function balance(int $cents): string
    {
        return self::formatExact(ltrim(self::decimal($cents), '-')).($cents > 0 ? ' Dr' : ($cents < 0 ? ' Cr' : ''));
    }

    // Read the full signed DECIMAL(15,2) journal storage range without relaxing
    // the stricter non-negative limits used to validate new user input.
    public static function storedCents(string $value): int
    {
        if (! preg_match('/^(-?)(\d{1,13})\.(\d{2})$/', $value, $matches)) {
            throw ValidationException::withMessages(['amount' => 'Invalid stored monetary amount.']);
        }
        $cents = (int) $matches[2] * 100 + (int) $matches[3];

        return $matches[1] === '-' ? -$cents : $cents;
    }

    public static function format($value): string
    {
        return '₱'.number_format((float) $value, 2);
    }

    public static function formatExact(string $decimal): string
    {
        [$whole, $fraction] = explode('.', $decimal);

        return '₱'.preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).'.'.$fraction;
    }
}
