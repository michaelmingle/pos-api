<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

            // Branch filter (admin can pass branch_id to scope to one)
            if ($request->filled('branch_id') && $request->branch_id !== 'all') {
                $query->where('branch_id', $request->branch_id);
            } elseif (in_array($user->role, ['cashier', 'sales_person'], true) && !empty($user->branch_id)) {
                $query->where('branch_id', $user->branch_id);
            }

            // Expiry / damaged filters
            if ($request->boolean('expired')) {
                $query->expired();
            } elseif ($request->filled('expiring_days')) {
                $query->expiringWithin((int) $request->expiring_days);
            }
            if ($request->boolean('damaged')) {
                $query->hasDamaged();
            }

            // Sorting
            $sortField = $request->get('sort_by', 'created_at');
            $sortDirection = $request->get('sort_direction', 'desc');
            $query->orderBy($sortField, $sortDirection);
            
            // Pagination
            $perPage = $request->get('per_page', 20);
            $products = $query->paginate($perPage);
            
            // Format products with full image URLs
            $processedProducts = collect($products->items())->map(function($product) use ($user) {
                return $this->formatProductWithImages($product, $user);
            });
            
            return response()->json([
                'success' => true,
                'data' => $processedProducts,
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
     * Format product with full image URLs
     */
    private function formatProductWithImages($product, $user = null)
    {
        $images = [];
        
        // Get shop_id
        $shopId = $product->shop_id;
        if (!$shopId && $user) {
            $shopId = $user->shop_id;
        }
        
        // Decode images from JSON
        if ($product->images) {
            $decodedImages = json_decode($product->images, true);
            if (is_array($decodedImages)) {
                foreach ($decodedImages as $image) {
                    // Clean up the path
                    $image = str_replace('\\', '/', $image);
                    
                    // Extract just the filename
                    $filename = basename($image);

                    // Build the URL via the app-served image route (not the
                    // /storage symlink) — see routes/api.php + serveImage()
                    // below for why: shared hosts like Bluehost/cPanel often
                    // disable FollowSymLinks, which makes Apache 403 on the
                    // symlinked public/storage path even though the file is
                    // right there on disk.
                    if ($shopId) {
                        $images[] = url("/api/product-images/{$shopId}/{$filename}");
                    } else {
                        $images[] = url("/storage/{$filename}");
                    }
                }
            }
        }
        
        // Create product array with all fields
        $productArray = $product->toArray();
        $productArray['images'] = $images;
        $productArray['shop_id'] = $shopId;
        
        // Add category name if exists
        if ($product->category) {
            $productArray['category_name'] = $product->category->name;
        }
        
        // Add formatted prices
        $productArray['formatted_selling_price'] = 'GHS ' . number_format($product->selling_price, 2);
        $productArray['formatted_cost_price'] = 'GHS ' . number_format($product->cost_price, 2);
        
        // Add stock status
        $productArray['stock_status'] = $this->getStockStatus($product);
        
        return $productArray;
    }
    
    /**
     * Serve a product image directly through Laravel instead of relying on
     * the public/storage symlink + Apache static-file serving.
     *
     * Why this exists: on shared hosting (cPanel/Bluehost and similar),
     * Apache commonly disables FollowSymLinks for security. `storage:link`
     * creates public/storage as a symlink to storage/app/public — with
     * FollowSymLinks off, Apache 403s on that path even though the target
     * file exists and is readable, because it refuses to traverse the
     * symlink at all. Streaming the file through PHP sidesteps Apache's
     * symlink policy entirely: this route is registered in web.php (public,
     * no auth) and only exists to serve image bytes.
     *
     * Path traversal is blocked by validating the shop id looks like a UUID
     * and resolving the final path with realpath(), then confirming it's
     * still inside the shop's own image directory before streaming it.
     */
    public function serveImage(string $shopId, string $filename)
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $shopId)) {
            abort(404);
        }

        $directory = storage_path("app/public/products/{$shopId}");
        $requested = realpath($directory . DIRECTORY_SEPARATOR . basename($filename));

        if (
            $requested === false
            || !str_starts_with($requested, realpath($directory) ?: $directory . DIRECTORY_SEPARATOR)
            || !is_file($requested)
        ) {
            abort(404);
        }

        return response()->file($requested, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * Get stock status label
     */
    private function getStockStatus($product)
    {
        if ($product->stock_quantity <= 0) {
            return 'Out of Stock';
        }
        if ($product->stock_quantity <= $product->min_stock_level) {
            return 'Low Stock';
        }
        return 'In Stock';
    }
    
    /**
     * Compress and resize image using GD library
     */
    private function compressAndResizeImage($sourcePath, $destinationPath, $maxWidth = 800, $quality = 80)
    {
        try {
            // Get image info
            $imageInfo = getimagesize($sourcePath);
            if (!$imageInfo) {
                return false;
            }
            
            $sourceImage = null;
            $mimeType = $imageInfo['mime'];
            
            // Create image resource based on mime type
            switch ($mimeType) {
                case 'image/jpeg':
                    $sourceImage = imagecreatefromjpeg($sourcePath);
                    break;
                case 'image/png':
                    $sourceImage = imagecreatefrompng($sourcePath);
                    // Preserve transparency for PNG
                    imagealphablending($sourceImage, true);
                    imagesavealpha($sourceImage, true);
                    break;
                case 'image/webp':
                    $sourceImage = imagecreatefromwebp($sourcePath);
                    break;
                case 'image/gif':
                    $sourceImage = imagecreatefromgif($sourcePath);
                    break;
                default:
                    return false;
            }
            
            if (!$sourceImage) {
                return false;
            }
            
            // Get original dimensions
            $origWidth = imagesx($sourceImage);
            $origHeight = imagesy($sourceImage);
            
            // Calculate new dimensions (maintain aspect ratio)
            $ratio = $origWidth / $origHeight;
            $newWidth = $maxWidth;
            $newHeight = $maxWidth / $ratio;
            
            // Create new image
            $newImage = imagecreatetruecolor($newWidth, $newHeight);
            
            // Preserve transparency for PNG
            if ($mimeType === 'image/png') {
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);
                $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
                imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
            }
            
            // Resize image
            imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
            
            // Save image
            switch ($mimeType) {
                case 'image/jpeg':
                    imagejpeg($newImage, $destinationPath, $quality);
                    break;
                case 'image/png':
                    imagepng($newImage, $destinationPath, 9);
                    break;
                case 'image/webp':
                    imagewebp($newImage, $destinationPath, $quality);
                    break;
                case 'image/gif':
                    imagegif($newImage, $destinationPath);
                    break;
            }
            
            // Free memory
            imagedestroy($sourceImage);
            imagedestroy($newImage);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Error compressing image: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Upload and save product images to storage
     */
    private function uploadImages($images)
    {
        $uploadedImages = [];
        
        if (!$images || !is_array($images)) {
            return $uploadedImages;
        }
        
        // Get shop ID from authenticated user
        $shopId = Auth::user()->shop_id;
        
        // Create directory structure: products/{shop_id}/
        $directory = "products/{$shopId}";
        $fullPath = storage_path("app/public/{$directory}");
        
        // Ensure directory exists
        if (!file_exists($fullPath)) {
            mkdir($fullPath, 0755, true);
            Log::info("Created directory: {$fullPath}");
        }
        
        foreach ($images as $index => $image) {
            try {
                // Check if it's a base64 image
                if (preg_match('/^data:image\/(\w+);base64,/', $image, $matches)) {
                    $imageType = $matches[1];
                    $imageData = substr($image, strpos($image, ',') + 1);
                    $imageData = base64_decode($imageData);
                    
                    $fileName = time() . "_{$index}_" . Str::random(10) . ".jpg";
                    $tempPath = storage_path("app/public/{$directory}/temp_{$fileName}");
                    $finalPath = storage_path("app/public/{$directory}/{$fileName}");
                    
                    // Save temporary file
                    file_put_contents($tempPath, $imageData);
                    
                    // Compress and resize image (max width 800px)
                    $this->compressAndResizeImage($tempPath, $finalPath, 800, 80);
                    
                    // Delete temporary file
                    if (file_exists($tempPath)) {
                        unlink($tempPath);
                    }
                    
                    // Store the relative path
                    $uploadedImages[] = "/storage/{$directory}/{$fileName}";
                    
                    Log::info("Image saved: {$finalPath}");
                }
                // Check if it's a URL (already uploaded)
                elseif (filter_var($image, FILTER_VALIDATE_URL)) {
                    $uploadedImages[] = $image;
                }
            } catch (\Exception $e) {
                Log::error('Error uploading image: ' . $e->getMessage());
            }
        }
        
        return $uploadedImages;
    }
    
    /**
     * Delete product images from storage
     */
    private function deleteImages($imagePaths, $shopId = null)
    {
        if (!$imagePaths || !is_array($imagePaths)) {
            return;
        }
        
        $shopId = $shopId ?? Auth::user()->shop_id;
        
        foreach ($imagePaths as $imagePath) {
            try {
                // Extract filename from URL or path
                $filename = basename($imagePath);
                
                // Build the full path
                $relativePath = "products/{$shopId}/{$filename}";
                $fullPath = storage_path("app/public/{$relativePath}");
                
                // Delete the file if it exists
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                    Log::info("Image deleted: {$fullPath}");
                }
            } catch (\Exception $e) {
                Log::error('Error deleting image: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Store a newly created product.
     */
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $shopId = Auth::user()->shop_id;
            $limits = app(\App\Services\SubscriptionLimits::class);
            if ($shopId && !$limits->canCreate($shopId, 'products')) {
                DB::rollBack();
                $limit = $limits->limitFor($shopId, 'products');
                return response()->json([
                    'success' => false,
                    'code' => 'PLAN_LIMIT_REACHED',
                    'resource' => 'products',
                    'limit' => $limit,
                    'message' => "Your plan allows up to {$limit} products. Upgrade to add more.",
                ], 402);
            }

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
                'expiry_date' => 'nullable|date',
                'damaged_quantity' => 'nullable|integer|min:0',
                'branch_id' => 'nullable|uuid|exists:branches,id',
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
            $validated['damaged_quantity'] = (int) ($validated['damaged_quantity'] ?? 0);

            // Generate UUID and other fields
            $validated['id'] = (string) Str::uuid();
            $validated['shop_id'] = Auth::user()->shop_id;
            $validated['branch_id'] = $validated['branch_id'] ?? Auth::user()->branch_id;
            $validated['slug'] = Str::slug($validated['name']) . '-' . Str::random(6);
            $validated['created_by'] = Auth::id();
            
            // Handle image uploads
            if (isset($validated['images']) && is_array($validated['images']) && count($validated['images']) > 0) {
                $uploadedImages = $this->uploadImages($validated['images']);
                $validated['images'] = json_encode($uploadedImages);
                Log::info('Images uploaded: ' . json_encode($uploadedImages));
            } else {
                $validated['images'] = null;
            }
            
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
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Product created successfully',
                'data' => $this->formatProductWithImages($product)
            ], 201);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating product: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create product: ' . $e->getMessage(),
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
                'data' => $this->formatProductWithImages($product, $user)
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
        DB::beginTransaction();
        
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
                'expiry_date' => 'nullable|date',
                'damaged_quantity' => 'nullable|integer|min:0',
                'branch_id' => 'nullable|uuid|exists:branches,id',
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
            
            // Get old images for deletion
            $oldImages = json_decode($product->images ?? '[]', true);
            
            // Handle image uploads for updates
            if (isset($validated['images']) && is_array($validated['images'])) {
                // Upload new images
                $uploadedImages = $this->uploadImages($validated['images']);
                $validated['images'] = json_encode($uploadedImages);
                
                // Delete old images that are no longer used
                $imagesToDelete = array_diff($oldImages, $uploadedImages);
                $this->deleteImages($imagesToDelete, $product->shop_id);
            }
            
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
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Product updated successfully',
                'data' => $this->formatProductWithImages($product->fresh('category'), $user)
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating product: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update product: ' . $e->getMessage(),
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
            
            // Delete product images from storage
            $oldImages = json_decode($product->images ?? '[]', true);
            $this->deleteImages($oldImages, $product->shop_id);
            
            $product->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Product deleted successfully'
            ]);
            
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
            $user = Auth::user();
            
            foreach ($validated['products'] as $index => $productData) {
                try {
                    // Check if SKU already exists
                    $existingProduct = Product::where('shop_id', $user->shop_id)
                        ->where('sku', $productData['sku'])
                        ->first();
                    
                    if ($existingProduct) {
                        $errors[] = "Row {$index}: SKU '{$productData['sku']}' already exists";
                        continue;
                    }
                    
                    $productData['id'] = (string) Str::uuid();
                    $productData['shop_id'] = $user->shop_id;
                    $productData['slug'] = Str::slug($productData['name']) . '-' . Str::random(6);
                    $productData['created_by'] = $user->id;
                    $productData['selling_price'] = (float) $productData['selling_price'];
                    $productData['cost_price'] = (float) ($productData['cost_price'] ?? 0);
                    $productData['stock_quantity'] = (int) ($productData['stock_quantity'] ?? 0);
                    $productData['min_stock_level'] = 5;
                    $productData['tax_rate'] = 0;
                    $productData['status'] = 'active';
                    
                    $product = Product::create($productData);
                    $imported[] = $this->formatProductWithImages($product, $user);
                    
                } catch (\Exception $e) {
                    $errors[] = "Row {$index}: " . $e->getMessage();
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => "Imported " . count($imported) . " products successfully",
                'data' => [
                    'imported' => $imported,
                    'imported_count' => count($imported),
                    'errors' => $errors,
                    'errors_count' => count($errors)
                ]
            ]);
            
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
            
            $processedProducts = $products->map(function($product) use ($user) {
                return $this->formatProductWithImages($product, $user);
            });
            
            return response()->json([
                'success' => true,
                'data' => $processedProducts
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
     * Products expiring within a window. Defaults to 30 days; can be overridden by ?days=N.
     */
    public function expiring(Request $request)
    {
        $user = Auth::user();
        $days = (int) $request->get('days', 30);

        $query = Product::query()
            ->where('status', 'active')
            ->expiringWithin($days);

        if ($user->role !== 'super_admin') {
            $query->where('shop_id', $user->shop_id);
            if (!empty($user->branch_id)) {
                $query->where(function ($q) use ($user) {
                    $q->whereNull('branch_id')->orWhere('branch_id', $user->branch_id);
                });
            }
        }

        $products = $query->with('category')->orderBy('expiry_date')->limit(100)->get();
        $items = $products->map(fn ($p) => $this->formatProductWithImages($p, $user));

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'days' => $days,
                'count' => count($items),
            ],
        ]);
    }

    /**
     * Products already past their expiry date.
     */
    public function expired(Request $request)
    {
        $user = Auth::user();

        $query = Product::query()
            ->where('status', 'active')
            ->expired();

        if ($user->role !== 'super_admin') {
            $query->where('shop_id', $user->shop_id);
            if (!empty($user->branch_id)) {
                $query->where(function ($q) use ($user) {
                    $q->whereNull('branch_id')->orWhere('branch_id', $user->branch_id);
                });
            }
        }

        $products = $query->with('category')->orderBy('expiry_date')->limit(100)->get();
        $items = $products->map(fn ($p) => $this->formatProductWithImages($p, $user));

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => ['count' => count($items)],
        ]);
    }

    /**
     * Products with damaged quantity > 0.
     */
    public function damaged(Request $request)
    {
        $user = Auth::user();

        $query = Product::query()->hasDamaged();
        if ($user->role !== 'super_admin') {
            $query->where('shop_id', $user->shop_id);
        }

        $products = $query->with('category')->orderByDesc('damaged_quantity')->limit(100)->get();
        $items = $products->map(fn ($p) => $this->formatProductWithImages($p, $user));

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => ['count' => count($items)],
        ]);
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
                'data' => $this->formatProductWithImages($product, $user)
            ]);
            
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