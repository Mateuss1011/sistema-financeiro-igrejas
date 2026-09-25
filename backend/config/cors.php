<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    // CORS restrito (Fase 13): só os métodos que a API expõe e só os cabeçalhos que o frontend realmente envia.
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],

    // Origens explícitas (nunca '*': com credenciais o navegador nem aceitaria). Em produção defina CORS_ALLOWED_ORIGINS
    // com a origem HTTPS do frontend, separadas por vírgula; o padrão é só o servidor de desenvolvimento do Vite.
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173'))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Content-Type', 'X-XSRF-TOKEN', 'Idempotency-Key'],

    'exposed_headers' => ['Idempotent-Replayed', 'Content-Disposition'],

    'max_age' => 600,

    'supports_credentials' => true,

];
