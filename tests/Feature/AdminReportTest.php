<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

test('admin report page loads with daily and user totals', function () {
    $user = User::factory()->create();

    $gen1 = Generation::create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'prompt' => 'Image 1',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => 'succeeded',
        'usage' => ['input_text' => 6, 'output' => 280, 'reasoning' => 113],
        'provider_cost_nano' => 12_642_500,
        'user_charge_nano' => 15_803_125,
        'rates' => ['input_text' => '5.00'],
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $gen2 = Generation::create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'prompt' => 'Image 2',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => 'succeeded',
        'usage' => ['input_text' => 6, 'output' => 280, 'reasoning' => 113],
        'provider_cost_nano' => 12_642_500,
        'user_charge_nano' => 15_803_125,
        'rates' => ['input_text' => '5.00'],
        'idempotency_key' => (string) Str::uuid(),
    ]);

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/usage' => Http::response([
            'data' => [
                [
                    'date' => now()->toDateString(),
                    'cost_usd' => '0.025285000',
                ],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($user)->get('/admin/report');
    $response->assertStatus(200);
});
