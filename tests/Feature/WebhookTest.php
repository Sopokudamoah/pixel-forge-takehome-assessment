<?php

use App\Models\Generation;
use App\Models\User;
use App\Models\UserBalance;
use App\Models\UserLedger;
use App\Models\WebhookEvent;
use Illuminate\Support\Str;

function makeWebhookSignature(string $rawBody, int $timestamp, string $secret): string
{
    $payload = "{$timestamp}.{$rawBody}";
    $signature = hash_hmac('sha256', $payload, $secret);
    return "t={$timestamp},v1={$signature}";
}

beforeEach(function () {
    config(['services.pixelforge.webhook_secret' => 'whsec_test_secret_12345']);
});

test('it rejects webhook requests with missing signature', function () {
    $response = $this->postJson('/webhooks/pixelforge', ['event_id' => 'evt_123']);
    $response->assertStatus(400);
});

test('it rejects webhook requests with invalid signature', function () {
    $body = json_encode(['event_id' => 'evt_123', 'event_type' => 'ping']);
    $timestamp = time();

    $response = $this->call(
        'POST',
        '/webhooks/pixelforge',
        [],
        [],
        [],
        [
            'HTTP_Pixelforge-Signature' => "t={$timestamp},v1=invalid_hex_signature",
            'CONTENT_TYPE' => 'application/json',
        ],
        $body
    );

    $response->assertStatus(400);
});

test('it rejects webhook requests with stale timestamp (> 300s)', function () {
    $body = json_encode(['event_id' => 'evt_123', 'event_type' => 'ping']);
    $staleTimestamp = time() - 350; // older than 300s
    $sig = makeWebhookSignature($body, $staleTimestamp, 'whsec_test_secret_12345');

    $response = $this->call(
        'POST',
        '/webhooks/pixelforge',
        [],
        [],
        [],
        [
            'HTTP_Pixelforge-Signature' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ],
        $body
    );

    $response->assertStatus(400);
});

test('it accepts ping webhook event with valid signature', function () {
    $body = json_encode(['event_id' => 'evt_ping_1', 'event_type' => 'ping']);
    $timestamp = time();
    $sig = makeWebhookSignature($body, $timestamp, 'whsec_test_secret_12345');

    $response = $this->call(
        'POST',
        '/webhooks/pixelforge',
        [],
        [],
        [],
        [
            'HTTP_Pixelforge-Signature' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ],
        $body
    );

    $response->assertStatus(200);
});

test('it handles generation.succeeded webhook and calculates charges atomically', function () {
    $user = User::factory()->create();
    UserBalance::create([
        'user_id' => $user->id,
        'balance_nano' => 10_000_000_000, // $10.00
    ]);

    $generationId = (string) Str::uuid();
    $generation = Generation::create([
        'id' => $generationId,
        'user_id' => $user->id,
        'prompt' => 'A photo of a cyberpunk city',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => 'queued',
        'rates' => [
            'input_text' => '5.00',
            'input_image' => '10.00',
            'output' => '40.00',
            'reasoning' => '12.50',
        ],
        'idempotency_key' => $generationId,
        'metadata' => ['generation_id' => $generationId],
    ]);

    $webhookPayload = [
        'event_id' => 'evt_succ_1',
        'event_type' => 'generation.succeeded',
        'data' => [
            'id' => 'gen_provider_999',
            'metadata' => ['generation_id' => $generationId],
            'usage' => [
                'input_text' => 6,
                'input_image' => 0,
                'output' => 280,
                'reasoning' => 113,
            ],
            'image' => [
                'url' => 'https://pixelforge.promptedit.dev/images/sample.png',
                'expires_at' => now()->addDay()->toISOString(),
            ],
        ],
    ];

    $body = json_encode($webhookPayload);
    $timestamp = time();
    $sig = makeWebhookSignature($body, $timestamp, 'whsec_test_secret_12345');

    $response = $this->call(
        'POST',
        '/webhooks/pixelforge',
        [],
        [],
        [],
        [
            'HTTP_Pixelforge-Signature' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ],
        $body
    );

    $response->assertStatus(200);

    $generation->refresh();
    expect($generation->status)->toBe('succeeded')
        ->and($generation->provider_cost_nano)->toBe(12_642_500) // $0.012642500
        ->and($generation->user_charge_nano)->toBe(15_803_125)   // $0.015803125
        ->and($generation->image_url)->toBe('https://pixelforge.promptedit.dev/images/sample.png');

    // Webhook event recorded
    expect(WebhookEvent::where('event_id', 'evt_succ_1')->exists())->toBeTrue();

    // User ledger debited exactly once
    expect(UserLedger::where('generation_id', $generationId)->count())->toBe(1);

    // User balance debited
    $userBalance = UserBalance::where('user_id', $user->id)->first();
    expect($userBalance->balance_nano)->toBe(10_000_000_000 - 15_803_125);
});

