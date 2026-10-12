<?php

return [
    // These flags affect fresh migrations only; never use with real accounting data.
    'owner_email' => env('DEMO_OWNER_EMAIL'),
    'staff_password' => env('DEMO_STAFF_PASSWORD'),
    'enabled' => env('DEMO_MODE', false),
    'skip_accounting_triggers' => env('DEMO_SKIP_ACCOUNTING_TRIGGERS', false),
];
