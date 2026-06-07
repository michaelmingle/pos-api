<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Plan extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'slug', 'name', 'tagline',
        'monthly_price_pesewas', 'yearly_price_pesewas',
        'item_limit', 'branch_limit', 'user_limit',
        'features', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'features' => 'array',
        'is_active' => 'boolean',
        'monthly_price_pesewas' => 'integer',
        'yearly_price_pesewas' => 'integer',
        'item_limit' => 'integer',
        'branch_limit' => 'integer',
        'user_limit' => 'integer',
        'sort_order' => 'integer',
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

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function priceForCycle(string $cycle): int
    {
        return $cycle === 'yearly' ? $this->yearly_price_pesewas : $this->monthly_price_pesewas;
    }
}
