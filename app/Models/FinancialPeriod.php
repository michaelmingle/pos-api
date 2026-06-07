<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialPeriod extends Model
{
    protected $fillable = [
        'shop_id', 'name', 'start_date', 'end_date', 'status'
    ];
    
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];
    
    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function journalEntries()
    {
        return $this->hasMany(JournalEntry::class);
    }

    
}
