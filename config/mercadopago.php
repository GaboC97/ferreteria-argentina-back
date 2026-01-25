<?php

return [
    'access_token' => trim((string) env('MP_ACCESS_TOKEN', '')),
    'currency_id' => trim((string) env('MP_CURRENCY_ID', 'ARS')),

    'back_urls' => [
        'success' => trim((string) env('MP_BACK_SUCCESS_URL', '')),
        'pending' => trim((string) env('MP_BACK_PENDING_URL', '')),
        'failure' => trim((string) env('MP_BACK_FAILURE_URL', '')),
    ],

    'webhook_url' => trim((string) env('MP_WEBHOOK_URL', '')),
];
