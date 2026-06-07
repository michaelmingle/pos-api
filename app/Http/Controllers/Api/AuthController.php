<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    // Super Admin Login (no shop required)
    public function login(Request $request)
{
    $validator = Validator::make($request->all(), [
        'email' => 'required|email',
        'password' => 'required',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $user = User::with('shop')->where('email', $request->email)->first();

    if (!$user || !Hash::check($request->password, $user->password)) {
        throw ValidationException::withMessages([
            'email' => ['The provided credentials are incorrect.'],
        ]);
    }

    if ($user->status !== 'active') {
        return response()->json(['message' => 'Your account is inactive.'], 403);
    }

    // Delete old tokens
    $user->tokens()->delete();

    // Create new token
    $token = $user->createToken('pos-token')->plainTextToken;

    $response = [
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
        ],
        'token' => $token
    ];

    // Add shop info if user has a shop
    if ($user->shop) {
        $response['user']['shop_id'] = $user->shop->id;
        $response['user']['shop_name'] = $user->shop->name;
        $response['user']['storeName'] = $user->shop->name;
        $response['user']['storeType'] = $user->shop->store_type; // Add this line
        $response['user']['store_type'] = $user->shop->store_type; // Add this line as well for consistency
        
        // Also return the full shop object
        $response['shop'] = [
            'id' => $user->shop->id,
            'name' => $user->shop->name,
            'store_type' => $user->shop->store_type,
            'email' => $user->shop->email,
            'phone' => $user->shop->phone,
        ];
    }

    return response()->json($response);
}

    // Create Shop First, then create Shop Admin User
 // Create Shop First, then create Shop Admin User
public function registerShop(Request $request)
{
    $validator = Validator::make($request->all(), [
        'shop_name' => 'required|string|max:255',
        'shop_email' => 'required|string|email|max:255|unique:shops,email',
        'shop_phone' => 'nullable|string',
        'shop_address' => 'nullable|string',
        'shop_city' => 'nullable|string',
        'shop_state' => 'nullable|string',
        'shop_country' => 'nullable|string',
        'store_type' => 'required|in:supermarket,pharmacy',
        'admin_name' => 'required|string|max:255',
        'admin_email' => 'required|string|email|max:255|unique:users,email',
        'admin_password' => 'required|string|min:6',
        'admin_phone' => 'nullable|string',
        'admin_role' => 'nullable|string|in:admin,cashier,sales_person,accountant',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    // Step 1: Create the shop
    $shop = Shop::create([
        'name' => $request->shop_name,
        'slug' => Str::slug($request->shop_name) . '-' . Str::random(6),
        'email' => $request->shop_email,
        'phone' => $request->shop_phone,
        'address' => $request->shop_address,
        'city' => $request->shop_city,
        'state' => $request->shop_state,
        'country' => $request->shop_country,
        'store_type' => $request->store_type, // Add store_type
        'status' => 'active',
    ]);

    // Step 2: Create the shop admin user
    $user = User::create([
        'name' => $request->admin_name,
        'email' => $request->admin_email,
        'password' => Hash::make($request->admin_password),
        'phone' => $request->admin_phone,
        'role' => $request->admin_role ?? 'admin',
        'shop_id' => $shop->id,
        'status' => 'active',
    ]);

    // Create token for the new user
    $token = $user->createToken('pos-token')->plainTextToken;

    // Notify the platform owner that a new shop registered. Non-fatal if it fails.
    try {
        $ownerEmail = env('OWNER_NOTIFICATION_EMAIL');
        if ($ownerEmail) {
            \Illuminate\Support\Facades\Mail::to($ownerEmail)
                ->send(new \App\Mail\NewShopRegistered($shop, $user));
        }
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::warning('Owner notification failed', [
            'error' => $e->getMessage(),
            'shop_id' => $shop->id,
        ]);
    }

    return response()->json([
        'message' => 'Shop and admin user created successfully',
        'shop' => [
            'id' => $shop->id,
            'name' => $shop->name,
            'email' => $shop->email,
            'store_type' => $shop->store_type,
        ],
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'shop_id' => $user->shop_id,
            'shop_name' => $shop->name,
            'storeName' => $shop->name,
        ],
        'token' => $token
    ], 201);
}

    // Create Super Admin (only once, manually or via seeder)
    public function createSuperAdmin(Request $request)
    {
        // This should be protected or only used via seeder
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'super_admin',
            'shop_id' => null, // Super admin has no shop
            'status' => 'active',
        ]);

        $token = $user->createToken('pos-token')->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'token' => $token
        ], 201);
    }

    // Add user to existing shop (for shop owners to add staff)
   // Add user to existing shop (for shop owners to add staff)
