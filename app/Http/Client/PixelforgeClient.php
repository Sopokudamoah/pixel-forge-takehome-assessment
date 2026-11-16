<?php

namespace App\Http\Client;

use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    public const BASE_URL = 'https://pixelforge.promptedit.dev';

    public function __construct(protected string $apiKey) {
    }

    protected function baseRequest()
    {
        return Http::baseUrl(self::BASE_URL)
            ->timeout(30)
            ->acceptJson()
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ]);
    }

    public function createGeneration(array $params, string $idempotencyKey): array
    {
        $response = $this->baseRequest()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/v1/generations', $params);

        if ($response->successful()) {
            return $response->json();
        }

        throw new PixelforgeApiException(
            $response->status(),
            $response->json('error.type', 'unknown'),
            $response->json('error.message', $response->json('error', 'Unknown error')),
            $response->header('Retry-After')
        );
    }

    public function getGeneration(string $providerId): array
    {
        $response = $this->baseRequest()->get("/v1/generations/{$providerId}");

        if ($response->successful()) {
            return $response->json();
        }

        throw new PixelforgeApiException(
            $response->status(),
            $response->json('error.type', 'unknown'),
            $response->json('error.message', 'Unknown error'),
            $response->header('Retry-After')
        );
    }

    public function getUsage(): array
    {
        $response = $this->baseRequest()->get('/v1/usage');

        if ($response->successful()) {
            return $response->json();
        }

        throw new PixelforgeApiException(
            $response->status(),
            $response->json('error.type', 'unknown'),
            $response->json('error.message', 'Unknown error')
        );
    }
}
