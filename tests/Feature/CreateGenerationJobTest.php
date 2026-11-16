<?php

use App\Actions\GenerationStateMachine;
use App\Http\Client\PixelforgeApiException;
use App\Http\Client\PixelforgeClient;
use App\Jobs\CreateGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\PricingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

test('it sends correct request parameters and idempotency key', function () {
    $user = User::factory()->create();
    $genId = (string) Str::uuid();

    $generation = Generation::create([
        'id' => $genId,
        'user_id' => $user->id,
        'prompt' => 'A photo of a lush rainforest [succeed]',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => 'queued',
        'rates' => ['input_text' => '5.00'],
        'idempotency_key' => $genId,
        'metadata' => ['generation_id' => $genId],
    ]);

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations' => function ($request) use ($genId) {
            expect($request->header('Idempotency-Key')[0])->toBe($genId)
                ->and($request['prompt'])->toBe('A photo of a lush rainforest [succeed]')
                ->and($request['size'])->toBe('1024x1024')
                ->and($request['quality'])->toBe('low')
                ->and($request['metadata']['generation_id'])->toBe($genId);

            return Http::response([
                'id' => 'gen_prov_12345',
                'status' => 'queued',
            ], 200);
        },
    ]);

    $client = new PixelforgeClient('test_api_key');
    $stateMachine = new GenerationStateMachine(app(PricingService::class), app(BalanceService::class));

    $job = new CreateGenerationJob($generation);
    $job->handle($client, $stateMachine);

    $generation->refresh();
    expect($generation->provider_id)->toBe('gen_prov_12345');
});

test('it handles terminal content policy violation without throwing exception', function () {
    $user = User::factory()->create();
    $genId = (string) Str::uuid();

    $generation = Generation::create([
        'id' => $genId,
        'user_id' => $user->id,
        'prompt' => 'Violating prompt [blocked]',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => 'queued',
        'rates' => ['input_text' => '5.00'],
        'idempotency_key' => $genId,
        'metadata' => ['generation_id' => $genId],
    ]);

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'error' => [
                'type' => 'content_policy_violation',
                'message' => 'Prompt violates safety guidelines',
            ],
        ], 400),
    ]);

    $client = new PixelforgeClient('test_api_key');
    $stateMachine = new GenerationStateMachine(app(PricingService::class), app(BalanceService::class));

    $job = new CreateGenerationJob($generation);
    $job->handle($client, $stateMachine);

    $generation->refresh();
    expect($generation->status)->toBe('failed')
        ->and($generation->error_type)->toBe('content_policy_violation')
        ->and($generation->error_message)->toContain('content policy');
});

test('it rethrows retryable 429 error for queue backoff', function () {
    $user = User::factory()->create();
    $genId = (string) Str::uuid();

    $generation = Generation::create([
        'id' => $genId,
        'user_id' => $user->id,
        'prompt' => 'Prompt [overloaded]',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => 'queued',
        'rates' => ['input_text' => '5.00'],
        'idempotency_key' => $genId,
    ]);

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'error' => [
                'type' => 'rate_limit_exceeded',
                'message' => 'Too many requests',
            ],
        ], 429, ['Retry-After' => '5']),
    ]);

    $client = new PixelforgeClient('test_api_key');
    $stateMachine = new GenerationStateMachine(app(PricingService::class), app(BalanceService::class));

    $job = new CreateGenerationJob($generation);

    expect(fn () => $job->handle($client, $stateMachine))
        ->toThrow(PixelforgeApiException::class);
});

test('it tolerates generation already completed by fast webhook', function () {
    $user = User::factory()->create();
    $genId = (string) Str::uuid();

    $generation = Generation::create([
        'id' => $genId,
        'user_id' => $user->id,
        'prompt' => 'Fast webhook prompt',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => 'succeeded',
        'provider_cost_nano' => 12_642_500,
        'user_charge_nano' => 15_803_125,
        'rates' => ['input_text' => '5.00'],
        'idempotency_key' => $genId,
    ]);

    // Client should not even be called
    Http::fake();

    $client = new PixelforgeClient('test_api_key');
    $stateMachine = new GenerationStateMachine(app(PricingService::class), app(BalanceService::class));

    $job = new CreateGenerationJob($generation);
    $job->handle($client, $stateMachine);

    $generation->refresh();
    expect($generation->status)->toBe('succeeded');
    Http::assertNothingSent();
});
