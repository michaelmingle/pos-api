<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExpenseController extends Controller
{
    /**
     * Get expense categories
     */
    public function categories(Request $request)
    {
        try {
            $user = Auth::user();
            $shopId = $user->shop_id;
            
            $categories = ExpenseCategory::where('shop_id', $shopId)
                ->where('status', 'active')
                ->orderBy('name')
                ->get();
            
            // If no categories exist, create default ones
            if ($categories->isEmpty()) {
                $defaultCategories = [
                    'Rent & Utilities', 'Salaries & Wages', 'Supplies', 'Equipment',
                    'Transportation', 'Marketing', 'Maintenance', 'Insurance',
                    'Taxes & Fees', 'Miscellaneous'
                ];
                
                foreach ($defaultCategories as $cat) {
                    ExpenseCategory::create([
                        'shop_id' => $shopId,
                        'name' => $cat,
                        'slug' => Str::slug($cat),
                        'status' => 'active',
                        'created_by' => $user->id,
                    ]);
                }
                
                $categories = ExpenseCategory::where('shop_id', $shopId)->get();
            }
            
            return response()->json([
                'success' => true,
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch categories',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of expenses
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            $shopId = $user->shop_id;
            
            $query = Expense::with(['category', 'creator', 'branch'])
                ->where('shop_id', $shopId);

            // Branch scope
            if ($request->filled('branch_id') && $request->branch_id !== 'all') {
                $query->where('branch_id', $request->branch_id);
            } elseif (in_array($user->role, ['cashier', 'sales_person'], true) && !empty($user->branch_id)) {
                $query->where('branch_id', $user->branch_id);
            }

            // Filter by category
            if ($request->has('category') && $request->category !== 'All') {
                $query->where('category_id', $request->category);
            }
            
            // Filter by date range
            if ($request->has('from_date')) {
                $query->whereDate('expense_date', '>=', $request->from_date);
            }
            if ($request->has('to_date')) {
                $query->whereDate('expense_date', '<=', $request->to_date);
            }
            
            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
            
            // Search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('reference_number', 'like', "%{$search}%");
                });
            }
            
            $expenses = $query->orderBy('expense_date', 'desc')
                ->paginate($request->get('per_page', 50));
            
            return response()->json([
                'success' => true,
                'data' => $expenses
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch expenses',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created expense
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'amount' => 'required|numeric|min:0',
                'expense_date' => 'required|date',
                'category_id' => 'required|exists:expense_categories,id',
                'payment_method' => 'required|in:cash,card,bank_transfer',
                'reference_number' => 'nullable|string',
            ]);
            
            $user = Auth::user();
            
            $expense = Expense::create([
                'id' => (string) Str::uuid(),
                'shop_id' => $user->shop_id,
                'branch_id' => $request->input('branch_id') ?? $user->branch_id,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'amount' => $validated['amount'],
                'expense_date' => $validated['expense_date'],
                'category_id' => $validated['category_id'],
                'payment_method' => $validated['payment_method'],
                'reference_number' => $validated['reference_number'] ?? null,
                'status' => 'approved',
                'created_by' => $user->id,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Expense added successfully',
                'data' => $expense->load('category')
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create expense',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified expense
     */
    public function show($id)
    {
        try {
            $expense = Expense::with(['category', 'creator', 'approver'])
                ->where('shop_id', Auth::user()->shop_id)
                ->where('id', $id)
                ->first();
            
            if (!$expense) {
                return response()->json([
                    'success' => false,
                    'message' => 'Expense not found'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'data' => $expense
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch expense',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified expense
     */
    public function update(Request $request, $id)
    {
        try {
            $expense = Expense::where('shop_id', Auth::user()->shop_id)
                ->where('id', $id)
                ->first();
            
            if (!$expense) {
                return response()->json([
                    'success' => false,
                    'message' => 'Expense not found'
                ], 404);
            }
            
            $validated = $request->validate([
                'title' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
                'amount' => 'sometimes|numeric|min:0',
                'expense_date' => 'sometimes|date',
                'category_id' => 'sometimes|exists:expense_categories,id',
                'payment_method' => 'sometimes|in:cash,card,bank_transfer',
                'reference_number' => 'nullable|string',
            ]);
            
            $expense->update($validated);
            
            return response()->json([
                'success' => true,
                'message' => 'Expense updated successfully',
                'data' => $expense->load('category')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update expense',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified expense
     */
    public function destroy($id)
    {
        try {
            $expense = Expense::where('shop_id', Auth::user()->shop_id)
                ->where('id', $id)
                ->first();
            
            if (!$expense) {
                return response()->json([
                    'success' => false,
                    'message' => 'Expense not found'
                ], 404);
            }
            
            $expense->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Expense deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete expense',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get expense summary
     */
    public function summary(Request $request)
    {
        try {
            $user = Auth::user();
            $shopId = $user->shop_id;
            
            $query = Expense::where('shop_id', $shopId);
            
            if ($request->has('from_date')) {
                $query->whereDate('expense_date', '>=', $request->from_date);
            }
            if ($request->has('to_date')) {
                $query->whereDate('expense_date', '<=', $request->to_date);
            }
            
            $totalExpenses = $query->sum('amount');
            $expenseCount = $query->count();
            
            // Category breakdown
            $categoryBreakdown = DB::table('expenses')
                ->join('expense_categories', 'expenses.category_id', '=', 'expense_categories.id')
                ->where('expenses.shop_id', $shopId)
                ->select(
                    'expense_categories.name as category',
                    DB::raw('SUM(expenses.amount) as total')
                )
                ->groupBy('expense_categories.id', 'expense_categories.name')
                ->orderBy('total', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'totalExpenses' => $totalExpenses,
                    'expenseCount' => $expenseCount,
                    'categoryBreakdown' => $categoryBreakdown
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}