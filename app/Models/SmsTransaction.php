<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SmsTransaction extends Model
{
    protected $table = 'sms_transactions';
    
    protected $keyType = 'string';
    public $incrementing = false;
    
    protected $fillable = [
        'id', // Add 'id' to fillable
        'shop_id',
        'message_id',
        'recipient',
        'cost',
        'recipient_count',
        'balance_before',
        'balance_after',
        'cost_info',
        'type'
    ];
    
    protected $casts = [
        'cost' => 'decimal:4',
        'balance_before' => 'decimal:4',
        'balance_after' => 'decimal:4',
        'cost_info' => 'array',
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