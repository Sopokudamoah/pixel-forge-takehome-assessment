<?php

namespace App\Console\Commands;

use App\Http\Client\PixelforgeClient;
use App\Models\Generation;
use Illuminate\Console\Command;

class ReconcileUsage extends Command
{
    protected $signature = 'pixelforge:reconcile-usage';
    protected $description = 'Compare local provider costs against Pixelforge usage API';

    public function __construct(protected PixelforgeClient $client) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $usage = $this->client->getUsage();
        } catch (\Exception $e) {
            $this->error("Failed to fetch usage: {$e->getMessage()}");
            return Command::FAILURE;
        }

        $this->info("Usage reconciliation report:");
        foreach (($usage['data'] ?? []) as $dayData) {
            $date = $dayData['date'] ?? 'unknown';
            $apiCost = $dayData['cost_usd'] ?? '0';

            $localCost = Generation::whereDate('updated_at', $date)
                ->whereIn('status', ['succeeded', 'failed'])
                ->sum('provider_cost_nano');

            $localCostUsd = number_format($localCost / 1_000_000_000, 9);
            $diff = abs((float) $apiCost - (float) $localCostUsd);

            $this->info("{$date} | API: \${$apiCost} | Local: \${$localCostUsd} | Diff: \${number_format($diff, 9)}");
        }

        return Command::SUCCESS;
    }
}
