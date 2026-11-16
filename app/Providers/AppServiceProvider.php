<?php

namespace App\Providers;

use App\Http\Client\PixelforgeClient;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, function ($app) {
            return new PixelforgeClient(config('services.pixelforge.api_key'));
        });
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
