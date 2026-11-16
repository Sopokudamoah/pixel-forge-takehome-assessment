<?php

namespace App\Http\Controllers;

use App\Actions\GenerationStateMachine;
use App\Jobs\DownloadImageJob;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(protected GenerationStateMachine $stateMachine) {
    }

    public function handle(Request $request)
    {
        $signature = $request->header('Pixelforge-Signature');
        if (!$signature) {
            return response()->json(['error' => 'Missing signature'], 400);
        }

        if (!preg_match('/t=(\d+),v1=([a-f0-9]+)/', $signature, $matches)) {
            return response()->json(['error' => 'Invalid signature format'], 400);
        }

        $timestamp = (int) $matches[1];
        $providedSignature = $matches[2];

        if (time() - $timestamp > 300) {
            return response()->json(['error' => 'Stale timestamp'], 400);
        }

        $webhookSecret = config('services.pixelforge.webhook_secret', '');
        $rawBody = $request->getContent();
        $expectedSignature = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $webhookSecret);

        if (!hash_equals($expectedSignature, $providedSignature)) {
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $payload = $request->json()->all();
        $eventType = $payload['type'] ?? $payload['event_type'] ?? '';
        $eventId = $payload['id'] ?? $payload['event_id'] ?? '';

        if ($eventType === 'ping') {
            return response()->json(['status' => 'ok']);
        }

        $eventData = $payload['data'] ?? $payload;
        $generationId = $eventData['metadata']['generation_id']
            ?? $payload['metadata']['generation_id']
            ?? $eventData['id']
            ?? $payload['id']
            ?? null;

        if (!$generationId) {
            Log::warning("Webhook received without generation_id: {$eventId}");
            return response()->json(['status' => 'ok']);
        }

        $generation = Generation::find($generationId);
        if (!$generation) {
            $generation = Generation::where('provider_id', $generationId)->first();
        }

        if (!$generation && isset($eventData['id'])) {
            $generation = Generation::where('provider_id', $eventData['id'])->first();
        }

        if (!$generation) {
            Log::warning("Webhook for unknown generation: {$generationId}");
            return response()->json(['status' => 'ok']);
        }

        DB::transaction(function () use ($generation, $eventType, $eventId, $payload, $eventData) {
            if (WebhookEvent::where('event_id', $eventId)->exists()) {
                return;
            }

            WebhookEvent::create([
                'event_id' => $eventId,
                'generation_id' => $generation->id,
                'event_type' => $eventType,
                'payload' => $payload,
                'processed_at' => now(),
            ]);

            $statusMap = [
                'generation.processing' => 'processing',
                'generation.succeeded' => 'succeeded',
                'generation.failed' => 'failed',
            ];

            $newStatus = $statusMap[$eventType] ?? null;
            if ($newStatus) {
                $this->stateMachine->transition($generation, $newStatus, $eventData);
            }
        });

        if ($eventType === 'generation.succeeded') {
            $generation->refresh();
            if ($generation->status === 'succeeded') {
                DownloadImageJob::dispatch($generation);
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
