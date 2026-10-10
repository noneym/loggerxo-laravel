<?php

return [
    // https://lx_xxx@loggerxo.com  (project key @ host). Empty DSN disables the SDK.
    'dsn' => env('LOGGERXO_DSN'),

    'environment' => env('LOGGERXO_ENVIRONMENT', env('APP_ENV', 'production')),
    'release' => env('LOGGERXO_RELEASE'),
    'server_name' => env('LOGGERXO_SERVER_NAME', gethostname() ?: null),

    // Report exceptions passed to the exception handler.
    'capture_exceptions' => env('LOGGERXO_CAPTURE_EXCEPTIONS', true),

    // Minimum level for the "loggerxo" log channel (debug..emergency).
    'log_level' => env('LOGGERXO_LOG_LEVEL', 'debug'),

    // Request / job / command transactions.
    'tracing' => env('LOGGERXO_TRACING', true),
    'traces_sample_rate' => (float) env('LOGGERXO_TRACES_SAMPLE_RATE', 1.0),
    // Record SQL query spans and breadcrumbs.
    'trace_queries' => env('LOGGERXO_TRACE_QUERIES', true),
    'slow_query_ms' => (int) env('LOGGERXO_SLOW_QUERY_MS', 0), // 0 = record all queries as spans
    'max_spans' => 150,

    // Include user e-mail / IP in events.
    'send_default_pii' => env('LOGGERXO_SEND_DEFAULT_PII', false),

    // Breadcrumbs kept in memory per request.
    'max_breadcrumbs' => 40,
    // Source lines around each in-app frame (0 disables).
    'context_lines' => 5,
    // Paths treated as "in app" (relative to base_path()).
    'in_app_include' => ['app', 'routes', 'database', 'config', 'resources', 'bootstrap'],

    // Exceptions that are never reported.
    'ignore_exceptions' => [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Illuminate\Validation\ValidationException::class,
        \Illuminate\Session\TokenMismatchException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
        \Illuminate\Database\Eloquent\ModelNotFoundException::class,
    ],

    // Transactions for these route names / URIs are skipped.
    'ignore_transactions' => ['up', 'livewire/*', 'telescope*', 'horizon*', '_debugbar*'],

    // Request input keys that are always masked.
    'mask' => ['password', 'password_confirmation', 'current_password', 'token', 'secret', 'api_key', 'authorization', 'cookie', 'credit_card', 'card_number', 'cvv'],

    // Transport
    'timeout' => (float) env('LOGGERXO_TIMEOUT', 3.0),
    'queue' => env('LOGGERXO_QUEUE', false),            // true = dispatch envelopes to a queue job
    'queue_name' => env('LOGGERXO_QUEUE_NAME', 'default'),
    'gzip' => env('LOGGERXO_GZIP', true),
    'max_events_per_envelope' => 100,
];
