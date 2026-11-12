<?php

return [
    'admin' => [
        'name' => env('SEED_ADMIN_NAME', 'Administrator'),
        'email' => env('SEED_ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('SEED_ADMIN_PASSWORD'),
    ],
    'staff' => [
        'name' => env('SEED_STAFF_NAME', 'Staff User'),
        'email' => env('SEED_STAFF_EMAIL', 'staff@example.com'),
        'password' => env('SEED_STAFF_PASSWORD'),
    ],
];
