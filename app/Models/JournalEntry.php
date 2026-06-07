<?php
// app/Models/JournalEntry.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class JournalEntry extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = [
        'id', 'shop_id', 'entry_number', 'entry_date', 'description', 'status', 'created_by'
    ];
    
    protected $casts = [
        'entry_date' => 'date',
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
    
    public function lines()
    {
        return $this->hasMany(JournalEntryLine::class);
    }
    
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}