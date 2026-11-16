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

        if (isset($data['tokens']['output'][$quality][$size])) {
            return (int) $data['tokens']['output'][$quality][$size];
        }

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

        if (isset($data['tokens']['reasoning'][$quality])) {
            $spec = $data['tokens']['reasoning'][$quality];
            return [
                'min' => (int) ($spec['min'] ?? 0),
                'max' => (int) ($spec['max'] ?? 0),
            ];
        }

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

        $textRate = $rates['input_text_tokens'] ?? $rates['input_text'] ?? '5.00';
        $imageRate = $rates['input_image_tokens'] ?? $rates['input_image'] ?? '10.00';
        $outputRate = $rates['output_tokens'] ?? $rates['output'] ?? '40.00';
        $reasoningRate = $rates['reasoning_tokens'] ?? $rates['reasoning'] ?? '12.50';

        $minCost = Money::zero();
        $minCost = $minCost->add(Money::fromTokensAndRate($textTokens, $textRate));
        if ($imageTokens > 0) {
            $minCost = $minCost->add(Money::fromTokensAndRate($imageTokens, $imageRate));
        }
        $minCost = $minCost->add(Money::fromTokensAndRate($outputTokens, $outputRate));
        $minCost = $minCost->add(Money::fromTokensAndRate($reasoningRange['min'], $reasoningRate));

        $maxCost = Money::zero();
        $maxCost = $maxCost->add(Money::fromTokensAndRate($textTokens, $textRate));
        if ($imageTokens > 0) {
            $maxCost = $maxCost->add(Money::fromTokensAndRate($imageTokens, $imageRate));
        }
        $maxCost = $maxCost->add(Money::fromTokensAndRate($outputTokens, $outputRate));
        $maxCost = $maxCost->add(Money::fromTokensAndRate($reasoningRange['max'], $reasoningRate));

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

        $tokenKeys = [
            'input_text' => ['input_text_tokens', 'input_text'],
            'input_image' => ['input_image_tokens', 'input_image'],
            'output' => ['output_tokens', 'output'],
            'reasoning' => ['reasoning_tokens', 'reasoning'],
        ];

        foreach ($tokenKeys as $type => $aliases) {
            $tokens = 0;
            foreach ($aliases as $k) {
                if (isset($usage[$k])) {
                    $tokens = (int) $usage[$k];
                    break;
                }
            }

            $rate = null;
            foreach ($aliases as $k) {
                if (isset($rates[$k])) {
                    $rate = $rates[$k];
                    break;
                }
            }

            if ($tokens > 0 && $rate !== null) {
                $cost = $cost->add(Money::fromTokensAndRate($tokens, $rate));
            }
        }

        return $cost;
    }

    public function getRatesSnapshot(): array
    {
        return $this->getRates();
    }
}
