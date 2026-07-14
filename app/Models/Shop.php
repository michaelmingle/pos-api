<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Shop extends Model
{
    use HasFactory, SoftDeletes;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'name', 'slug', 'email', 'phone', 'sms_sender_id', 'address', 'city', 'state',
        'country', 'zip_code', 'tax_number', 'currency', 'timezone', 'status',
        'store_type', 'settings', 'created_by'
    ];

    protected $casts = [
        'settings' => 'array',
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

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }

    public function isSupermarket()
    {
        return $this->store_type === 'supermarket';
    }

    public function isPharmacy()
    {
        return $this->store_type === 'pharmacy';
    }
}