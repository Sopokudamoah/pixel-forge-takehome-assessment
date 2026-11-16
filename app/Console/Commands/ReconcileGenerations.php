<?php

namespace App\Console\Commands;

use App\Actions\GenerationStateMachine;
use App\Http\Client\PixelforgeClient;
use App\Models\Generation;
use Illuminate\Console\Command;

class ReconcileGenerations extends Command
{
    protected $signature = 'pixelforge:reconcile-generations';
    protected $description = 'Poll Pixelforge API for stuck generations';

    public function __construct(
        protected PixelforgeClient $client,
        protected GenerationStateMachine $stateMachine
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $stuck = Generation::whereIn('status', ['queued', 'processing'])
            ->where('updated_at', '<', now()->subMinutes(2))
            ->get();

        $this->info("Found {$stuck->count()} stuck generations.");

        foreach ($stuck as $generation) {
            if (empty($generation->provider_id)) {
                continue;
            }

            try {
                $data = $this->client->getGeneration($generation->provider_id);
                $statusMap = ['queued' => 'queued', 'processing' => 'processing', 'succeeded' => 'succeeded', 'failed' => 'failed'];
                $newStatus = $statusMap[$data['status'] ?? ''] ?? null;

                if ($newStatus && $newStatus !== $generation->status) {
                    $this->stateMachine->transition($generation, $newStatus, $data);
                    $this->info("Updated {$generation->id} to {$newStatus}.");
                }
            } catch (\Exception $e) {
                $this->error("Failed {$generation->id}: {$e->getMessage()}");
            }
        }

        return Command::SUCCESS;
    }
}
