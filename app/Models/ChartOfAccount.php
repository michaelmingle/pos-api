<?php
// app/Models/ChartOfAccount.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ChartOfAccount extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = [
        'id', 'shop_id', 'code', 'name', 'type', 'sub_type', 'balance', 'parent_account_id', 'is_active'
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
    
    public function parent()
    {
        return $this->belongsTo(ChartOfAccount::class, 'parent_account_id');
    }
    
    public function children()
    {
        return $this->hasMany(ChartOfAccount::class, 'parent_account_id');
    }
    
    public function journalLines()
    {
        return $this->hasMany(JournalEntryLine::class, 'account_id');
    }
}