<?php

return [
    // These flags affect fresh migrations only; never use with real accounting data.
    'enabled' => env('DEMO_MODE', false),
    'skip_accounting_triggers' => env('DEMO_SKIP_ACCOUNTING_TRIGGERS', false),
];
