<?php

return [
    'enabled' => env('VERITAS_DEMO_DATA', false),
    'passwords' => [
        'super-admin' => env('VERITAS_DEMO_SUPER_ADMIN_PASSWORD'),
        'owner' => env('VERITAS_DEMO_OWNER_PASSWORD'),
        'office-manager' => env('VERITAS_DEMO_OFFICE_MANAGER_PASSWORD'),
        'bookkeeper' => env('VERITAS_DEMO_BOOKKEEPER_PASSWORD'),
    ],
];
