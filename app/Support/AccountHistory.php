<?php

namespace App\Support;

final class AccountHistory
{
    public static function description(string $description): string
    {
        // Format the historical snapshot for display without rewriting audit records.
        return preg_replace_callback('/\{(?:[^"{}]|"(?:\\\\.|[^"\\\\])*")*\}/s', function (array $match): string {
            $fields = json_decode($match[0], true);
            if (! is_array($fields) || count($fields) !== 3) {
                return $match[0];
            }

            $parts = [];
            foreach (['code' => 'Code', 'name' => 'Name', 'classification' => 'Classification'] as $key => $label) {
                if (! isset($fields[$key]) || ! is_string($fields[$key])) {
                    return $match[0];
                }
                $parts[] = $label.': '.$fields[$key];
            }

            return implode(' · ', $parts);
        }, $description) ?? $description;
    }
}
