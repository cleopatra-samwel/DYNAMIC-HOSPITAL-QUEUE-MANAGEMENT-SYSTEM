<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | We authenticate the SPA with Sanctum personal access tokens sent as a
    | Bearer header (not the cookie-based SPA guard), so credentials do not
    | need to be shared across origins. This still needs to be permissive
    | enough for the Vite dev server / production frontend origin to call
    | the API and read the Authorization header exchange.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', env('FRONTEND_URL', 'http://localhost:5173'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];