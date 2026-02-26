<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    */
    'default' => env('MAIL_MAILER', 'smtp'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    */
    'mailers' => [

        // Mailer default (lo podés dejar como base / fallback)
        'smtp' => [
            'transport' => 'smtp',
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', 'smtp.mailgun.org'),
            'port' => env('MAIL_PORT', 587),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Mailer: PEDIDOS (Hostinger - pedidos@ferrear.com.ar)
        |--------------------------------------------------------------------------
        */
        'pedidos' => [
            'transport' => env('MAIL_MAILER_PEDIDOS', 'smtp'),
            'host' => env('MAIL_HOST_PEDIDOS', 'smtp.hostinger.com'),
            'port' => env('MAIL_PORT_PEDIDOS', 587),
            'encryption' => env('MAIL_ENCRYPTION_PEDIDOS', 'tls'),
            'username' => env('MAIL_USERNAME_PEDIDOS'),
            'password' => env('MAIL_PASSWORD_PEDIDOS'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN_PEDIDOS'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Mailer: PAGOS (Hostinger - pagos@ferrear.com.ar)
        |--------------------------------------------------------------------------
        */
        'pagos' => [
            'transport' => env('MAIL_MAILER_PAGOS', 'smtp'),
            'host' => env('MAIL_HOST_PAGOS', 'smtp.hostinger.com'),
            'port' => env('MAIL_PORT_PAGOS', 587),
            'encryption' => env('MAIL_ENCRYPTION_PAGOS', 'tls'),
            'username' => env('MAIL_USERNAME_PAGOS'),
            'password' => env('MAIL_PASSWORD_PAGOS'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN_PAGOS'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Mailer: VERIFICACIONES (Hostinger - verificacionesopt@ferrear.com.ar)
        |--------------------------------------------------------------------------
        */
        'verificaciones' => [
            'transport' => env('MAIL_MAILER_VERIF', 'smtp'),
            'host' => env('MAIL_HOST_VERIF', 'smtp.hostinger.com'),
            'port' => env('MAIL_PORT_VERIF', 587),
            'encryption' => env('MAIL_ENCRYPTION_VERIF', 'tls'),
            'username' => env('MAIL_USERNAME_VERIF'),
            'password' => env('MAIL_PASSWORD_VERIF'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN_VERIF'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Mailer: CONTACTO (Hostinger - contacto@ferrear.com.ar)
        |--------------------------------------------------------------------------
        */
        'contacto' => [
            'transport' => env('MAIL_MAILER_CONTACTO', 'smtp'),
            'host' => env('MAIL_HOST_CONTACTO', 'smtp.hostinger.com'),
            'port' => env('MAIL_PORT_CONTACTO', 587),
            'encryption' => env('MAIL_ENCRYPTION_CONTACTO', 'tls'),
            'username' => env('MAIL_USERNAME_CONTACTO'),
            'password' => env('MAIL_PASSWORD_CONTACTO'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN_CONTACTO'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Mailer: RRHH / POSTULACIONES (Hostinger - rrhh@ferrear.com.ar)
        |--------------------------------------------------------------------------
        */
        'rrhh' => [
            'transport' => env('MAIL_MAILER_RRHH', 'smtp'),
            'host' => env('MAIL_HOST_RRHH', 'smtp.hostinger.com'),
            'port' => env('MAIL_PORT_RRHH', 587),
            'encryption' => env('MAIL_ENCRYPTION_RRHH', 'tls'),
            'username' => env('MAIL_USERNAME_RRHH'),
            'password' => env('MAIL_PASSWORD_RRHH'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN_RRHH'),
        ],

        // --- lo demás lo dejás tal cual ---

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
        ],

        'mailgun' => [
            'transport' => 'mailgun',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => ['smtp', 'log'],
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => ['ses', 'postmark'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    | OJO: este "from" es el default. Para evitar líos, conviene que coincida
    | con tu mailer default (smtp). Si vas a usar siempre pedidos/pagos con
    | `Mail::mailer()`, este from casi no se usa.
    */
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'no-reply@ferrear.com.ar'),
        'name' => env('MAIL_FROM_NAME', 'Ferretería Argentina'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ferretería - Configuración propia
    |--------------------------------------------------------------------------
    */
    'ferreteria' => [
        // Mail que recibe notificaciones de pedidos (interno)
        'notif_email' => env('FERRETERIA_NOTIF_EMAIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Markdown Mail Settings
    |--------------------------------------------------------------------------
    */
    'markdown' => [
        'theme' => 'default',
        'paths' => [
            resource_path('views/vendor/mail'),
        ],
    ],

];
