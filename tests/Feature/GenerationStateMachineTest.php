<?php

use App\Actions\GenerationStateMachine;
use App\Models\Generation;
use App\Models\User;
use App\Models\UserBalance;
use App\Models\UserLedger;
use App\Services\BalanceService;
use App\Services\PricingService;
use Illuminate\Support\Str;

test('it enforces forward-only state transitions', function () {
    $pricing = app(PricingService::class);
    $balance = app(BalanceService::class);
    $machine = new GenerationStateMachine($pricing, $balance);

    $user = User::factory()->create();
    $genId = (string) Str::uuid();

    $gen = Generation::create([
        'id' => $genId,
        'user_id' => $user->id,
        'prompt' => 'Test transitions',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => 'queued',
        'rates' => ['input_text' => '5.00', 'output' => '40.00'],
        'idempotency_key' => $genId,
    ]);

    // queued -> processing is allowed
    expect($machine->transition($gen, 'processing'))->toBeTrue();
    $gen->refresh();
    expect($gen->status)->toBe('processing');

    // processing -> queued is NOT allowed
    expect($machine->transition($gen, 'queued'))->toBeFalse();
    $gen->refresh();
    expect($gen->status)->toBe('processing');
});

test('terminal states succeeded and failed are final and cannot transition', function () {
    $pricing = app(PricingService::class);
    $balance = app(BalanceService::class);
    $machine = new GenerationStateMachine($pricing, $balance);

    $user = User::factory()->create();
    $genId = (string) Str::uuid();

    $gen = Generation::create([
        'id' => $genId,
        'user_id' => $user->id,
        'prompt' => 'Test terminal',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => 'succeeded',
        'provider_cost_nano' => 12_642_500,
        'user_charge_nano' => 15_803_125,
        'rates' => ['input_text' => '5.00'],
        'idempotency_key' => $genId,
    ]);

    expect($gen->isTerminal())->toBeTrue();
    expect($machine->transition($gen, 'failed'))->toBeFalse();
    expect($machine->transition($gen, 'processing'))->toBeFalse();
    expect($machine->transition($gen, 'queued'))->toBeFalse();
});

test('it calculates cost on succeeded using snapshot rates from the generation row', function () {
    $pricing = app(PricingService::class);
    $balance = app(BalanceService::class);
    $machine = new GenerationStateMachine($pricing, $balance);

    $user = User::factory()->create();
    UserBalance::create([
        'user_id' => $user->id,
        'balance_nano' => 10_000_000_000,
    ]);

    $genId = (string) Str::uuid();

    // Use custom historical snapshot rates (e.g. higher output rate of 50.00)
    $gen = Generation::create([
        'id' => $genId,
        'user_id' => $user->id,
        'prompt' => 'Snapshot rates test',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => 'queued',
        'rates' => [
            'input_text' => '6.00',  // 6000 nano per token
            'output' => '50.00',     // 50000 nano per token
            'reasoning' => '15.00',  // 15000 nano per token
        ],
        'idempotency_key' => $genId,
    ]);

    $payload = [
        'usage' => [
            'input_text' => 10,  // 10 * 6000 = 60000
            'output' => 200,     // 200 * 50000 = 10000000
            'reasoning' => 100,  // 100 * 15000 = 1500000
        ],                       // Total provider = 11560000
        'image_url' => 'https://example.com/img.png',
    ];

    $machine->transition($gen, 'succeeded', $payload);

    $gen->refresh();
    expect($gen->status)->toBe('succeeded')
        ->and($gen->provider_cost_nano)->toBe(11_560_000)
        // 11560000 * 1.25 = 14450000
        ->and($gen->user_charge_nano)->toBe(14_450_000);

    expect(UserLedger::where('generation_id', $genId)->first()->amount_nano)->toBe(14_450_000);
});

test('it calculates cost with real-world pixelforge token keys', function () {
    $pricing = app(PricingService::class);
    $balance = app(BalanceService::class);
    $machine = new GenerationStateMachine($pricing, $balance);

    $user = User::factory()->create();
    UserBalance::create([
        'user_id' => $user->id,
        'balance_nano' => 10_000_000_000,
    ]);

    $genId = (string) Str::uuid();

    $gen = Generation::create([
        'id' => $genId,
        'user_id' => $user->id,
        'prompt' => 'API keys test',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => 'queued',
        'rates' => [
            'input_text_tokens' => '5.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'idempotency_key' => $genId,
    ]);

    $payload = [
        'usage' => [
            'input_text_tokens' => 6,
            'input_image_tokens' => 0,
            'output_tokens' => 280,
            'reasoning_tokens' => 113,
        ],
        'image_url' => 'https://pixelforge.promptedit.dev/images/sample.png',
    ];

    $machine->transition($gen, 'succeeded', $payload);

    $gen->refresh();
    expect($gen->status)->toBe('succeeded')
        ->and($gen->provider_cost_nano)->toBe(12_642_500)
        ->and($gen->user_charge_nano)->toBe(15_803_125);
});
