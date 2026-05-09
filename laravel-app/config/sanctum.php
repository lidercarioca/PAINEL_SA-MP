<?php

return [
    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost')),
    'expiration' => null,
    'middleware' => [
        'verify_csrf_token' => [
            App\Http\Middleware\VerifyCsrfToken::class,
        ],
        'ensure_front_cookie' => [
            Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ],
    ],
];
