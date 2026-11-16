<?php

use App\ValueObjects\Money;

test('it creates zero money', function () {
    $money = Money::zero();
    expect($money->nanoDollars)->toBe(0)
        ->and($money->isZero())->toBeTrue();
});

test('it creates money from whole USD', function () {
    $money = Money::fromUsd(10);
    expect($money->nanoDollars)->toBe(10_000_000_000);
});

test('it parses rate strings correctly with bcmath', function () {
    // $5.00 per 1M tokens = 5000 nanodollars per token
    $rate5 = Money::fromRateString('5.00');
    expect($rate5->nanoDollars)->toBe(5000);

    // $10.00 per 1M tokens = 10000 nanodollars per token
    $rate10 = Money::fromRateString('10.00');
    expect($rate10->nanoDollars)->toBe(10000);

    // $40.00 per 1M tokens = 40000 nanodollars per token
    $rate40 = Money::fromRateString('40.00');
    expect($rate40->nanoDollars)->toBe(40000);

    // $12.50 per 1M tokens = 12500 nanodollars per token
    $rate1250 = Money::fromRateString('12.50');
    expect($rate1250->nanoDollars)->toBe(12500);
});

test('it calculates token costs accurately', function () {
    // 6 text tokens at $5.00/1M = 6 * 5000 = 30000 nano
    $text = Money::fromTokensAndRate(6, '5.00');
    expect($text->nanoDollars)->toBe(30000);

    // 280 output tokens at $40.00/1M = 280 * 40000 = 11200000 nano
    $output = Money::fromTokensAndRate(280, '40.00');
    expect($output->nanoDollars)->toBe(11_200_000);

    // 113 reasoning tokens at $12.50/1M = 113 * 12500 = 1412500 nano
    $reasoning = Money::fromTokensAndRate(113, '12.50');
    expect($reasoning->nanoDollars)->toBe(1_412_500);

    // Total provider cost: 30000 + 11200000 + 1412500 = 12642500 nano = $0.012642500
    $total = $text->add($output)->add($reasoning);
    expect($total->nanoDollars)->toBe(12_642_500)
        ->and($total->formatForDisplay())->toBe('$0.012642500');
});

test('it applies 25% markup with round half up to nearest nanodollar', function () {
    // 12642500 * 1.25 = 15803125 nano = $0.015803125
    $provider = new Money(12_642_500);
    $userCharge = $provider->withMarkup();

    expect($userCharge->nanoDollars)->toBe(15_803_125)
        ->and($userCharge->formatForDisplay())->toBe('$0.015803125');
});

test('it handles addition and subtraction', function () {
    $a = new Money(5_000_000_000);
    $b = new Money(2_000_000_000);

    expect($a->add($b)->nanoDollars)->toBe(7_000_000_000)
        ->and($a->subtract($b)->nanoDollars)->toBe(3_000_000_000);
});

test('it formats short display strings nicely', function () {
    expect((new Money(15_803_125))->formatShort())->toBe('$0.015803')
        ->and((new Money(0))->formatShort())->toBe('$0.00')
        ->and((new Money(10_000_000_000))->formatShort())->toBe('$10.00');
});
