<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StockMovement extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = [
        'id',
        'shop_id',
        'product_id',
        'invoice_id',
        'type',
        'quantity',
        'previous_quantity',
        'new_quantity',
        'reference',
        'reason',
        'user_id',
    ];
    
    protected $casts = [
        'quantity' => 'integer',
        'previous_quantity' => 'integer',
        'new_quantity' => 'integer',
        'created_at' => 'datetime',
    ];
    
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }
    
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    
    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}