<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    /**
     * Display a listing of customers
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            $shopId = $user->shop_id;
            
            $query = Customer::where('shop_id', $shopId);
            
            // Filter by customer type
            if ($request->has('customer_type') && $request->customer_type !== 'all') {
                $query->where('customer_type', $request->customer_type);
            }
            
            // Search
            if ($request->has('search')) {
                $query->search($request->search);
            }
            
            // Sorting
            $sortField = $request->get('sort_by', 'name');
            $sortDirection = $request->get('sort_direction', 'asc');
            $query->orderBy($sortField, $sortDirection);
            
            $customers = $query->paginate($request->get('per_page', 20));
            
            return response()->json([
                'success' => true,
                'data' => $customers
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching customers: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch customers'
            ], 500);
        }
    }
    
    /**
     * Store a newly created customer
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'nullable|email|unique:customers,email',
                'phone' => 'nullable|string|max:50',
                'address' => 'nullable|string',
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:100',
                'country' => 'nullable|string|max:100',
                'zip_code' => 'nullable|string|max:20',
                'birth_date' => 'nullable|date',
                'customer_type' => 'required|in:regular,vip,wholesale',
                'credit_limit' => 'nullable|numeric|min:0',
            ]);
            
            $validated['id'] = (string) Str::uuid();
            $validated['shop_id'] = Auth::user()->shop_id;
            $validated['created_by'] = Auth::id();
            $validated['credit_limit'] = $validated['credit_limit'] ?? 0;
            $validated['total_spent'] = 0;
            $validated['total_orders'] = 0;
            $validated['current_balance'] = 0;
            
            $customer = Customer::create($validated);
            
            return response()->json([
                'success' => true,
                'message' => 'Customer created successfully',
                'data' => $customer
            ], 201);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating customer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create customer: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Display the specified customer
     */
    public function show($id)
    {
        try {
            $customer = Customer::where('shop_id', Auth::user()->shop_id)
                ->with(['invoices' => function($q) {
                    $q->latest()->limit(10);
                }])
                ->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $customer
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found'
            ], 404);
        }
    }
    
    /**
     * Update the specified customer
     */
    public function update(Request $request, $id)
    {
        try {
            $customer = Customer::where('shop_id', Auth::user()->shop_id)
                ->findOrFail($id);
            
            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => ['nullable', 'email', Rule::unique('customers')->ignore($customer->id)],
                'phone' => 'nullable|string|max:50',
                'address' => 'nullable|string',
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:100',
                'country' => 'nullable|string|max:100',
                'zip_code' => 'nullable|string|max:20',
                'birth_date' => 'nullable|date',
                'customer_type' => 'sometimes|in:regular,vip,wholesale',
                'credit_limit' => 'nullable|numeric|min:0',
            ]);
            
            $customer->update($validated);
            
            return response()->json([
                'success' => true,
                'message' => 'Customer updated successfully',
                'data' => $customer
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating customer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update customer'
            ], 500);
        }
    }
    
    /**
     * Remove the specified customer
     */
    public function destroy($id)
    {
        try {
            $customer = Customer::where('shop_id', Auth::user()->shop_id)
                ->findOrFail($id);
            
            // Check if customer has invoices
            if ($customer->invoices()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete customer with existing invoices'
                ], 400);
            }
            
            $customer->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Customer deleted successfully'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error deleting customer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete customer'
            ], 500);
        }
    }
    
    /**
     * Get customer invoices
     */
    public function invoices($id)
    {
        try {
            $customer = Customer::where('shop_id', Auth::user()->shop_id)
                ->findOrFail($id);
            
            $invoices = $customer->invoices()
                ->with('items')
                ->orderBy('created_at', 'desc')
                ->paginate(20);
            
            return response()->json([
                'success' => true,
                'data' => $invoices
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch customer invoices'
            ], 500);
        }
    }
    
    /**
     * Get customer statistics
     */
    public function stats($id)
    {
        try {
            $customer = Customer::where('shop_id', Auth::user()->shop_id)
                ->findOrFail($id);
            
            $stats = [
                'total_orders' => $customer->total_orders,
                'total_spent' => $customer->total_spent,
                'average_order_value' => $customer->total_orders > 0 
                    ? $customer->total_spent / $customer->total_orders 
                    : 0,
                'last_order_date' => $customer->invoices()
                    ->where('status', 'completed')
                    ->latest()
                    ->first()
                    ?->created_at,
            ];
            
            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch customer stats'
            ], 500);
        }
    }
}