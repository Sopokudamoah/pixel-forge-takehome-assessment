<?php

namespace App\Actions;

use App\Models\Generation;
use App\Services\BalanceService;
use App\Services\PricingService;
use Illuminate\Support\Facades\DB;

class GenerationStateMachine
{
    public const TRANSITIONS = [
        'queued' => ['processing', 'succeeded', 'failed'],
        'processing' => ['succeeded', 'failed'],
    ];

    public function __construct(
        protected PricingService $pricingService,
        protected BalanceService $balanceService
    ) {
    }

    public function transition(Generation $generation, string $newStatus, ?array $payload = null): bool
    {
        if ($generation->isTerminal()) {
            return false;
        }

        $allowed = self::TRANSITIONS[$generation->status] ?? [];
        if (!in_array($newStatus, $allowed)) {
            return false;
        }

        return DB::transaction(function () use ($generation, $newStatus, $payload) {
            $lockedGeneration = Generation::where('id', $generation->id)->lockForUpdate()->first();
            if (!$lockedGeneration || $lockedGeneration->isTerminal()) {
                return false;
            }

            $allowed = self::TRANSITIONS[$lockedGeneration->status] ?? [];
            if (!in_array($newStatus, $allowed)) {
                return false;
            }

            $lockedGeneration->status = $newStatus;

            if ($newStatus === 'succeeded' && $payload) {
                $this->handleSuccess($lockedGeneration, $payload);
            } elseif ($newStatus === 'failed' && $payload) {
                $this->handleFailure($lockedGeneration, $payload);
            } elseif ($newStatus === 'processing' && $payload) {
                if (isset($payload['provider_id'])) {
                    $lockedGeneration->provider_id = $payload['provider_id'];
                } elseif (isset($payload['id'])) {
                    $lockedGeneration->provider_id = $payload['id'];
                }
            }

            $lockedGeneration->save();
            return true;
        });
    }

    protected function handleSuccess(Generation $generation, array $payload): void
    {
        if (isset($payload['id'])) {
            $generation->provider_id = $payload['id'];
        }

        if (isset($payload['usage'])) {
            $generation->usage = $payload['usage'];
        }

        if (isset($payload['image']['url'])) {
            $generation->image_url = $payload['image']['url'];
        } elseif (isset($payload['image_url'])) {
            $generation->image_url = $payload['image_url'];
        }

        if (isset($payload['image']['expires_at'])) {
            $generation->image_url_expires_at = $payload['image']['expires_at'];
        } elseif (isset($payload['image_url_expires_at'])) {
            $generation->image_url_expires_at = $payload['image_url_expires_at'];
        }

        $usage = $payload['usage'] ?? [];
        $providerCost = $this->pricingService->calculateActualCost($usage, $generation->rates);
        $userCharge = $providerCost->withMarkup();

        $generation->provider_cost_nano = $providerCost->nanoDollars;
        $generation->user_charge_nano = $userCharge->nanoDollars;

        $this->balanceService->debit($generation->user, $userCharge->nanoDollars, $generation);
    }

    protected function handleFailure(Generation $generation, array $payload): void
    {
        $generation->provider_cost_nano = 0;
        $generation->user_charge_nano = 0;

        if (isset($payload['id'])) {
            $generation->provider_id = $payload['id'];
        }

        if (isset($payload['error']['type'])) {
            $generation->error_type = $payload['error']['type'];
        } elseif (isset($payload['error_type'])) {
            $generation->error_type = $payload['error_type'];
        }

        if (isset($payload['error']['message'])) {
            $generation->error_message = $payload['error']['message'];
        } elseif (isset($payload['error_message'])) {
            $generation->error_message = $payload['error_message'];
        }
    }
}
