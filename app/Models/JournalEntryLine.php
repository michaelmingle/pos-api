<?php
// app/Models/JournalEntryLine.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class JournalEntryLine extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = [
        'id', 'journal_entry_id', 'account_id', 'debit', 'credit', 'description', 'reference_id', 'reference_type'
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
    
    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }
    
    public function account()
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }
}