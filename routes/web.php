<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', [App\Http\Controllers\DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/generate', function () {
        return Inertia::render('Generate');
    })->name('generate');

    Route::get('/history', [App\Http\Controllers\GenerationController::class, 'index'])->name('history');
    Route::post('/generations', [App\Http\Controllers\GenerationController::class, 'store'])->name('generations.store');
    Route::post('/generations/{id}/retry', [App\Http\Controllers\GenerationController::class, 'retry'])->name('generations.retry');
    Route::post('/balance/buy', [App\Http\Controllers\BalanceController::class, 'buyCredits'])->name('balance.buy');
    Route::get('/admin/report', [App\Http\Controllers\AdminReportController::class, 'index'])->name('admin.report');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Webhook route - no auth/CSRF
Route::post('/webhooks/pixelforge', [App\Http\Controllers\WebhookController::class, 'handle'])
    ->name('webhooks.pixelforge');

require __DIR__.'/auth.php';
