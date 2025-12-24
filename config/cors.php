<?php

// config/cors.php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', '*'],
    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:8080', 
        'https://lovable.dev', 
        'https://a752ad05-ccce-44ac-8ca0-df596e5bbcb8.lovableproject.com',
        'https://id-preview--a752ad05-ccce-44ac-8ca0-df596e5bbcb8.lovable.app',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
;