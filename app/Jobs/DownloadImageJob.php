<?php

namespace App\Jobs;

use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class DownloadImageJob implements ShouldQueue
{
    use Queueable;
    public $tries = 3;

    public function __construct(public Generation $generation) {
    }

    public function handle(): void
    {
        if (empty($this->generation->image_url)) {
            return;
        }

        try {
            $response = Http::get($this->generation->image_url);
            if ($response->successful()) {
                $filename = $this->generation->id . '.png';
                Storage::disk('public')->put("images/{$filename}", $response->body());
                $this->generation->image_url = Storage::url("images/{$filename}");
                $this->generation->save();
            }
        } catch (\Exception $e) {
            logger()->warning("Failed to download image for generation {$this->generation->id}: {$e->getMessage()}");
        }
    }
}
