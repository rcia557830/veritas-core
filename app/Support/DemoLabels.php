<?php

namespace App\Support;

final class DemoLabels
{
    /** Remove label affixes, never occurrences inside substantive text or warnings. */
    public static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $clean = preg_replace('/^(?:(?:Fictional|Demo|Dummy|Sample|Test)\s*:\s*|(?:Fictional|Demo|Dummy|Sample|Test)\s+)+/i', '', $value);
        $clean = preg_replace('/\s*\((?:Fictional|Demo|Dummy|Sample|Test)\)\s*$/i', '', $clean);

        return trim($clean) !== '' ? trim($clean) : $value;
    }

    /** Presentation only: historical accounting payloads and review hashes stay intact. */
    public static function display(?string $value): ?string
    {
        try {
            DemoDatabase::check();
        } catch (\RuntimeException) {
            return $value;
        }

        return self::clean($value);
    }
}
