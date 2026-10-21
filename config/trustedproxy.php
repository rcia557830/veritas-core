<?php

return [
    // Laravel's TrustProxies middleware reads this cached configuration. Reading
    // env() in bootstrap/app.php loses .env-only values after config:cache.
    'proxies' => env('TRUSTED_PROXIES'),
];
