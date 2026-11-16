<?php

namespace App\Http\Controllers;

use App\Jobs\CreateGenerationJob;
use App\Jobs\SendSlackNotificationJob;
use App\Http\Requests\GenerateRequest;
use App\Models\Generation;
use App\Services\BalanceService;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class GenerationController extends Controller
{
    public function __construct(
        protected PricingService $pricingService,
        protected BalanceService $balanceService
    ) {
    }

    public function index(Request $request, \App\Http\Client\PixelforgeClient $client, \App\Actions\GenerationStateMachine $stateMachine)
    {
        $user = $request->user();

        // Self-healing / immediate reconciliation for active generations
        $activeGenerations = Generation::where('user_id', $user->id)
            ->whereIn('status', ['queued', 'processing'])
            ->get();

        foreach ($activeGenerations as $generation) {
            // If queued and provider_id is missing, execute creation job to ensure it was submitted
            if (empty($generation->provider_id)) {
                try {
                    (new CreateGenerationJob($generation))->handle($client, $stateMachine);
                    $generation->refresh();
                } catch (\Throwable $e) {
                    // Handled within CreateGenerationJob
                }
            }

            // If provider_id exists and not terminal, poll Pixelforge for latest status
            if (!empty($generation->provider_id) && !$generation->isTerminal()) {
                try {
                    $data = $client->getGeneration($generation->provider_id);
                    $status = $data['status'] ?? null;
                    if ($status && in_array($status, ['processing', 'succeeded', 'failed']) && $status !== $generation->status) {
                        $stateMachine->transition($generation, $status, $data);
                    }
                } catch (\Throwable $e) {
                    // Continue to next generation
                }
            }
        }

        $generations = Generation::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return Inertia::render('History', [
            'generations' => $generations->map(fn($g) => [
                'id' => $g->id,
                'prompt' => $g->prompt,
                'size' => $g->size,
                'quality' => $g->quality,
                'status' => $g->status,
                'status_label' => $g->statusLabel(),
                'provider_cost_nano' => $g->provider_cost_nano,
                'user_charge_nano' => $g->user_charge_nano,
                'provider_cost_display' => $g->providerCost()->formatShort(),
                'user_charge_display' => $g->userCharge()->formatShort(),
                'image_url' => $g->image_url,
                'error_type' => $g->error_type,
                'error_message' => $g->error_message,
                'created_at' => $g->created_at->toISOString(),
            ]),
        ]);
    }

    public function store(GenerateRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        $estimate = $this->pricingService->estimateCostRange(
            $validated['prompt'],
            $validated['size'],
            $validated['quality'],
            count($validated['reference_images'] ?? [])
        );

        $maxEstimateWithMarkup = $estimate['max']->withMarkup();
        if (!$this->balanceService->hasSufficientBalance($user, $maxEstimateWithMarkup->nanoDollars)) {
            return back()->withErrors(['prompt' => 'Insufficient balance. Please buy more credits.']);
        }

        $generation = Generation::create([
            'user_id' => $user->id,
            'prompt' => $validated['prompt'],
            'size' => $validated['size'],
            'quality' => $validated['quality'],
            'reference_images' => $validated['reference_images'] ?? null,
            'status' => 'queued',
            'rates' => $this->pricingService->getRatesSnapshot(),
            'idempotency_key' => (string) Str::uuid(),
            'metadata' => ['generation_id' => null],
        ]);

        $generation->metadata = ['generation_id' => $generation->id];
        $generation->save();

        CreateGenerationJob::dispatch($generation);
        SendSlackNotificationJob::dispatch($generation, 'started');

        return redirect()->route('history')->with('success', 'Generation queued successfully.');
    }

    public function retry(Request $request, string $id)
    {
        $generation = Generation::where('id', $id)->where('user_id', $request->user()->id)->firstOrFail();

        if (!$generation->isTerminal() || $generation->status !== 'failed') {
            abort(400, 'Can only retry failed generations.');
        }

        $newGeneration = Generation::create([
            'user_id' => $request->user()->id,
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => $generation->reference_images,
            'status' => 'queued',
            'rates' => $this->pricingService->getRatesSnapshot(),
            'idempotency_key' => (string) Str::uuid(),
            'metadata' => ['generation_id' => null],
        ]);

        $newGeneration->metadata = ['generation_id' => $newGeneration->id];
        $newGeneration->save();

        CreateGenerationJob::dispatch($newGeneration);
        SendSlackNotificationJob::dispatch($newGeneration, 'started');

        return redirect()->route('history')->with('success', 'Generation retry queued.');
    }
}
