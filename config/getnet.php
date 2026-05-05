<?php

return [
    'client_id'      => env('GETNET_CLIENT_ID'),
    'client_secret'  => env('GETNET_CLIENT_SECRET'),
    'seller_id'      => env('GETNET_SELLER_ID'),
    'currency'       => env('GETNET_CURRENCY', 'ARS'),
    'base_url'       => env('GETNET_BASE_URL', 'https://api.globalgetnet.com'),
    'web_base_url'   => env('GETNET_WEB_BASE_URL', 'https://www.globalgetnet.com'),
    'webhook_secret' => env('GETNET_WEBHOOK_SECRET'),
    'token_ttl'      => env('GETNET_TOKEN_TTL', 3480),
];
