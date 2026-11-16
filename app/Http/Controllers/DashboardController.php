<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\BalanceService;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request, BalanceService $balanceService)
    {
        $user = $request->user();
        $balance = $balanceService->getOrCreate($user);
        $recentGenerations = Generation::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return Inertia::render('Dashboard', [
            'balance_nano' => $balance->balance_nano,
            'balance_display' => (new \App\ValueObjects\Money($balance->balance_nano))->formatShort(),
            'recent_generations' => $recentGenerations->map(fn($g) => [
                'id' => $g->id,
                'prompt' => $g->prompt,
                'status' => $g->status,
                'status_label' => $g->statusLabel(),
                'created_at' => $g->created_at->toISOString(),
            ]),
        ]);
    }

    public function pricing(PricingService $pricingService)
    {
        return response()->json(['rates' => $pricingService->getRates()]);
    }
}
