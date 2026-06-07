<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SMS extends Model
{
    protected $table = 's_m_s';
    
    protected $keyType = 'string';
    public $incrementing = false;
    
    protected $fillable = [
        'id', // Add 'id' to fillable
        'customer_id',
        'recipient',
        'message',
        'type',
        'status',
        'message_id',
        'cost',
        'balance_before',
        'balance_after',
        'cost_info'
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
    
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}