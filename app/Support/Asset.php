<?php

namespace App\Support;

final class Asset
{
    public static function versioned(string $path): string
    {
        // Content versions also change when deployments preserve file timestamps.
        return asset($path).'?v='.substr(hash_file('sha256', public_path($path)), 0, 16);
    }
}
