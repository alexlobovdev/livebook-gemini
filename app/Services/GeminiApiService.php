<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiApiService
{
    private function shouldRetry(\Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException || ! $exception->response) {
            return false;
        }

        $status = $exception->response->status();

        return $status === 429 || $status >= 500;
    }

    private function baseRequest(): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout((int) config('gemini.connect_timeout', 15))
            ->timeout((int) config('gemini.request_timeout', 600))
            ->retry(
                max(0, (int) config('gemini.request_retries', 2)),
                max(0, (int) config('gemini.retry_delay_ms', 500)),
                fn (\Throwable $e): bool => $this->shouldRetry($e)
            );
    }

    /**
     * @param  array{
     *   prompt: string,
     *   images?: ?array<int, array{mime_type:string,base64_data:string}>,
     *   model?: ?string,
     *   aspect_ratio?: ?string,
     *   image_size?: ?string,
     *   trace_job_id?: ?string,
     *   trace_attempt?: ?int
     * }  $payload
     * @return array{
     *   mime_type: string,
     *   base64_data: string,
     *   text: string,
     *   response_id: string,
     *   model_version: string
     * }
     */
    public function generateImage(array $payload): array
    {
        $traceJobId = trim((string) ($payload['trace_job_id'] ?? ''));
        $traceAttempt = (int) ($payload['trace_attempt'] ?? 0);
        $requestStartedAt = microtime(true);

        $apiKey = trim((string) config('gemini.api_key', ''));
        if ($apiKey === '') {
            throw new \RuntimeException('GEMINI_API_KEY is not configured on gemini service.');
        }

        $model = trim((string) ($payload['model'] ?? config('gemini.image_model', 'gemini-3.1-flash-image-preview')));
        if ($model === '') {
            throw new \RuntimeException('Gemini model is not configured.');
        }

        $prompt = trim((string) ($payload['prompt'] ?? ''));
        if ($prompt === '') {
            throw new \RuntimeException('Prompt is required.');
        }

        $images = is_array($payload['images'] ?? null) ? $payload['images'] : [];
        $inputImages = [];
        foreach ($images as $item) {
            if (! is_array($item)) {
                continue;
            }
            $itemBase64 = trim((string) ($item['base64_data'] ?? ''));
            $itemMime = trim((string) ($item['mime_type'] ?? ''));
            if ($itemBase64 === '') {
                continue;
            }
            if ($itemMime === '') {
                throw new \RuntimeException('Image mime type is required.');
            }
            $inputImages[] = [
                'mime_type' => $itemMime,
                'base64_data' => $itemBase64,
            ];
        }

        $aspectRatio = trim((string) ($payload['aspect_ratio'] ?? ''));
        if ($aspectRatio !== '' && ! preg_match('/^\d+:\d+$/', $aspectRatio)) {
            $aspectRatio = '';
        }

        $imageSize = strtoupper(trim((string) ($payload['image_size'] ?? '')));
        if (! in_array($imageSize, ['512', '1K', '2K', '4K'], true)) {
            $imageSize = '';
        }

        $generationConfig = [];
        $imageConfig = [];
        if ($aspectRatio !== '') {
            $imageConfig['aspectRatio'] = $aspectRatio;
        }
        if ($imageSize !== '') {
            $imageConfig['imageSize'] = $imageSize;
        }
        if ($imageConfig !== []) {
            $generationConfig['imageConfig'] = $imageConfig;
        }

        Log::info('gemini.api.request_started', [
            'job_id' => $traceJobId,
            'attempt' => $traceAttempt > 0 ? $traceAttempt : null,
            'model' => $model,
            'aspect_ratio' => $aspectRatio,
            'image_size' => $imageSize,
            'images_count' => count($inputImages),
            'prompt_chars' => mb_strlen($prompt),
        ]);

        $parts = [
            ['text' => $prompt],
        ];
        foreach ($inputImages as $inputImage) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => (string) ($inputImage['mime_type'] ?? ''),
                    'data' => (string) ($inputImage['base64_data'] ?? ''),
                ],
            ];
        }
        $requestPayload = [
            'contents' => [[
                'parts' => $parts,
            ]],
        ];
        if ($generationConfig !== []) {
            $requestPayload['generationConfig'] = $generationConfig;
        }

        $endpoint = rtrim((string) config('gemini.api_base', 'https://generativelanguage.googleapis.com/v1beta'), '/')
            .'/models/'.rawurlencode($model).':generateContent';

        $response = $this->baseRequest()
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->post($endpoint, $requestPayload);

        $body = $response->json();
        $payloadResponse = is_array($body) ? $body : [];
        Log::info('gemini.api.response_received', [
            'job_id' => $traceJobId,
            'attempt' => $traceAttempt > 0 ? $traceAttempt : null,
            'status_code' => (int) $response->status(),
            'duration_ms' => (int) round((microtime(true) - $requestStartedAt) * 1000),
        ]);
        if (! $response->successful()) {
            $error = $payloadResponse['error'] ?? null;
            if (is_array($error)) {
                $message = trim((string) ($error['message'] ?? ''));
                if ($message !== '') {
                    Log::error('gemini.api.request_failed', [
                        'job_id' => $traceJobId,
                        'attempt' => $traceAttempt > 0 ? $traceAttempt : null,
                        'status_code' => (int) $response->status(),
                        'error' => $message,
                    ]);
                    throw new \RuntimeException($message);
                }
            }
            Log::error('gemini.api.request_failed', [
                'job_id' => $traceJobId,
                'attempt' => $traceAttempt > 0 ? $traceAttempt : null,
                'status_code' => (int) $response->status(),
                'error' => 'Gemini API request failed.',
            ]);
            throw new \RuntimeException('Gemini API request failed.');
        }

        $parts = [];
        $candidates = is_array($payloadResponse['candidates'] ?? null) ? $payloadResponse['candidates'] : [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $content = $candidate['content'] ?? null;
            if (! is_array($content)) {
                continue;
            }
            $candidateParts = is_array($content['parts'] ?? null) ? $content['parts'] : [];
            foreach ($candidateParts as $part) {
                if (is_array($part)) {
                    $parts[] = $part;
                }
            }
        }

        $imagePart = null;
        foreach ($parts as $part) {
            $inline = null;
            if (isset($part['inlineData']) && is_array($part['inlineData'])) {
                $inline = $part['inlineData'];
            } elseif (isset($part['inline_data']) && is_array($part['inline_data'])) {
                $inline = $part['inline_data'];
            }
            $data = trim((string) (($inline['data'] ?? '')));
            if ($data !== '') {
                $imagePart = $part;
                break;
            }
        }

        if (! $imagePart) {
            $texts = [];
            foreach ($parts as $part) {
                $text = trim((string) ($part['text'] ?? ''));
                if ($text !== '') {
                    $texts[] = $text;
                }
            }
            if ($texts !== []) {
                throw new \RuntimeException('Gemini response without image: '.implode("\n", $texts));
            }

            throw new \RuntimeException('Gemini returned response without image.');
        }

        $inline = [];
        if (isset($imagePart['inlineData']) && is_array($imagePart['inlineData'])) {
            $inline = $imagePart['inlineData'];
        } elseif (isset($imagePart['inline_data']) && is_array($imagePart['inline_data'])) {
            $inline = $imagePart['inline_data'];
        }

        $outputMimeType = trim((string) ($inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png'));
        $outputBase64 = trim((string) ($inline['data'] ?? ''));
        if ($outputBase64 === '') {
            throw new \RuntimeException('Gemini returned empty image payload.');
        }

        $texts = [];
        foreach ($parts as $part) {
            $text = trim((string) ($part['text'] ?? ''));
            if ($text !== '') {
                $texts[] = $text;
            }
        }

        Log::info('gemini.api.request_completed', [
            'job_id' => $traceJobId,
            'attempt' => $traceAttempt > 0 ? $traceAttempt : null,
            'response_id' => trim((string) ($payloadResponse['responseId'] ?? $payloadResponse['response_id'] ?? '')),
            'model_version' => trim((string) ($payloadResponse['modelVersion'] ?? $payloadResponse['model_version'] ?? $model)),
            'output_mime_type' => $outputMimeType,
            'output_base64_size' => strlen($outputBase64),
        ]);

        return [
            'mime_type' => $outputMimeType,
            'base64_data' => $outputBase64,
            'text' => implode("\n", $texts),
            'response_id' => trim((string) ($payloadResponse['responseId'] ?? $payloadResponse['response_id'] ?? '')),
            'model_version' => trim((string) ($payloadResponse['modelVersion'] ?? $payloadResponse['model_version'] ?? $model)),
        ];
    }
}
