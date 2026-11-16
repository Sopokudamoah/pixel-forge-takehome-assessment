<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\SlackNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendSlackNotificationJob implements ShouldQueue
{
    use Queueable;

    public $tries = 3;
    public $backoff = [2, 5, 10];

    public function __construct(
        public Generation $generation,
        public string $event = 'started' // 'started', 'completed', 'failed'
    ) {
    }

    public function handle(SlackNotificationService $slackService): void
    {
        try {
            $this->generation->loadMissing('user');

            match ($this->event) {
                'started' => $slackService->sendGenerationStarted($this->generation),
                'completed' => $slackService->sendGenerationCompleted($this->generation),
                'failed' => $slackService->sendGenerationFailed($this->generation),
                default => Log::warning("Unknown Slack notification event type: {$this->event}"),
            };
        } catch (\Throwable $e) {
            Log::warning('SendSlackNotificationJob error: ' . $e->getMessage(), [
                'exception' => $e,
                'generation_id' => $this->generation->id,
                'event' => $this->event,
            ]);
        }
    }
}
