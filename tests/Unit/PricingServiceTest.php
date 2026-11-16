<?php

use App\Services\PricingService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response([
            'rates' => [
                'input_text' => '5.00',
                'input_image' => '10.00',
                'output' => '40.00',
                'reasoning' => '12.50',
            ],
            'token_specs' => [
                'output' => [
                    ['quality' => 'low', 'size' => '1024x1024', 'tokens' => 280],
                    ['quality' => 'medium', 'size' => '1024x1024', 'tokens' => 1100],
                    ['quality' => 'high', 'size' => '1024x1024', 'tokens' => 4300],
                ],
                'reasoning' => [
                    ['quality' => 'low', 'min' => 0, 'max' => 500],
                    ['quality' => 'medium', 'min' => 200, 'max' => 2000],
                    ['quality' => 'high', 'min' => 1000, 'max' => 8000],
                ],
            ],
        ], 200),
    ]);
});

test('it estimates cost range for prompt, quality and size', function () {
    $pricingService = new PricingService();

    // 10 characters = ceil(10/4) = 3 tokens
    $estimate = $pricingService->estimateCostRange(
        prompt: '1234567890',
        size: '1024x1024',
        quality: 'low',
        referenceImageCount: 0
    );

    expect($estimate['text_tokens'])->toBe(3)
        ->and($estimate['image_tokens'])->toBe(0)
        ->and($estimate['output_tokens'])->toBe(280)
        ->and($estimate['reasoning_min'])->toBe(0)
        ->and($estimate['reasoning_max'])->toBe(500);

    // Min cost = 3 * 5000 + 280 * 40000 + 0 * 12500 = 15000 + 11200000 = 11215000
    expect($estimate['min']->nanoDollars)->toBe(11_215_000);

    // Max cost = 3 * 5000 + 280 * 40000 + 500 * 12500 = 15000 + 11200000 + 6250000 = 17465000
    expect($estimate['max']->nanoDollars)->toBe(17_465_000);
});

test('it calculates reference image tokens correctly', function () {
    $pricingService = new PricingService();

    // 2 reference images = 2 * 765 = 1530 image tokens
    $estimate = $pricingService->estimateCostRange(
        prompt: 'test',
        size: '1024x1024',
        quality: 'low',
        referenceImageCount: 2
    );

    expect($estimate['image_tokens'])->toBe(1530);
});

test('it calculates actual provider cost and user markup from usage', function () {
    $pricingService = new PricingService();

    $usage = [
        'input_text' => 6,
        'input_image' => 0,
        'output' => 280,
        'reasoning' => 113,
    ];

    $rates = [
        'input_text' => '5.00',
        'input_image' => '10.00',
        'output' => '40.00',
        'reasoning' => '12.50',
    ];

    $providerCost = $pricingService->calculateActualCost($usage, $rates);

    // 6 * 5000 + 280 * 40000 + 113 * 12500 = 30000 + 11200000 + 1412500 = 12642500
    expect($providerCost->nanoDollars)->toBe(12_642_500)
        ->and($providerCost->formatForDisplay())->toBe('$0.012642500');

    // Markup 25% = 15803125 nano
    $userCharge = $providerCost->withMarkup();
    expect($userCharge->nanoDollars)->toBe(15_803_125)
        ->and($userCharge->formatForDisplay())->toBe('$0.015803125');
});