public function addUserToShop(Request $request)
{
    $currentUser = $request->user();
    $shop = Shop::find($currentUser->shop_id);
    
    $allowedRoles = ['admin', 'cashier', 'sales_person', 'accountant'];
    $roleRule = 'required|string|in:' . implode(',', $allowedRoles);
    
    // If user is shop admin, use their shop_id automatically
    if ($currentUser->role !== 'super_admin') {
        $request->merge(['shop_id' => $currentUser->shop_id]);
    }
    
    $validator = Validator::make($request->all(), [
        'shop_id' => 'required|uuid|exists:shops,id',
        'branch_id' => 'nullable|uuid|exists:branches,id',
        'name' => 'required|string|max:255',
        'email' => 'required|string|email|max:255|unique:users,email',
        'password' => 'required|string|min:6',
        'role' => $roleRule,
        'phone' => 'nullable|string',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $shop = Shop::findOrFail($request->shop_id);

    if ($currentUser->role !== 'super_admin' && $currentUser->shop_id !== $request->shop_id) {
        return response()->json(['message' => 'Unauthorized to add users to this shop'], 403);
    }

    // Plan limit (super_admin bypasses)
    if ($currentUser->role !== 'super_admin') {
        $limits = app(\App\Services\SubscriptionLimits::class);
        if (!$limits->canCreate($request->shop_id, 'users')) {
            $limit = $limits->limitFor($request->shop_id, 'users');
            return response()->json([
                'success' => false,
                'code' => 'PLAN_LIMIT_REACHED',
                'resource' => 'users',
                'limit' => $limit,
                'message' => "Your plan allows up to {$limit} user(s). Upgrade to add more.",
            ], 402);
        }
    }

    if ($request->filled('branch_id')) {
        $branch = \App\Models\Branch::find($request->branch_id);
        if (!$branch || $branch->shop_id !== $request->shop_id) {
            return response()->json(['message' => 'Branch does not belong to this shop'], 422);
        }
    }

    $user = User::create([
        'id' => (string) Str::uuid(),
        'name' => $request->name,
        'email' => $request->email,
        'password' => Hash::make($request->password),
        'phone' => $request->phone,
        'role' => $request->role,
        'shop_id' => $request->shop_id,
        'branch_id' => $request->branch_id,
        'status' => 'active',
        'created_by' => $currentUser->id,
    ]);

    // Remove password from response
    $user->makeHidden(['password']);

    return response()->json([
        'success' => true,
        'message' => 'User added to shop successfully',
        'data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'phone' => $user->phone,
            'shop_id' => $user->shop_id,
            'shop_name' => $shop->name,
            'status' => $user->status,
            'created_at' => $user->created_at,
        ]
    ], 201);
}


    // Get all shops (Super Admin only)
    public function getAllShops(Request $request)
    {
        $user = $request->user();
        
        if ($user->role !== 'super_admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $shops = Shop::withCount('users')->paginate(20);
        return response()->json($shops);
    }

    // Get users by shop
    public function getShopUsers(Request $request, $shopId)
    {
        $currentUser = $request->user();
        $shop = Shop::findOrFail($shopId);

        // Check authorization
        if ($currentUser->role !== 'super_admin' && $currentUser->shop_id !== $shopId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $users = User::where('shop_id', $shopId)->paginate(20);
        return response()->json($users);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load(['shop', 'branch']);

        $response = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
            'branch_id' => $user->branch_id,
        ];

        if ($user->shop) {
            $response['shop_id'] = $user->shop->id;
            $response['shop_name'] = $user->shop->name;
            $response['storeName'] = $user->shop->name;
            $response['store_type'] = $user->shop->store_type;
        }

        if ($user->branch) {
            $response['branch'] = [
                'id' => $user->branch->id,
                'name' => $user->branch->name,
                'is_main' => (bool) $user->branch->is_main,
            ];
        }

        return response()->json($response);
    }

     /**
     * Get all users for the authenticated user's shop
     */
    public function getUsers(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            // Super admin can see all users across shops
            if ($currentUser->role === 'super_admin') {
                $query = User::with(['shop', 'branch']);
            } else {
                $query = User::where('shop_id', $currentUser->shop_id)->with('branch');
            }

            // Filter by role
            if ($request->has('role') && $request->role !== 'all') {
                $query->where('role', $request->role);
            }

            // Filter by status
            if ($request->has('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            if ($request->filled('branch_id') && $request->branch_id !== 'all') {
                $query->where('branch_id', $request->branch_id);
            }
            
            // Search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            }
            
            $users = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 20));
            
            return response()->json([
                'success' => true,
                'data' => $users
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch users'
            ], 500);
        }
    }
    
    /**
     * Get user statistics for the shop
     */
    public function getUserStats(Request $request)
    {
        try {
            $currentUser = $request->user();
            
            if ($currentUser->role === 'super_admin') {
                $query = User::query();
            } else {
                $query = User::where('shop_id', $currentUser->shop_id);
            }
            
            $stats = [
                'total' => $query->count(),
                'active' => (clone $query)->where('status', 'active')->count(),
                'inactive' => (clone $query)->where('status', 'inactive')->count(),
                'byRole' => [
                    'admin' => (clone $query)->where('role', 'admin')->count(),
                    'cashier' => (clone $query)->where('role', 'cashier')->count(),
                    'sales_person' => (clone $query)->where('role', 'sales_person')->count(),
                    'accountant' => (clone $query)->where('role', 'accountant')->count(),
                ]
            ];
            
            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user statistics'
            ], 500);
        }
    }
    
    /**
     * Update user details
     */
    public function updateUser(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            
            // Find user
            if ($currentUser->role === 'super_admin') {
                $user = User::findOrFail($id);
            } else {
                $user = User::where('shop_id', $currentUser->shop_id)
                    ->findOrFail($id);
            }
            
            // Prevent self-role downgrade if you're the only admin
            if ($currentUser->id === $id && $request->has('role') && $request->role !== $user->role) {
                // Check if this is the last admin
                $adminCount = User::where('shop_id', $user->shop_id)
                    ->where('role', 'admin')
                    ->where('status', 'active')
                    ->count();
                    
                if ($adminCount <= 1 && $user->role === 'admin') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot change role: You are the last admin user'
                    ], 400);
                }
            }
            
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'email' => ['sometimes', 'email', 'unique:users,email,' . $user->id],
                'password' => 'nullable|string|min:6',
                'phone' => 'nullable|string|max:50',
                'role' => 'sometimes|in:admin,cashier,sales_person,accountant',
                'status' => 'sometimes|in:active,inactive',
                'branch_id' => 'sometimes|nullable|uuid|exists:branches,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            if ($request->filled('branch_id')) {
                $branch = \App\Models\Branch::find($request->branch_id);
                if (!$branch || $branch->shop_id !== $user->shop_id) {
                    return response()->json(['message' => 'Branch does not belong to this shop'], 422);
                }
            }

            $updateData = $request->only(['name', 'email', 'phone', 'role', 'status', 'branch_id']);
            
            if ($request->filled('password')) {
                $updateData['password'] = Hash::make($request->password);
            }
            
            $user->update($updateData);
            
            // Remove password from response
            $user->makeHidden(['password']);
            
            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => $user
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Delete user
     */
    public function deleteUser(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            
            // Prevent self-deletion
            if ($currentUser->id === $id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot delete your own account'
                ], 400);
            }
            
            // Find user
            if ($currentUser->role === 'super_admin') {
                $user = User::findOrFail($id);
            } else {
                $user = User::where('shop_id', $currentUser->shop_id)
                    ->findOrFail($id);
            }
            
            // Check if this is the last admin
            if ($user->role === 'admin') {
                $adminCount = User::where('shop_id', $user->shop_id)
                    ->where('role', 'admin')
                    ->where('status', 'active')
                    ->count();
                    
                if ($adminCount <= 1) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot delete the last admin user'
                    ], 400);
                }
            }
            
            $user->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete user'
            ], 500);
        }
    }
    
    /**
     * Update user status (activate/deactivate)
     */
    public function updateUserStatus(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:active,inactive'
            ]);
            
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
            
            $currentUser = $request->user();
            
            // Prevent self-deactivation
            if ($currentUser->id === $id && $request->status === 'inactive') {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot deactivate your own account'
                ], 400);
            }
            
            // Find user
            if ($currentUser->role === 'super_admin') {
                $user = User::findOrFail($id);
            } else {
                $user = User::where('shop_id', $currentUser->shop_id)
                    ->findOrFail($id);
            }
            
            // Check if this is the last admin being deactivated
            if ($user->role === 'admin' && $request->status === 'inactive') {
                $adminCount = User::where('shop_id', $user->shop_id)
                    ->where('role', 'admin')
                    ->where('status', 'active')
                    ->count();
                    
                if ($adminCount <= 1) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot deactivate the last admin user'
                    ], 400);
                }
            }
            
            $user->status = $request->status;
            $user->save();
            
            return response()->json([
                'success' => true,
                'message' => 'User status updated successfully',
                'data' => [
                    'id' => $user->id,
                    'status' => $user->status
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user status'
            ], 500);
        }
    }
}