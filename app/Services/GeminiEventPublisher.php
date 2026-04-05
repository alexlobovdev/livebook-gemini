<?php

namespace App\Services;

use App\Models\GeminiJob;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class GeminiEventPublisher
{
    public function publish(string $eventType, GeminiJob $job, array $extra = []): void
    {
        $stream = trim((string) config('gemini.events.stream', 'gemini:events'));

        $payload = [
            'event_id' => (string) \Illuminate\Support\Str::uuid(),
            'event_type' => $eventType,
            'event_version' => 1,
            'occurred_at' => now()->toIso8601String(),
            'job' => [
                'id' => (string) $job->id,
                'source_system' => (string) ($job->source_system ?? ''),
                'source_entity_type' => (string) ($job->source_entity_type ?? ''),
                'source_entity_id' => $job->source_entity_id !== null ? (int) $job->source_entity_id : null,
                'status' => (string) ($job->status ?? ''),
                'attempt' => (int) ($job->attempt ?? 0),
            ],
            'result' => null,
            'error' => null,
            'trace' => [
                'idempotency_key' => (string) ($job->idempotency_key ?? ''),
            ],
        ];

        if ($job->status === GeminiJob::STATUS_DONE) {
            $payload['result'] = [
                'mime_type' => (string) ($job->result_mime_type ?? ''),
                'base64_data' => (string) ($job->result_base64_data ?? ''),
                'width' => (int) ($job->result_width ?? 0),
                'height' => (int) ($job->result_height ?? 0),
                'size_bytes' => (int) ($job->result_size_bytes ?? 0),
                'text' => (string) ($job->result_text ?? ''),
                'response_id' => (string) ($job->response_id ?? ''),
                'model_version' => (string) ($job->model_version ?? ''),
            ];
        }

        if ($job->status === GeminiJob::STATUS_FAILED) {
            $payload['error'] = [
                'message' => (string) ($job->error_message ?? ''),
                'retryable' => false,
            ];
        }

        if ($job->status === GeminiJob::STATUS_CANCELLED) {
            $payload['error'] = [
                'message' => (string) ($job->error_message ?: 'Gemini job cancelled by request.'),
                'retryable' => false,
            ];
        }

        if ($extra !== []) {
            $payload = array_replace_recursive($payload, $extra);
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || $encoded === '') {
            return;
        }

        $connection = (string) config('gemini.events.redis_connection', 'default');
        $maxLen = max(1000, (int) config('gemini.events.max_len', 10000));

        if ($stream !== '') {
            try {
                Redis::connection($connection)->xadd(
                    $stream,
                    '*',
                    ['payload' => $encoded],
                    $maxLen,
                    true
                );

                Log::info('gemini.events.published', [
                    'event_type' => $eventType,
                    'stream' => $stream,
                    'job_id' => (string) $job->id,
                    'status' => (string) ($job->status ?? ''),
                    'attempt' => (int) ($job->attempt ?? 0),
                ]);
            } catch (\Throwable $e) {
                Log::warning('gemini.events.redis_publish_failed', [
                    'event_type' => $eventType,
                    'stream' => $stream,
                    'job_id' => (string) $job->id,
                    'status' => (string) ($job->status ?? ''),
                    'attempt' => (int) ($job->attempt ?? 0),
                    'error' => mb_substr($e->getMessage(), 0, 500),
                ]);
            }
        }

        app(CrmCallbackClient::class)->send($payload);
    }
}
