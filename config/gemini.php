<?php

$queueConnection = trim((string) env('GEMINI_QUEUE_CONNECTION', (string) env('QUEUE_CONNECTION', 'redis')));
if ($queueConnection === '') {
    $queueConnection = 'redis';
}

$queueName = trim((string) env('GEMINI_QUEUE_NAME', (string) env('REDIS_QUEUE', 'gemini-process')));
if ($queueName === '') {
    $queueName = 'gemini-process';
}

return [
    'service_token' => env('GEMINI_SERVICE_TOKEN', ''),

    'api_key' => env('GEMINI_API_KEY', ''),
    'api_base' => env('GEMINI_API_BASE', 'https://generativelanguage.googleapis.com/v1beta'),
    'image_model' => env('GEMINI_IMAGE_MODEL', 'gemini-3.1-flash-image-preview'),

    'request_timeout' => (int) env('GEMINI_REQUEST_TIMEOUT_SECONDS', 600),
    'connect_timeout' => (int) env('GEMINI_CONNECT_TIMEOUT_SECONDS', 15),
    'request_retries' => (int) env('GEMINI_REQUEST_RETRIES', 2),
    'retry_delay_ms' => (int) env('GEMINI_RETRY_DELAY_MS', 500),

    'queue_connection' => $queueConnection,
    'queue_name' => $queueName,
    'job_tries' => (int) env('GEMINI_JOB_TRIES', 3),
    'job_timeout' => (int) env('GEMINI_JOB_TIMEOUT_SECONDS', 1800),
    'job_backoff' => array_values(array_filter(array_map(
        static fn (string $value): int => max(0, (int) trim($value)),
        explode(',', (string) env('GEMINI_JOB_BACKOFF_SECONDS', '15,60,180'))
    ))),

    'events' => [
        'redis_connection' => env('GEMINI_EVENTS_REDIS_CONNECTION', 'default'),
        'stream' => env('GEMINI_EVENTS_STREAM', 'gemini:events'),
        'max_len' => (int) env('GEMINI_EVENTS_MAX_LEN', 10000),
    ],

    'crm_callback' => [
        'url' => env('CRM_GEMINI_CALLBACK_URL', ''),
        'token' => env('CRM_GEMINI_CALLBACK_TOKEN', ''),
        'connect_timeout' => (int) env('CRM_GEMINI_CALLBACK_CONNECT_TIMEOUT_SECONDS', 5),
        'timeout' => (int) env('CRM_GEMINI_CALLBACK_TIMEOUT_SECONDS', 20),
    ],

    'heartbeat' => [
        'interval_seconds' => (int) env('GEMINI_HEARTBEAT_INTERVAL_SECONDS', 60),
        'max_messages' => (int) env('GEMINI_HEARTBEAT_MAX_MESSAGES', 240),
    ],
];
