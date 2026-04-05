<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CrmCallbackClient
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): void
    {
        $url = trim((string) config('gemini.crm_callback.url', ''));
        if ($url === '') {
            return;
        }

        $token = trim((string) config('gemini.crm_callback.token', ''));
        if ($token === '') {
            Log::warning('gemini.crm_callback.skipped_missing_token', [
                'url' => $url,
            ]);

            return;
        }

        try {
            $maxAttempts = 3;
            $attempt = 0;

            while ($attempt < $maxAttempts) {
                $attempt++;

                try {
                    $response = Http::acceptJson()
                        ->asJson()
                        ->withToken($token)
                        ->connectTimeout(max(1, (int) config('gemini.crm_callback.connect_timeout', 5)))
                        ->timeout(max(2, (int) config('gemini.crm_callback.timeout', 20)))
                        ->post($url, $payload);

                    if ($response->successful()) {
                        return;
                    }

                    $statusCode = (int) $response->status();
                    $retryable = $statusCode === 429 || $statusCode >= 500;
                    if ($retryable && $attempt < $maxAttempts) {
                        usleep(250_000);
                        continue;
                    }

                    Log::warning('gemini.crm_callback.request_failed', [
                        'url' => $url,
                        'status_code' => $statusCode,
                        'event_type' => (string) ($payload['event_type'] ?? ''),
                        'job_id' => (string) (($payload['job']['id'] ?? '') ?: ''),
                        'attempt' => $attempt,
                    ]);

                    return;
                } catch (\Throwable $e) {
                    if ($e instanceof ConnectionException && $attempt < $maxAttempts) {
                        usleep(250_000);
                        continue;
                    }

                    throw $e;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('gemini.crm_callback.request_error', [
                'url' => $url,
                'event_type' => (string) ($payload['event_type'] ?? ''),
                'job_id' => (string) (($payload['job']['id'] ?? '') ?: ''),
                'attempt' => 3,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);
        }
    }
}
