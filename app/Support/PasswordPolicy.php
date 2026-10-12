<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

class PasswordPolicy
{
    public static function rules(): array
    {
        return ['required', 'string', 'confirmed', Password::min(12), 'max:72',
            function (string $attribute, mixed $value, \Closure $fail) {
                // Bcrypt accepts at most 72 bytes; never silently truncate a password.
                if (is_string($value) && strlen($value) > 72) {
                    $fail('The password is too long. Use at most 72 bytes.');
                }
            },
        ];
    }
}
