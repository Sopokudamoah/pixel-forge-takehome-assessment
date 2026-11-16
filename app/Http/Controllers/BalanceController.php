<?php

namespace App\Http\Controllers;

use App\Services\BalanceService;
use Illuminate\Http\Request;

class BalanceController extends Controller
{
    public function buyCredits(Request $request, BalanceService $balanceService)
    {
        $user = $request->user();
        $amount = 5_000_000_000; // $5

        $balanceService->credit($user, $amount, 'purchase_' . now()->timestamp);

        return back()->with('success', 'Successfully added $5.00 to your balance.');
    }
}
