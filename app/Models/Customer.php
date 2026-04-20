<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Customer extends Model
{
    use SoftDeletes;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'shop_id', 'name', 'email', 'phone', 'address', 'city', 'state',
        'country', 'zip_code', 'total_spent', 'total_orders', 'credit_limit',
        'current_balance', 'birth_date', 'customer_type', 'metadata', 'created_by'
    ];

    protected $casts = [
        'total_spent' => 'decimal:2',
        'credit_limit' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'birth_date' => 'date',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
    public function scopeVip($query)
    {
        return $query->where('customer_type', 'vip');
    }

    public function scopeWholesale($query)
    {
        return $query->where('customer_type', 'wholesale');
    }

    public function scopeRegular($query)
    {
        return $query->where('customer_type', 'regular');
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%")
              ->orWhere('phone', 'like', "%{$search}%");
        });
    }

    // Accessors
    public function getFormattedTotalSpentAttribute()
    {
        return '$' . number_format($this->total_spent, 2);
    }

    public function getFormattedCreditLimitAttribute()
    {
        return '$' . number_format($this->credit_limit, 2);
    }

    public function getFormattedCurrentBalanceAttribute()
    {
        return '$' . number_format($this->current_balance, 2);
    }

    // Update totals
    public function updateTotals()
    {
        $this->total_orders = $this->invoices()->where('status', 'completed')->count();
        $this->total_spent = $this->invoices()
            ->where('status', 'completed')
            ->sum('total');
        $this->save();
    }
}