<?php

namespace App\Jobs;

use App\Models\GeminiJob;
use App\Services\GeminiApiService;
use App\Services\GeminiEventPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessGeminiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    /**
     * @var array<int, int>
     */
    public array $backoff;

    public function __construct(public readonly string $jobId)
    {
        $this->tries = max(1, (int) config('gemini.job_tries', 3));
        $this->timeout = max(30, (int) config('gemini.job_timeout', 1800));
        $configuredBackoff = config('gemini.job_backoff', [15, 60, 180]);
        $this->backoff = is_array($configuredBackoff) ? array_values(array_map('intval', $configuredBackoff)) : [15, 60, 180];

        $this->onQueue((string) config('gemini.queue_name', 'gemini-process'));
    }

    public function handle(GeminiApiService $geminiApiService, GeminiEventPublisher $eventPublisher): void
    {
        $startedAt = microtime(true);
        $job = GeminiJob::query()->find($this->jobId);
        if (! $job) {
            Log::warning('gemini.job.missing', [
                'job_id' => $this->jobId,
            ]);
            return;
        }
        if ($job->status === GeminiJob::STATUS_DONE) {
            Log::info('gemini.job.skip_done', [
                'job_id' => (string) $job->id,
            ]);
            return;
        }

        $job->status = GeminiJob::STATUS_PROCESSING;
        $job->attempt = max(0, (int) $job->attempt) + 1;
        $job->started_at = $job->started_at ?: now();
        $job->finished_at = null;
        $job->error_message = null;
        $job->save();

        Log::info('gemini.job.processing_started', [
            'job_id' => (string) $job->id,
            'attempt' => (int) $job->attempt,
            'source_system' => (string) ($job->source_system ?? ''),
            'source_entity_type' => (string) ($job->source_entity_type ?? ''),
            'source_entity_id' => $job->source_entity_id !== null ? (int) $job->source_entity_id : null,
            'model' => (string) ($job->model ?: config('gemini.image_model', '')),
            'aspect_ratio' => (string) ($job->aspect_ratio ?? ''),
            'image_size' => (string) ($job->image_size ?? ''),
            'input_images_count' => count(is_array($job->input_images) ? $job->input_images : []),
            'prompt_chars' => mb_strlen((string) $job->prompt),
        ]);

        $eventPublisher->publish('job.processing', $job);

        $result = $geminiApiService->generateImage([
            'prompt' => (string) $job->prompt,
            'model' => (string) ($job->model ?: config('gemini.image_model', '')),
            'aspect_ratio' => (string) ($job->aspect_ratio ?? ''),
            'image_size' => (string) ($job->image_size ?? ''),
            'images' => is_array($job->input_images) ? $job->input_images : [],
            'trace_job_id' => (string) $job->id,
            'trace_attempt' => (int) $job->attempt,
        ]);

        $resultBinary = base64_decode((string) ($result['base64_data'] ?? ''), true);
        if (! is_string($resultBinary) || $resultBinary === '') {
            throw new \RuntimeException('Gemini returned invalid base64 payload.');
        }

        $size = @getimagesizefromstring($resultBinary);
        $resultWidth = is_array($size) ? (int) ($size[0] ?? 0) : 0;
        $resultHeight = is_array($size) ? (int) ($size[1] ?? 0) : 0;
        if ($resultWidth <= 0 || $resultHeight <= 0) {
            throw new \RuntimeException('Unable to read generated image dimensions.');
        }

        $job->status = GeminiJob::STATUS_DONE;
        $job->result_mime_type = (string) ($result['mime_type'] ?? 'image/png');
        $job->result_base64_data = (string) ($result['base64_data'] ?? '');
        $job->result_text = (string) ($result['text'] ?? '');
        $job->result_width = $resultWidth;
        $job->result_height = $resultHeight;
        $job->result_size_bytes = strlen($resultBinary);
        $job->response_id = (string) ($result['response_id'] ?? '');
        $job->model_version = (string) ($result['model_version'] ?? '');
        $job->error_message = null;
        $job->finished_at = now();
        $job->save();

        Log::info('gemini.job.processing_completed', [
            'job_id' => (string) $job->id,
            'attempt' => (int) $job->attempt,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'result_mime_type' => (string) ($job->result_mime_type ?? ''),
            'result_width' => (int) ($job->result_width ?? 0),
            'result_height' => (int) ($job->result_height ?? 0),
            'result_size_bytes' => (int) ($job->result_size_bytes ?? 0),
            'response_id' => (string) ($job->response_id ?? ''),
            'model_version' => (string) ($job->model_version ?? ''),
        ]);

        $eventPublisher->publish('job.completed', $job);
    }

    public function failed(?Throwable $exception): void
    {
        $job = GeminiJob::query()->find($this->jobId);
        if (! $job) {
            return;
        }
        if ($job->status === GeminiJob::STATUS_DONE) {
            return;
        }

        $job->status = GeminiJob::STATUS_FAILED;
        $job->error_message = $exception?->getMessage() ?: (string) ($job->error_message ?: 'Gemini processing failed.');
        $job->finished_at = now();
        $job->save();

        Log::error('gemini.job.processing_failed', [
            'job_id' => (string) $job->id,
            'attempt' => (int) ($job->attempt ?? 0),
            'error' => (string) ($job->error_message ?? ''),
        ]);

        app(GeminiEventPublisher::class)->publish('job.failed', $job);
    }
}
