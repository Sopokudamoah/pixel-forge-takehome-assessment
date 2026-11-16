<?php

namespace App\Http\Controllers;

use App\Http\Client\PixelforgeClient;
use App\Models\Generation;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AdminReportController extends Controller
{
    public function index(Request $request, PixelforgeClient $client)
    {
        $dailyReport = Generation::whereIn('status', ['succeeded', 'failed'])
            ->whereNotNull('usage')
            ->selectRaw('DATE(updated_at) as day, SUM(user_charge_nano) as total_user_charge, SUM(provider_cost_nano) as total_provider_cost, COUNT(*) as generation_count')
            ->groupBy('day')
            ->orderByDesc('day')
            ->get();

        $userReport = Generation::whereIn('status', ['succeeded', 'failed'])
            ->whereNotNull('usage')
            ->join('users', 'generations.user_id', '=', 'users.id')
            ->selectRaw('users.id as user_id, users.name, users.email, SUM(generations.user_charge_nano) as total_user_charge, SUM(generations.provider_cost_nano) as total_provider_cost, COUNT(*) as generation_count')
            ->groupBy('user_id', 'name', 'email')
            ->get();

        $reconciliation = [];
        try {
            $usage = $client->getUsage();
            $reconciliation = $usage['data'] ?? [];
        } catch (\Exception $e) {
            // Ignore
        }

        return Inertia::render('Admin/Report', [
            'daily_report' => $dailyReport,
            'user_report' => $userReport,
            'reconciliation' => $reconciliation,
        ]);
    }
}
