<?php

namespace App\Console\Commands;

use App\Actions\GenerationStateMachine;
use App\Http\Client\PixelforgeClient;
use App\Models\Generation;
use Illuminate\Console\Command;

class ReconcileGenerations extends Command
{
    protected $signature = 'pixelforge:reconcile-generations {--all : Reconcile all non-terminal generations regardless of updated_at}';
    protected $description = 'Poll Pixelforge API for stuck generations';

    public function __construct(
        protected PixelforgeClient $client,
        protected GenerationStateMachine $stateMachine
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $query = Generation::whereIn('status', ['queued', 'processing']);
        if (!$this->option('all')) {
            $query->where('updated_at', '<', now()->subSeconds(30));
        }

        $stuck = $query->get();

        $this->info("Found {$stuck->count()} in-flight generations to reconcile.");

        foreach ($stuck as $generation) {
            if (empty($generation->provider_id)) {
                try {
                    (new \App\Jobs\CreateGenerationJob($generation))->handle($this->client, $this->stateMachine);
                    $generation->refresh();
                } catch (\Throwable $e) {
                    $this->warn("Could not dispatch generation {$generation->id}: {$e->getMessage()}");
                }
            }

            if (!empty($generation->provider_id) && !$generation->isTerminal()) {
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
        }

        return Command::SUCCESS;
    }
}
