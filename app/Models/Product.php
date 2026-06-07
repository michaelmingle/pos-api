<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use App\Traits\LogsActivity;

class Product extends Model
{
    use SoftDeletes, LogsActivity;

    /**
     * Disable auto-incrementing as we use UUID
     */
    public $incrementing = false;
    
    /**
     * Set key type to string for UUID
     */
    protected $keyType = 'string';
    
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'id',
        'shop_id',
        'branch_id',
        'category_id',
        'name',
        'slug',
        'sku',
        'barcode',
        'description',
        'cost_price',
        'selling_price',
        'tax_rate',
        'stock_quantity',
        'expiry_date',
        'damaged_quantity',
        'min_stock_level',
        'max_stock_level',
        'unit',
        'weight',
        'images',
        'attributes',
        'status',
        'created_by',
    ];
    
    /**
     * The attributes that should be cast.
     */
   protected $casts = [
    'selling_price' => 'decimal:2',
    'cost_price' => 'decimal:2',
    'tax_rate' => 'decimal:2',
    'stock_quantity' => 'integer',
    'damaged_quantity' => 'integer',
    'expiry_date' => 'date',
    'min_stock_level' => 'integer',
    'max_stock_level' => 'integer',
    'created_at' => 'datetime',
    'updated_at' => 'datetime',
    'images' => 'array',
];
    
    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'deleted_at',
    ];
    
    /**
     * Boot function to generate UUID on creating
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
            
            // Generate slug from name if not provided
            if (empty($model->slug) && !empty($model->name)) {
                $model->slug = Str::slug($model->name) . '-' . Str::random(6);
            }
        });
        
        static::updating(function ($model) {
            // Update slug if name changed
            if ($model->isDirty('name') && !empty($model->name)) {
                $model->slug = Str::slug($model->name) . '-' . Str::random(6);
            }
        });
    }
    
    /**
     * Get the shop that owns the product.
     */
    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Get the category that owns the product.
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }
    
    /**
     * Get the user who created the product.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    
    /**
     * Get the stock movements for the product.
     */
    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }
    
    /**
     * Get the invoice items for the product.
     */
    public function invoiceItems()
    {
        return $this->hasMany(InvoiceItem::class);
    }
    
    /**
     * Scope a query to only include active products.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
    
    /**
     * Scope a query to only include inactive products.
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }
    
    /**
     * Scope a query to only include low stock products.
     */
    public function scopeLowStock($query)
    {
        return $query->whereRaw('stock_quantity <= min_stock_level')
                     ->where('status', 'active');
    }
    
    /**
     * Scope a query to only include out of stock products.
     */
    public function scopeOutOfStock($query)
    {
        return $query->where('stock_quantity', '<=', 0);
    }

    public function scopeExpiringWithin($query, int $days)
    {
        $today = now()->toDateString();
        $threshold = now()->addDays($days)->toDateString();
        return $query->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [$today, $threshold]);
    }

    public function scopeExpired($query)
    {
        return $query->whereNotNull('expiry_date')
            ->where('expiry_date', '<', now()->toDateString());
    }

    public function scopeHasDamaged($query)
    {
        return $query->where('damaged_quantity', '>', 0);
    }

    /**
     * Scope a query to search products.
     */
    public function scopeSearch($query, $search)
    {
        return $query->where(function($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('sku', 'like', "%{$search}%")
              ->orWhere('barcode', 'like', "%{$search}%")
              ->orWhere('description', 'like', "%{$search}%");
        });
    }
    
    /**
     * Check if product is in stock.
     */
    public function isInStock()
    {
        return $this->stock_quantity > 0;
    }
    
    /**
     * Check if product is low on stock.
     */
    public function isLowStock()
    {
        return $this->stock_quantity <= $this->min_stock_level;
    }
    
    /**
     * Get the profit margin for the product.
     */
    public function getProfitMarginAttribute()
    {
        if ($this->cost_price > 0) {
            return (($this->selling_price - $this->cost_price) / $this->cost_price) * 100;
        }
        return 0;
    }
    
    /**
     * Get the formatted selling price.
     */
    public function getFormattedSellingPriceAttribute()
    {
        return '$' . number_format($this->selling_price, 2);
    }
    
    /**
     * Get the formatted cost price.
     */
    public function getFormattedCostPriceAttribute()
    {
        return '$' . number_format($this->cost_price, 2);
    }
    
    /**
     * Get the stock status label.
     */
    public function getStockStatusAttribute()
    {
        if ($this->stock_quantity <= 0) {
            return 'Out of Stock';
        }
        if ($this->stock_quantity <= $this->min_stock_level) {
            return 'Low Stock';
        }
        return 'In Stock';
    }
    
    /**
     * Get the stock status color class.
     */
    public function getStockStatusColorAttribute()
    {
        if ($this->stock_quantity <= 0) {
            return 'danger';
        }
        if ($this->stock_quantity <= $this->min_stock_level) {
            return 'warning';
        }
        return 'success';
    }
    
    /**
     * Decrease stock quantity.
     */
    public function decreaseStock($quantity)
    {
        $this->stock_quantity -= $quantity;
        $this->save();
        
        // Log stock movement
        StockMovement::create([
            'product_id' => $this->id,
            'shop_id' => $this->shop_id,
            'type' => 'out',
            'quantity' => $quantity,
            'previous_quantity' => $this->stock_quantity + $quantity,
            'new_quantity' => $this->stock_quantity,
            'reason' => 'Sale',
            'user_id' => Auth::id(),
        ]);
        
        return $this;
    }
    
    /**
     * Increase stock quantity.
     */
    public function increaseStock($quantity, $reason = 'Purchase')
    {
        $oldQuantity = $this->stock_quantity;
        $this->stock_quantity += $quantity;
        $this->save();
        
        // Log stock movement
        StockMovement::create([
            'product_id' => $this->id,
            'shop_id' => $this->shop_id,
            'type' => 'in',
            'quantity' => $quantity,
            'previous_quantity' => $oldQuantity,
            'new_quantity' => $this->stock_quantity,
            'reason' => $reason,
            'user_id' => Auth::id(),
        ]);
        
        return $this;
    }
}