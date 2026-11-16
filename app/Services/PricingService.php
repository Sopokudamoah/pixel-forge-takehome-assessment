<?php

namespace App\Services;

use App\ValueObjects\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class PricingService
{
    public const API_BASE = 'https://pixelforge.promptedit.dev';
    public const CACHE_TTL = 300;

    public function getPricingData(): array
    {
        return Cache::remember('pixelforge_pricing', self::CACHE_TTL, function () {
            $response = Http::get(self::API_BASE . '/v1/pricing');
            return $response->json();
        });
    }

    public function getRates(): array
    {
        $data = $this->getPricingData();
        return $data['rates'] ?? [];
    }

    public function getOutputTokens(string $quality, string $size): int
    {
        $data = $this->getPricingData();
        $tokenSpecs = $data['token_specs']['output'] ?? [];

        foreach ($tokenSpecs as $spec) {
            if (($spec['quality'] ?? '') === $quality && ($spec['size'] ?? '') === $size) {
                return (int) ($spec['tokens'] ?? 0);
            }
        }

        return 0;
    }

    public function getReasoningRange(string $quality): array
    {
        $data = $this->getPricingData();
        $tokenSpecs = $data['token_specs']['reasoning'] ?? [];

        foreach ($tokenSpecs as $spec) {
            if (($spec['quality'] ?? '') === $quality) {
                return [
                    'min' => (int) ($spec['min'] ?? 0),
                    'max' => (int) ($spec['max'] ?? 0),
                ];
            }
        }

        return ['min' => 0, 'max' => 0];
    }

    public function estimateCostRange(
        string $prompt,
        string $size,
        string $quality,
        int $referenceImageCount = 0
    ): array {
        $rates = $this->getRates();
        $outputTokens = $this->getOutputTokens($quality, $size);
        $reasoningRange = $this->getReasoningRange($quality);

        $textTokens = (int) ceil(mb_strlen($prompt) / 4);
        $imageTokens = $referenceImageCount * 765;

        $minCost = Money::zero();
        if (isset($rates['input_text'])) {
            $minCost = $minCost->add(Money::fromTokensAndRate($textTokens, $rates['input_text']));
        }
        if (isset($rates['input_image']) && $imageTokens > 0) {
            $minCost = $minCost->add(Money::fromTokensAndRate($imageTokens, $rates['input_image']));
        }
        if (isset($rates['output'])) {
            $minCost = $minCost->add(Money::fromTokensAndRate($outputTokens, $rates['output']));
        }
        if (isset($rates['reasoning'])) {
            $minCost = $minCost->add(Money::fromTokensAndRate($reasoningRange['min'], $rates['reasoning']));
        }

        $maxCost = Money::zero();
        if (isset($rates['input_text'])) {
            $maxCost = $maxCost->add(Money::fromTokensAndRate($textTokens, $rates['input_text']));
        }
        if (isset($rates['input_image']) && $imageTokens > 0) {
            $maxCost = $maxCost->add(Money::fromTokensAndRate($imageTokens, $rates['input_image']));
        }
        if (isset($rates['output'])) {
            $maxCost = $maxCost->add(Money::fromTokensAndRate($outputTokens, $rates['output']));
        }
        if (isset($rates['reasoning'])) {
            $maxCost = $maxCost->add(Money::fromTokensAndRate($reasoningRange['max'], $rates['reasoning']));
        }

        $headlineNano = intdiv($minCost->nanoDollars + $maxCost->nanoDollars, 2);
        $headline = new Money($headlineNano);

        return [
            'min' => $minCost,
            'max' => $maxCost,
            'headline' => $headline,
            'text_tokens' => $textTokens,
            'image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_min' => $reasoningRange['min'],
            'reasoning_max' => $reasoningRange['max'],
        ];
    }

    public function calculateActualCost(array $usage, array $rates): Money
    {
        $cost = Money::zero();

        foreach (['input_text', 'input_image', 'output', 'reasoning'] as $key) {
            $tokens = $usage[$key] ?? 0;
            if ($tokens > 0 && isset($rates[$key])) {
                $cost = $cost->add(Money::fromTokensAndRate((int) $tokens, $rates[$key]));
            }
        }

        return $cost;
    }

    public function getRatesSnapshot(): array
    {
        return $this->getRates();
    }
}