test('it ensures idempotency when duplicate webhook event is delivered', function () {
    $user = User::factory()->create();
    UserBalance::create([
        'user_id' => $user->id,
        'balance_nano' => 10_000_000_000,
    ]);

    $generationId = (string) Str::uuid();
    $generation = Generation::create([
        'id' => $generationId,
        'user_id' => $user->id,
        'prompt' => 'Duplicate test prompt',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => 'queued',
        'rates' => [
            'input_text' => '5.00',
            'output' => '40.00',
            'reasoning' => '12.50',
        ],
        'idempotency_key' => $generationId,
        'metadata' => ['generation_id' => $generationId],
    ]);

    $webhookPayload = [
        'event_id' => 'evt_dup_100',
        'event_type' => 'generation.succeeded',
        'data' => [
            'id' => 'gen_provider_100',
            'metadata' => ['generation_id' => $generationId],
            'usage' => [
                'input_text' => 6,
                'output' => 280,
                'reasoning' => 113,
            ],
            'image' => [
                'url' => 'https://pixelforge.promptedit.dev/images/sample.png',
            ],
        ],
    ];

    $body = json_encode($webhookPayload);
    $timestamp = time();
    $sig = makeWebhookSignature($body, $timestamp, 'whsec_test_secret_12345');

    // First delivery
    $r1 = $this->call('POST', '/webhooks/pixelforge', [], [], [], [
        'HTTP_Pixelforge-Signature' => $sig,
        'CONTENT_TYPE' => 'application/json',
    ], $body);
    $r1->assertStatus(200);

    // Second delivery (duplicate)
    $r2 = $this->call('POST', '/webhooks/pixelforge', [], [], [], [
        'HTTP_Pixelforge-Signature' => $sig,
        'CONTENT_TYPE' => 'application/json',
    ], $body);
    $r2->assertStatus(200);

    // Ledger has exactly 1 entry
    expect(UserLedger::where('generation_id', $generationId)->count())->toBe(1);

    // Balance debited only once
    $userBalance = UserBalance::where('user_id', $user->id)->first();
    expect($userBalance->balance_nano)->toBe(10_000_000_000 - 15_803_125);
});

test('it does not charge on generation.failed webhook', function () {
    $user = User::factory()->create();
    UserBalance::create([
        'user_id' => $user->id,
        'balance_nano' => 10_000_000_000,
    ]);

    $generationId = (string) Str::uuid();
    $generation = Generation::create([
        'id' => $generationId,
        'user_id' => $user->id,
        'prompt' => 'Failing prompt [fail]',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => 'queued',
        'rates' => ['input_text' => '5.00'],
        'idempotency_key' => $generationId,
        'metadata' => ['generation_id' => $generationId],
    ]);

    $webhookPayload = [
        'event_id' => 'evt_fail_1',
        'event_type' => 'generation.failed',
        'data' => [
            'id' => 'gen_provider_fail',
            'metadata' => ['generation_id' => $generationId],
            'error' => [
                'type' => 'content_policy_violation',
                'message' => 'Your prompt triggered content safety filters.',
            ],
        ],
    ];

    $body = json_encode($webhookPayload);
    $timestamp = time();
    $sig = makeWebhookSignature($body, $timestamp, 'whsec_test_secret_12345');

    $response = $this->call('POST', '/webhooks/pixelforge', [], [], [], [
        'HTTP_Pixelforge-Signature' => $sig,
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    $response->assertStatus(200);

    $generation->refresh();
    expect($generation->status)->toBe('failed')
        ->and($generation->provider_cost_nano)->toBe(0)
        ->and($generation->user_charge_nano)->toBe(0)
        ->and($generation->error_type)->toBe('content_policy_violation');

    // No ledger charge
    expect(UserLedger::where('generation_id', $generationId)->count())->toBe(0);

    // Balance untouched
    $userBalance = UserBalance::where('user_id', $user->id)->first();
    expect($userBalance->balance_nano)->toBe(10_000_000_000);
});

test('it does not regress state if processing webhook arrives after succeeded', function () {
    $user = User::factory()->create();
    $generationId = (string) Str::uuid();
    $generation = Generation::create([
        'id' => $generationId,
        'user_id' => $user->id,
        'prompt' => 'Out of order test',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => 'succeeded',
        'provider_cost_nano' => 12_642_500,
        'user_charge_nano' => 15_803_125,
        'rates' => ['input_text' => '5.00'],
        'idempotency_key' => $generationId,
        'metadata' => ['generation_id' => $generationId],
    ]);

    $webhookPayload = [
        'event_id' => 'evt_proc_late',
        'event_type' => 'generation.processing',
        'data' => [
            'id' => 'gen_late',
            'metadata' => ['generation_id' => $generationId],
        ],
    ];

    $body = json_encode($webhookPayload);
    $timestamp = time();
    $sig = makeWebhookSignature($body, $timestamp, 'whsec_test_secret_12345');

    $response = $this->call('POST', '/webhooks/pixelforge', [], [], [], [
        'HTTP_Pixelforge-Signature' => $sig,
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    $response->assertStatus(200);

    $generation->refresh();
    // Status stays succeeded!
    expect($generation->status)->toBe('succeeded');
});
