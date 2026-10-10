<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class AccountCode
{
    public static function display(string $code): string
    {
        $code = trim($code, ' ');
        if ($code === '' || mb_strlen($code) > 255 || preg_match('/[\x00-\x1F\x7F]/', $code)) {
            throw ValidationException::withMessages(['code' => 'Use a non-empty account code of at most 255 characters without control characters.']);
        }

        return $code;
    }

    public static function key(string $code): string
    {
        return hash('sha256', strtr(self::display($code), 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'));
    }
}
