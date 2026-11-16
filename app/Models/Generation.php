<?php

namespace App\Models;

use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Generation extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $casts = [
        'reference_images' => 'array',
        'rates' => 'array',
        'usage' => 'array',
        'metadata' => 'array',
        'image_url_expires_at' => 'datetime',
    ];

    protected $fillable = [
        'id', 'user_id', 'provider_id', 'prompt', 'size', 'quality',
        'reference_images', 'status', 'rates', 'usage', 'image_url',
        'image_url_expires_at', 'provider_cost_nano', 'user_charge_nano',
        'error_type', 'error_message', 'idempotency_key', 'metadata',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function user() { return $this->belongsTo(User::class); }
    public function ledgerEntry() { return $this->hasOne(UserLedger::class, 'generation_id'); }
    public function webhookEvents() { return $this->hasMany(WebhookEvent::class, 'generation_id'); }

    public function providerCost(): Money { return new Money($this->provider_cost_nano); }
    public function userCharge(): Money { return new Money($this->user_charge_nano); }
    public function isTerminal(): bool { return in_array($this->status, ['succeeded', 'failed']); }
    public function isPending(): bool { return in_array($this->status, ['queued', 'processing']); }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'queued' => 'Queued',
            'processing' => 'Processing',
            'succeeded' => 'Completed',
            'failed' => 'Failed',
            default => $this->status,
        };
    }
}
