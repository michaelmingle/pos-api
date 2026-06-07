<?php
// app/Models/GlobalTransaction.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class GlobalTransaction extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = [
        'id', 'shop_id', 'transaction_type', 'reference_type', 'reference_id', 
        'amount', 'currency', 'details', 'transaction_date'
    ];
    
    protected $casts = [
        'details' => 'array',
        'transaction_date' => 'datetime',
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
}