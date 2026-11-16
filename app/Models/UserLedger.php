<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserLedger extends Model
{
    protected $table = 'user_ledger';
    protected $fillable = ['user_id', 'generation_id', 'amount_nano', 'type'];
    public function user() { return $this->belongsTo(User::class); }
    public function generation() { return $this->belongsTo(Generation::class, 'generation_id'); }
}
