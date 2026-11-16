<?php

use App\Actions\GenerationStateMachine;
use App\Jobs\SendSlackNotificationJob;
use App\Models\Generation;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\PricingService;
use App\Services\SlackNotificationService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

test('it sends generation started notification to slack webhook when configured', function () {
    Config::set('services.slack.webhook_url', 'https://hooks.slack.com/services/TEST/WEBHOOK/123');

    Http::fake([
        'https://hooks.slack.com/*' => Http::response(['ok' => true], 200),
    ]);

    $user = User::factory()->create([
        'name' => 'Jane Developer',
        'email' => 'jane@example.com',
    ]);

    $generation = Generation::create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'prompt' => 'A cybernetic owl under neon rain',
        'size' => '1024x1024',
        'quality' => 'high',
        'status' => 'queued',
        'rates' => [
            'input_text_tokens' => '5.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $service = app(SlackNotificationService::class);
    $result = $service->sendGenerationStarted($generation);

    expect($result)->toBeTrue();

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        $data = $request->data();
        return str_contains($request->url(), 'hooks.slack.com')
            && str_contains($data['text'], 'Jane Developer')
            && str_contains($data['text'], 'cybernetic owl');
    });
});

test('it sends generation completed notification with financial details and image link', function () {
    Config::set('services.slack.webhook_url', 'https://hooks.slack.com/services/TEST/WEBHOOK/123');

    Http::fake([
        'https://hooks.slack.com/*' => Http::response(['ok' => true], 200),
    ]);

    $user = User::factory()->create([
        'name' => 'Alex Studio',
        'email' => 'alex@example.com',
    ]);

    $generation = Generation::create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'prompt' => 'A futuristic floating island',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => 'succeeded',
        'image_url' => 'https://pixelforge.promptedit.dev/images/sample.png',
        'provider_cost_nano' => 12_642_500,
        'user_charge_nano' => 15_803_125,
        'rates' => [
            'input_text_tokens' => '5.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $service = app(SlackNotificationService::class);
    $result = $service->sendGenerationCompleted($generation);

    expect($result)->toBeTrue();

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        $data = $request->data();
        return str_contains($request->url(), 'hooks.slack.com')
            && str_contains($data['text'], 'Alex Studio')
            && str_contains($data['text'], 'Charged');
    });
});

test('it sends generation failed notification without charges', function () {
    Config::set('services.slack.webhook_url', 'https://hooks.slack.com/services/TEST/WEBHOOK/123');

    Http::fake([
        'https://hooks.slack.com/*' => Http::response(['ok' => true], 200),
    ]);

    $user = User::factory()->create([
        'name' => 'Sam Policy',
        'email' => 'sam@example.com',
    ]);

    $generation = Generation::create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'prompt' => 'A prompt that violates terms',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => 'failed',
        'error_type' => 'content_policy_violation',
        'error_message' => 'The prompt violated content safety policies.',
        'provider_cost_nano' => 0,
        'user_charge_nano' => 0,
        'rates' => [
            'input_text_tokens' => '5.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $service = app(SlackNotificationService::class);
    $result = $service->sendGenerationFailed($generation);

    expect($result)->toBeTrue();

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        $data = $request->data();
        return str_contains($request->url(), 'hooks.slack.com')
            && str_contains($data['text'], 'failed')
            && str_contains($data['text'], 'content safety');
    });
});

test('it gracefully returns false when slack webhook url is not set', function () {
    Config::set('services.slack.webhook_url', null);

    Http::fake();

    $user = User::factory()->create();
    $generation = Generation::create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'prompt' => 'Testing no webhook',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => 'queued',
        'rates' => [
            'input_text_tokens' => '5.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $service = app(SlackNotificationService::class);
    $result = $service->sendGenerationStarted($generation);

    expect($result)->toBeFalse();
    Http::assertNothingSent();
});

test('store generation request dispatches slack notification job', function () {
    Queue::fake();

    $user = User::factory()->create();
    app(BalanceService::class)->getOrCreate($user);

    $response = $this->actingAs($user)->post('/generations', [
        'prompt' => 'An astronaut walking on Mars in 4k',
        'size' => '1024x1024',
        'quality' => 'low',
    ]);

    $response->assertRedirect(route('history'));

    Queue::assertPushed(SendSlackNotificationJob::class, function ($job) {
        return $job->event === 'started';
    });
});

test('state machine transition dispatches slack notification job', function () {
    Queue::fake();

    $pricing = app(PricingService::class);
    $balanceService = app(BalanceService::class);
    $machine = new GenerationStateMachine($pricing, $balanceService);

    $user = User::factory()->create();
    $balanceService->getOrCreate($user);

    $gen = Generation::create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'prompt' => 'A magical forest with glowing crystals',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => 'queued',
        'rates' => [
            'input_text_tokens' => '5.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $machine->transition($gen, 'succeeded', [
        'usage' => [
            'input_text_tokens' => 6,
            'output_tokens' => 280,
            'reasoning_tokens' => 100,
        ],
        'image_url' => 'https://pixelforge.promptedit.dev/images/forest.png',
    ]);

    Queue::assertPushed(SendSlackNotificationJob::class, function ($job) {
        return $job->event === 'completed';
    });
});
