<?php

namespace App\Jobs;

use App\Models\GeminiJob;
use App\Services\GeminiEventPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishGeminiJobHeartbeat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly string $jobId,
        public readonly int $sequence = 1
    ) {
        $this->onConnection(ProcessGeminiJob::queueConnection());
        $this->onQueue(ProcessGeminiJob::queueName());
    }

    public function handle(GeminiEventPublisher $eventPublisher): void
    {
        $job = GeminiJob::query()->find($this->jobId);
        if (! $job) {
            return;
        }

        if (in_array((string) $job->status, [GeminiJob::STATUS_DONE, GeminiJob::STATUS_FAILED, GeminiJob::STATUS_CANCELLED], true)) {
            return;
        }

        $eventPublisher->publish('job.heartbeat', $job, [
            'heartbeat' => [
                'sequence' => max(1, (int) $this->sequence),
            ],
        ]);

        $maxMessages = max(1, (int) config('gemini.heartbeat.max_messages', 240));
        if ((int) $this->sequence >= $maxMessages) {
            return;
        }

        $intervalSeconds = max(5, (int) config('gemini.heartbeat.interval_seconds', 60));
        $jitterSeconds = max(0, (int) floor($intervalSeconds * 0.2));
        $delaySeconds = $intervalSeconds + ($jitterSeconds > 0 ? random_int(0, $jitterSeconds) : 0);
        self::dispatch($this->jobId, (int) $this->sequence + 1)
            ->delay(now()->addSeconds($delaySeconds))
            ->onConnection(ProcessGeminiJob::queueConnection())
            ->onQueue(ProcessGeminiJob::queueName());
    }
}
