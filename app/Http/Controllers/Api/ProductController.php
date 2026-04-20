<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /**
     * Display a listing of products.
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Super admin can see all products across shops
            if ($user->role === 'super_admin') {
                $query = Product::with(['category', 'shop']);
            } else {
                $query = Product::where('shop_id', $user->shop_id)
                    ->with('category');
            }
            
            // Filter by category
            if ($request->has('category') && $request->category) {
                $query->where('category_id', $request->category);
            }
            
            // Filter by status
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }
            
            // Search by name, SKU, or barcode
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('sku', 'like', "%{$search}%")
                      ->orWhere('barcode', 'like', "%{$search}%");
                });
            }
            
            // Low stock filter
            if ($request->has('low_stock') && $request->low_stock === 'true') {
                $query->whereRaw('stock_quantity <= min_stock_level');
            }
            
            // Sorting
            $sortField = $request->get('sort_by', 'created_at');
            $sortDirection = $request->get('sort_direction', 'desc');
            $query->orderBy($sortField, $sortDirection);
            
            // Pagination
            $perPage = $request->get('per_page', 20);
            $products = $query->paginate($perPage);
            
            return response()->json([
                'success' => true,
                'data' => $products->items(),
                'meta' => [
                    'current_page' => $products->currentPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'last_page' => $products->lastPage(),
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching products: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch products',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Store a newly created product.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'sku' => 'required|string|unique:products,sku',
                'barcode' => 'nullable|string|unique:products,barcode',
                'category_id' => 'nullable|exists:categories,id',
                'description' => 'nullable|string',
                'cost_price' => 'required|numeric|min:0',
                'selling_price' => 'required|numeric|min:0',
                'tax_rate' => 'nullable|numeric|min:0|max:100',
                'stock_quantity' => 'nullable|integer|min:0',
                'min_stock_level' => 'nullable|integer|min:0',
                'max_stock_level' => 'nullable|integer|min:0',
                'unit' => 'nullable|string|max:50',
                'weight' => 'nullable|string|max:50',
                'images' => 'nullable|array',
                'attributes' => 'nullable|array',
                'status' => 'in:active,inactive',
            ]);
            
            // Cast numeric values
            $validated['selling_price'] = (float) $validated['selling_price'];
            $validated['cost_price'] = (float) ($validated['cost_price'] ?? 0);
            $validated['tax_rate'] = (float) ($validated['tax_rate'] ?? 0);
            $validated['stock_quantity'] = (int) ($validated['stock_quantity'] ?? 0);
            $validated['min_stock_level'] = (int) ($validated['min_stock_level'] ?? 5);
            $validated['max_stock_level'] = isset($validated['max_stock_level']) ? (int) $validated['max_stock_level'] : null;
            
            // Generate UUID and other fields
            $validated['id'] = (string) Str::uuid();
            $validated['shop_id'] = Auth::user()->shop_id;
            $validated['slug'] = Str::slug($validated['name']) . '-' . Str::random(6);
            $validated['created_by'] = Auth::id();
            
            // Create product
            $product = Product::create($validated);
            
            // Log initial stock movement if stock > 0
            if ($product->stock_quantity > 0) {
                StockMovement::create([
                    'id' => (string) Str::uuid(),
                    'shop_id' => $product->shop_id,
                    'product_id' => $product->id,
                    'type' => 'in',
                    'quantity' => $product->stock_quantity,
                    'previous_quantity' => 0,
                    'new_quantity' => $product->stock_quantity,
                    'reference' => 'INITIAL',
                    'reason' => 'Initial stock',
                    'user_id' => Auth::id(),
                ]);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Product created successfully',
                'data' => $product->load('category')
            ], 201);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating product: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create product',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Display the specified product.
     */
    public function show($id)
    {
        try {
            $user = Auth::user();
            
            // Find product
            if ($user->role === 'super_admin') {
                $product = Product::with(['category', 'shop', 'stockMovements', 'creator'])
                    ->findOrFail($id);
            } else {
                $product = Product::with(['category', 'stockMovements', 'creator'])
                    ->where('shop_id', $user->shop_id)
                    ->findOrFail($id);
            }
            
            return response()->json([
                'success' => true,
                'data' => $product
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching product: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch product',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update the specified product.
     */
    public function update(Request $request, $id)
    {
        try {
            $user = Auth::user();
            
            // Find product
            if ($user->role === 'super_admin') {
                $product = Product::findOrFail($id);
            } else {
                $product = Product::where('shop_id', $user->shop_id)
                    ->findOrFail($id);
            }
            
            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'sku' => ['sometimes', 'string', Rule::unique('products')->ignore($product->id)],
                'barcode' => ['nullable', 'string', Rule::unique('products')->ignore($product->id)],
                'category_id' => 'nullable|exists:categories,id',
                'description' => 'nullable|string',
                'cost_price' => 'sometimes|numeric|min:0',
                'selling_price' => 'sometimes|numeric|min:0',
                'tax_rate' => 'nullable|numeric|min:0|max:100',
                'stock_quantity' => 'nullable|integer|min:0',
                'min_stock_level' => 'nullable|integer|min:0',
                'max_stock_level' => 'nullable|integer|min:0',
                'unit' => 'nullable|string|max:50',
                'weight' => 'nullable|string|max:50',
                'images' => 'nullable|array',
                'attributes' => 'nullable|array',
                'status' => 'in:active,inactive',
            ]);
            
            // Cast numeric values
            if (isset($validated['selling_price'])) {
                $validated['selling_price'] = (float) $validated['selling_price'];
            }
            if (isset($validated['cost_price'])) {
                $validated['cost_price'] = (float) $validated['cost_price'];
            }
            if (isset($validated['tax_rate'])) {
                $validated['tax_rate'] = (float) $validated['tax_rate'];
            }
            
            // Track stock changes
            $oldStock = $product->stock_quantity;
            $newStock = $validated['stock_quantity'] ?? $oldStock;
            
            // Update product
            $product->update($validated);
            
            // Log stock movement if changed
            if ($oldStock != $newStock) {
                StockMovement::create([
                    'id' => (string) Str::uuid(),
                    'shop_id' => $product->shop_id,
                    'product_id' => $product->id,
                    'type' => 'adjustment',
                    'quantity' => $newStock - $oldStock,
                    'previous_quantity' => $oldStock,
                    'new_quantity' => $newStock,
                    'reference' => 'MANUAL',
                    'reason' => 'Manual stock adjustment',
                    'user_id' => Auth::id(),
                ]);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Product updated successfully',
                'data' => $product->fresh('category')
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating product: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update product',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Remove the specified product.
     */
    public function destroy($id)
    {
        try {
            $user = Auth::user();
            
            // Find product
            if ($user->role === 'super_admin') {
                $product = Product::findOrFail($id);
            } else {
                $product = Product::where('shop_id', $user->shop_id)
                    ->findOrFail($id);
            }
            
            // Check if product has any invoice items
            if ($product->invoiceItems()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete product with existing sales records'
                ], 400);
            }
            
            $product->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Product deleted successfully'
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting product: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete product',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get stock history for a product.
     */
    public function stockHistory($id)
    {
        try {
            $user = Auth::user();
            
            // Find product
            if ($user->role === 'super_admin') {
                $product = Product::findOrFail($id);
            } else {
                $product = Product::where('shop_id', $user->shop_id)
                    ->findOrFail($id);
            }
            
            $history = StockMovement::where('product_id', $product->id)
                ->with('user')
                ->orderBy('created_at', 'desc')
                ->paginate(50);
            
            return response()->json([
                'success' => true,
                'data' => $history
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching stock history: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch stock history',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Bulk import products.
     */
    public function bulkImport(Request $request)
    {
        try {
            $validated = $request->validate([
                'products' => 'required|array',
                'products.*.name' => 'required|string|max:255',
                'products.*.sku' => 'required|string',
                'products.*.selling_price' => 'required|numeric|min:0',
                'products.*.cost_price' => 'nullable|numeric|min:0',
                'products.*.stock_quantity' => 'nullable|integer|min:0',
                'products.*.category_id' => 'nullable|exists:categories,id',
            ]);
            
            $imported = [];
            $errors = [];
            
            foreach ($validated['products'] as $index => $productData) {
                try {
                    // Check if SKU already exists
                    $existingProduct = Product::where('shop_id', Auth::user()->shop_id)
                        ->where('sku', $productData['sku'])
                        ->first();
                    
                    if ($existingProduct) {
                        $errors[] = "Row {$index}: SKU '{$productData['sku']}' already exists";
                        continue;
                    }
                    
                    $productData['id'] = (string) Str::uuid();
                    $productData['shop_id'] = Auth::user()->shop_id;
                    $productData['slug'] = Str::slug($productData['name']) . '-' . Str::random(6);
                    $productData['created_by'] = Auth::id();
                    $productData['selling_price'] = (float) $productData['selling_price'];
                    $productData['cost_price'] = (float) ($productData['cost_price'] ?? 0);
                    $productData['stock_quantity'] = (int) ($productData['stock_quantity'] ?? 0);
                    $productData['min_stock_level'] = 5;
                    $productData['tax_rate'] = 0;
                    $productData['status'] = 'active';
                    
                    $product = Product::create($productData);
                    $imported[] = $product;
                    
                } catch (\Exception $e) {
                    $errors[] = "Row {$index}: " . $e->getMessage();
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => "Imported {$imported} products successfully",
                'data' => [
                    'imported' => $imported,
                    'imported_count' => count($imported),
                    'errors' => $errors,
                    'errors_count' => count($errors)
                ]
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error bulk importing products: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to import products',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get low stock products.
     */
    public function lowStock(Request $request)
    {
        try {
            $user = Auth::user();
            
            if ($user->role === 'super_admin') {
                $products = Product::with('shop')
                    ->whereRaw('stock_quantity <= min_stock_level')
                    ->where('status', 'active')
                    ->orderByRaw('stock_quantity / min_stock_level ASC')
                    ->limit(20)
                    ->get();
            } else {
                $products = Product::where('shop_id', $user->shop_id)
                    ->whereRaw('stock_quantity <= min_stock_level')
                    ->where('status', 'active')
                    ->orderByRaw('stock_quantity / min_stock_level ASC')
                    ->limit(20)
                    ->get();
            }
            
            return response()->json([
                'success' => true,
                'data' => $products
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching low stock products: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch low stock products',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Adjust product stock.
     */
    public function adjustStock(Request $request, $id)
    {
        try {
            $user = Auth::user();
            
            // Find product
            if ($user->role === 'super_admin') {
                $product = Product::findOrFail($id);
            } else {
                $product = Product::where('shop_id', $user->shop_id)
                    ->findOrFail($id);
            }
            
            $validated = $request->validate([
                'quantity' => 'required|integer',
                'type' => 'required|in:add,subtract,set',
                'reason' => 'nullable|string',
            ]);
            
            $oldStock = $product->stock_quantity;
            
            switch ($validated['type']) {
                case 'add':
                    $newStock = $oldStock + $validated['quantity'];
                    break;
                case 'subtract':
                    $newStock = max(0, $oldStock - $validated['quantity']);
                    break;
                case 'set':
                    $newStock = max(0, $validated['quantity']);
                    break;
                default:
                    $newStock = $oldStock;
            }
            
            $product->stock_quantity = $newStock;
            $product->save();
            
            // Log stock movement
            StockMovement::create([
                'id' => (string) Str::uuid(),
                'shop_id' => $product->shop_id,
                'product_id' => $product->id,
                'type' => 'adjustment',
                'quantity' => $newStock - $oldStock,
                'previous_quantity' => $oldStock,
                'new_quantity' => $newStock,
                'reference' => 'MANUAL_ADJUSTMENT',
                'reason' => $validated['reason'] ?? 'Manual stock adjustment',
                'user_id' => Auth::id(),
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Stock adjusted successfully',
                'data' => $product
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error adjusting stock: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to adjust stock',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}