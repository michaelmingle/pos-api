<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        // Super admin can see all categories from all shops
        if ($user->role === 'super_admin') {
            $categories = Category::with('shop')->get();
        } else {
            // Regular users only see categories from their shop
            $categories = Category::where('shop_id', $user->shop_id)->get();
        }
        
        return response()->json($categories);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive',
        ]);

        $user = Auth::user();
        
        // Check if user has a shop (super admin can't create categories)
        if ($user->role !== 'super_admin' && !$user->shop_id) {
            return response()->json([
                'message' => 'User does not have a shop assigned'
            ], 400);
        }
        
        // Generate slug from name
        $slug = Str::slug($request->name) . '-' . Str::random(6);
        
        $category = Category::create([
            'id' => (string) Str::uuid(),
            'shop_id' => $user->shop_id, // This will be null for super admin
            'name' => $request->name,
            'slug' => $slug,
            'description' => $request->description,
            'status' => $request->status,
            'created_by' => Auth::id(),
        ]);

        return response()->json($category, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Category $category)
    {
        $user = Auth::user();
        
        // Check authorization
        if ($user->role !== 'super_admin' && $category->shop_id !== $user->shop_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        return response()->json($category);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Category $category)
    {
        $user = Auth::user();
        
        // Check authorization
        if ($user->role !== 'super_admin' && $category->shop_id !== $user->shop_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive',
        ]);
        
        // Update slug if name changed
        $data = [
            'name' => $request->name,
            'description' => $request->description,
            'status' => $request->status,
        ];
        
        if ($category->name !== $request->name) {
            $data['slug'] = Str::slug($request->name) . '-' . Str::random(6);
        }
        
        $category->update($data);

        return response()->json($category);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Category $category)
    {
        $user = Auth::user();
        
        // Check authorization
        if ($user->role !== 'super_admin' && $category->shop_id !== $user->shop_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $category->delete();
        return response()->json(['message' => 'Category deleted successfully']);
    }
}