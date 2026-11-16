<?php

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Models\UserBalance;
use App\Services\BalanceService;
use App\Services\PricingService;
use Illuminate\Support\Facades\Http;

test('new user gets 10 USD default balance', function () {
    $user = User::factory()->create();
    $service = new BalanceService();

    $balance = $service->getOrCreate($user);
    expect($balance->balance_nano)->toBe(10_000_000_000);
});

test('buy credits endpoint adds 5 USD to user balance', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/balance/buy');
    $response->assertRedirect();

    $balance = UserBalance::where('user_id', $user->id)->first();
    expect($balance->balance_nano)->toBe(15_000_000_000);

    expect(CreditTransaction::where('user_id', $user->id)->where('type', 'purchase')->exists())->toBeTrue();
});

test('generation is blocked when user balance is insufficient', function () {
    $user = User::factory()->create();
    UserBalance::create([
        'user_id' => $user->id,
        'balance_nano' => 1_000, // almost $0 (1 micro-dollar)
    ]);

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response([
            'rates' => [
                'input_text' => '5.00',
                'output' => '40.00',
                'reasoning' => '12.50',
            ],
            'token_specs' => [
                'output' => [['quality' => 'low', 'size' => '1024x1024', 'tokens' => 280]],
                'reasoning' => [['quality' => 'low', 'min' => 0, 'max' => 500]],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($user)->post('/generations', [
        'prompt' => 'An expensive test image prompt',
        'size' => '1024x1024',
        'quality' => 'low',
    ]);

    $response->assertSessionHasErrors('prompt');
    expect(Generation::where('user_id', $user->id)->count())->toBe(0);
});

test('debit fails safely when balance is lower than amount', function () {
    $user = User::factory()->create();
    UserBalance::create([
        'user_id' => $user->id,
        'balance_nano' => 5_000_000_000, // $5
    ]);

    $service = new BalanceService();
    $fakeGen = (object) ['id' => 'dummy_id'];

    $result = $service->debit($user, 10_000_000_000, $fakeGen); // trying to debit $10
    expect($result)->toBeFalse();

    $balance = UserBalance::where('user_id', $user->id)->first();
    expect($balance->balance_nano)->toBe(5_000_000_000); // untouched
});

test('successful generation debits user balance correctly', function () {
    $pricing = app(PricingService::class);
    $balanceService = app(BalanceService::class);
    $machine = new \App\Actions\GenerationStateMachine($pricing, $balanceService);

    $user = User::factory()->create();
    $balanceService->getOrCreate($user);

    $genId = (string) \Illuminate\Support\Str::uuid();
    $gen = Generation::create([
        'id' => $genId,
        'user_id' => $user->id,
        'prompt' => 'A test prompt',
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
            'output_tokens' => 280,
            'reasoning_tokens' => 113,
        ],
        'image_url' => 'https://pixelforge.promptedit.dev/images/sample.png',
    ];

    $machine->transition($gen, 'succeeded', $payload);

    $balance = UserBalance::where('user_id', $user->id)->first();
    // Initial 10_000_000_000 - 15_803_125 = 9_984_196_875
    expect($balance->balance_nano)->toBe(9_984_196_875);

    // Duplicate transition should not double debit
    $machine->transition($gen, 'succeeded', $payload);
    expect(UserBalance::where('user_id', $user->id)->first()->balance_nano)->toBe(9_984_196_875);
});
