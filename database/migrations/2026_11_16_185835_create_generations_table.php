<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_id')->nullable();
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images')->nullable();
            $table->string('status')->default('queued');
            $table->json('rates');
            $table->json('usage')->nullable();
            $table->string('image_url')->nullable();
            $table->datetime('image_url_expires_at')->nullable();
            $table->bigInteger('provider_cost_nano')->default(0);
            $table->bigInteger('user_charge_nano')->default(0);
            $table->string('error_type')->nullable();
            $table->text('error_message')->nullable();
            $table->string('idempotency_key')->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
