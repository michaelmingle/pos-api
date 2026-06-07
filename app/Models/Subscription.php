<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Subscription extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'shop_id', 'plan_id', 'billing_cycle', 'status',
        'current_period_start', 'current_period_end', 'trial_ends_at', 'cancelled_at',
        'paystack_customer_code', 'paystack_authorization_code',
        'amount_pesewas', 'currency', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'amount_pesewas' => 'integer',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'trial_ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
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

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function payments()
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && (!$this->current_period_end || $this->current_period_end->isFuture());
    }
}
