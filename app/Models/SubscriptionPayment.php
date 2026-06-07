<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SubscriptionPayment extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'subscription_id', 'paystack_reference',
        'amount_pesewas', 'currency', 'status', 'channel',
        'paid_at', 'raw_response',
    ];

    protected $casts = [
        'amount_pesewas' => 'integer',
        'paid_at' => 'datetime',
        'raw_response' => 'array',
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

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}
