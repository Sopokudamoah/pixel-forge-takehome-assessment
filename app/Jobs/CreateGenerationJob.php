<?php

namespace App\Jobs;

use App\Actions\GenerationStateMachine;
use App\Http\Client\PixelforgeApiException;
use App\Http\Client\PixelforgeClient;
use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CreateGenerationJob implements ShouldQueue
{
    use Queueable;

    public $tries = 5;
    public $backoff = [1, 2, 4, 8, 16];

    public function __construct(public Generation $generation) {
    }

    public function handle(PixelforgeClient $client, GenerationStateMachine $stateMachine): void
    {
        $this->generation->refresh();
        if ($this->generation->isTerminal()) {
            return;
        }

        $params = [
            'prompt' => $this->generation->prompt,
            'size' => $this->generation->size,
            'quality' => $this->generation->quality,
            'metadata' => ['generation_id' => $this->generation->id],
        ];

        if ($this->generation->reference_images) {
            $params['reference_images'] = $this->generation->reference_images;
        }

        try {
            $response = $client->createGeneration($params, $this->generation->idempotency_key);

            if (isset($response['id'])) {
                $this->generation->provider_id = $response['id'];
                $this->generation->save();
            }
        } catch (PixelforgeApiException $e) {
            if ($e->isTerminal()) {
                $stateMachine->transition($this->generation, 'failed', [
                    'error_type' => $e->errorType,
                    'error_message' => $e->userFriendlyMessage(),
                ]);
            } elseif ($e->isRetryable() && $this->attempts() < $this->tries) {
                throw $e;
            } elseif ($e->isRetryable()) {
                $stateMachine->transition($this->generation, 'failed', [
                    'error_type' => $e->errorType,
                    'error_message' => 'Generation failed after multiple retries: ' . $e->userFriendlyMessage(),
                ]);
            } else {
                $stateMachine->transition($this->generation, 'failed', [
                    'error_type' => $e->errorType,
                    'error_message' => $e->userFriendlyMessage(),
                ]);
            }
        }
    }
}
