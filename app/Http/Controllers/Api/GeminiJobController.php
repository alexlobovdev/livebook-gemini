<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessGeminiJob;
use App\Models\GeminiJob;
use App\Services\GeminiEventPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GeminiJobController extends Controller
{
    public function store(Request $request, GeminiEventPublisher $eventPublisher): JsonResponse
    {
        $idempotencyKey = trim((string) $request->header('X-Idempotency-Key', ''));
        if ($idempotencyKey === '') {
            abort(422, 'X-Idempotency-Key header is required.');
        }
        if (mb_strlen($idempotencyKey) > 255) {
            abort(422, 'X-Idempotency-Key is too long.');
        }

        $data = $request->validate([
            'source_system' => ['required', 'string', 'max:64'],
            'source_entity_type' => ['nullable', 'string', 'max:64'],
            'source_entity_id' => ['nullable', 'integer', 'min:1'],
            'prompt' => ['required', 'string', 'max:200000'],
            'model' => ['nullable', 'string', 'max:255'],
            'aspect_ratio' => ['nullable', 'string', 'max:16'],
            'image_size' => ['nullable', 'string', 'max:8'],
            'images' => ['nullable', 'array', 'max:10'],
            'images.*.mime_type' => ['required_with:images', 'string', 'max:128'],
            'images.*.base64_data' => ['required_with:images', 'string'],
            'meta' => ['nullable', 'array'],
        ]);

        $existing = GeminiJob::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return response()->json([
                'job' => $this->presentJob($existing),
                'idempotent_replay' => true,
            ], 200);
        }

        $job = GeminiJob::query()->create([
            'id' => (string) Str::uuid(),
            'source_system' => (string) $data['source_system'],
            'source_entity_type' => isset($data['source_entity_type']) ? (string) $data['source_entity_type'] : null,
            'source_entity_id' => isset($data['source_entity_id']) ? (int) $data['source_entity_id'] : null,
            'idempotency_key' => $idempotencyKey,
            'status' => GeminiJob::STATUS_QUEUED,
            'attempt' => 0,
            'prompt' => (string) $data['prompt'],
            'model' => trim((string) ($data['model'] ?? config('gemini.image_model', ''))),
            'aspect_ratio' => isset($data['aspect_ratio']) ? trim((string) $data['aspect_ratio']) : null,
            'image_size' => isset($data['image_size']) ? strtoupper(trim((string) $data['image_size'])) : null,
            'input_images' => is_array($data['images'] ?? null) ? $data['images'] : [],
            'meta' => is_array($data['meta'] ?? null) ? $data['meta'] : [],
            'queued_at' => now(),
        ]);

        ProcessGeminiJob::dispatch((string) $job->id);
        $eventPublisher->publish('job.queued', $job);

        return response()->json([
            'job' => $this->presentJob($job),
            'idempotent_replay' => false,
        ], 202);
    }

    public function show(string $jobId): JsonResponse
    {
        $job = GeminiJob::query()->findOrFail($jobId);

        return response()->json([
            'job' => $this->presentJob($job),
        ]);
    }

    public function retry(string $jobId): JsonResponse
    {
        $job = GeminiJob::query()->findOrFail($jobId);
        if ($job->status !== GeminiJob::STATUS_FAILED) {
            abort(409, 'Retry is available only for failed jobs.');
        }

        $job->status = GeminiJob::STATUS_QUEUED;
        $job->error_message = null;
        $job->result_mime_type = null;
        $job->result_base64_data = null;
        $job->result_text = null;
        $job->result_width = null;
        $job->result_height = null;
        $job->result_size_bytes = null;
        $job->response_id = null;
        $job->model_version = null;
        $job->queued_at = now();
        $job->started_at = null;
        $job->finished_at = null;
        $job->save();

        ProcessGeminiJob::dispatch((string) $job->id);

        return response()->json([
            'job' => $this->presentJob($job),
        ], 202);
    }

    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'gemini',
            'time' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentJob(GeminiJob $job): array
    {
        $payload = [
            'id' => (string) $job->id,
            'source_system' => (string) ($job->source_system ?? ''),
            'source_entity_type' => (string) ($job->source_entity_type ?? ''),
            'source_entity_id' => $job->source_entity_id !== null ? (int) $job->source_entity_id : null,
            'status' => (string) ($job->status ?? ''),
            'attempt' => (int) ($job->attempt ?? 0),
            'model' => (string) ($job->model ?? ''),
            'aspect_ratio' => (string) ($job->aspect_ratio ?? ''),
            'image_size' => (string) ($job->image_size ?? ''),
            'error_message' => (string) ($job->error_message ?? ''),
            'response_id' => (string) ($job->response_id ?? ''),
            'model_version' => (string) ($job->model_version ?? ''),
            'queued_at' => $job->queued_at?->toIso8601String(),
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'created_at' => $job->created_at?->toIso8601String(),
            'updated_at' => $job->updated_at?->toIso8601String(),
            'result' => null,
        ];

        if ($job->status !== GeminiJob::STATUS_DONE) {
            return $payload;
        }

        $payload['result'] = [
            'mime_type' => (string) ($job->result_mime_type ?? ''),
            'base64_data' => (string) ($job->result_base64_data ?? ''),
            'text' => (string) ($job->result_text ?? ''),
            'width' => (int) ($job->result_width ?? 0),
            'height' => (int) ($job->result_height ?? 0),
            'size_bytes' => (int) ($job->result_size_bytes ?? 0),
            'response_id' => (string) ($job->response_id ?? ''),
            'model_version' => (string) ($job->model_version ?? ''),
        ];

        return $payload;
    }
}
