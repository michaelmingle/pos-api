<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SmsBalance extends Model
{
    protected $table = 'sms_balances';
    
    protected $keyType = 'string';
    public $incrementing = false;
    
    protected $fillable = [
        'id', // Add 'id' to fillable
        'shop_id',
        'balance',
        'total_sent',
        'total_cost',
        'last_transaction_at'
    ];
    
    protected $casts = [
        'balance' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'last_transaction_at' => 'datetime',
    ];
    
    // Auto-generate UUID when creating new record
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