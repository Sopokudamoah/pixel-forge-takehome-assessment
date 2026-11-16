<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditTransaction extends Model
{
    protected $fillable = ['user_id', 'amount_nano', 'type', 'reference'];
    public function user() { return $this->belongsTo(User::class); }
}
