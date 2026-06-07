<?php
// app/Http/Controllers/SuperAdmin/DashboardController.php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\User;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    // This one is working - keep as is
    public function getDashboard()
    {
        try {
            $stats = [
                'total_shops' => Shop::count(),
                'active_shops' => Shop::where('status', 'active')->count(),
                'total_users' => User::count(),
                'total_super_admins' => User::where('role', 'super_admin')->count(),
                'total_revenue' => (float) Invoice::where('payment_status', 'paid')->sum('total'),
                'revenue_this_month' => (float) Invoice::where('payment_status', 'paid')
                    ->whereMonth('created_at', now()->month)
                    ->sum('total'),
                'total_transactions' => Payment::count(),
                'total_invoices' => Invoice::count(),
                'monthly_revenue' => $this->getMonthlyRevenue(),
                'top_shops' => $this->getTopShops(),
                'recent_transactions' => $this->getRecentTransactions(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            Log::error('Super Admin Dashboard Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load dashboard data: ' . $e->getMessage()
            ], 500);
        }
    }

    private function getMonthlyRevenue()
    {
        try {
            $monthlyRevenue = [];
            for ($i = 11; $i >= 0; $i--) {
                $date = now()->subMonths($i);
                $revenue = Invoice::where('payment_status', 'paid')
                    ->whereYear('created_at', $date->year)
                    ->whereMonth('created_at', $date->month)
                    ->sum('total');
                    
                $monthlyRevenue[] = [
                    'month' => $date->format('M Y'),
                    'revenue' => (float) $revenue,
                ];
            }
            return $monthlyRevenue;
        } catch (\Exception $e) {
            Log::error('getMonthlyRevenue error: ' . $e->getMessage());
            return [];
        }
    }

    private function getTopShops()
    {
        try {
            $shops = Shop::withCount('invoices')
                ->withSum('invoices', 'total')
                ->orderBy('invoices_sum_total', 'desc')
                ->limit(5)
                ->get();
                
            return $shops->map(function($shop) {
                return [
                    'id' => $shop->id,
                    'name' => $shop->name,
                    'store_type' => $shop->store_type,
                    'invoices_sum_total' => (float) ($shop->invoices_sum_total ?? 0),
                    'invoices_count' => $shop->invoices_count,
                ];
            });
        } catch (\Exception $e) {
            Log::error('getTopShops error: ' . $e->getMessage());
            return collect([]);
        }
    }

    private function getRecentTransactions()
    {
        try {
            $payments = Payment::with('invoice.shop')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
                
            return $payments->map(function($payment) {
                return [
                    'id' => $payment->id,
                    'shop' => [
                        'name' => optional($payment->invoice->shop)->name ?? 'Unknown'
                    ],
                    'amount' => (float) $payment->amount,
                    'transaction_type' => 'sale',
                    'transaction_date' => $payment->created_at,
                ];
            });
        } catch (\Exception $e) {
            Log::error('getRecentTransactions error: ' . $e->getMessage());
            return collect([]);
        }
    }

    // FIXED: getShops method
    public function getShops(Request $request)
    {
        try {
            $query = Shop::query();
            
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }
            
            if ($request->has('status') && $request->status && $request->status !== 'all') {
                $query->where('status', $request->status);
            }
            
            if ($request->has('store_type') && $request->store_type && $request->store_type !== 'all') {
                $query->where('store_type', $request->store_type);
            }
            
            $shops = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 20));
            
            // Add statistics to each shop safely
            foreach ($shops as $shop) {
                try {
                    $shop->statistics = [
                        'total_sales' => (float) optional($shop->invoices())->where('payment_status', 'paid')->sum('total') ?? 0,
                        'total_invoices' => optional($shop->invoices())->count() ?? 0,
                        'total_customers' => optional($shop->customers())->count() ?? 0,
                        'total_products' => optional($shop->products())->count() ?? 0,
                        'monthly_sales' => (float) optional($shop->invoices())
                            ->whereMonth('created_at', now()->month)
                            ->where('payment_status', 'paid')
                            ->sum('total') ?? 0,
                    ];
                } catch (\Exception $e) {
                    Log::error('Error adding statistics to shop ' . $shop->id . ': ' . $e->getMessage());
                    $shop->statistics = [
                        'total_sales' => 0,
                        'total_invoices' => 0,
                        'total_customers' => 0,
                        'total_products' => 0,
                        'monthly_sales' => 0,
                    ];
                }
            }
            
            return response()->json([
                'success' => true,
                'data' => $shops
            ]);
        } catch (\Exception $e) {
            Log::error('Get Shops Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load shops: ' . $e->getMessage()
            ], 500);
        }
    }

    // FIXED: getShopDetails method
    public function getShopDetails($id)
    {
        try {
            $shop = Shop::findOrFail($id);
            
            $statistics = [
                'total_sales' => (float) optional($shop->invoices())->where('payment_status', 'paid')->sum('total') ?? 0,
                'total_invoices' => optional($shop->invoices())->count() ?? 0,
                'total_customers' => optional($shop->customers())->count() ?? 0,
                'total_products' => optional($shop->products())->count() ?? 0,
                'pending_invoices' => optional($shop->invoices())->where('payment_status', 'unpaid')->count() ?? 0,
                'monthly_sales' => (float) optional($shop->invoices())
                    ->whereMonth('created_at', now()->month)
                    ->where('payment_status', 'paid')
                    ->sum('total') ?? 0,
                'average_order_value' => (float) optional($shop->invoices())
                    ->where('payment_status', 'paid')
                    ->avg('total') ?? 0,
            ];
            
            $balanceSheet = [
                'assets' => [
                    'current_assets' => 0,
                    'fixed_assets' => 0,
                    'total_assets' => 0,
                ],
                'liabilities' => [
                    'current_liabilities' => 0,
                    'total_liabilities' => 0,
                ],
                'equity' => [
                    'total_equity' => 0,
                ],
            ];
            
            $profitLoss = [
                'income' => [
                    'sales_revenue' => $statistics['total_sales'],
                    'other_income' => 0,
                    'total_income' => $statistics['total_sales'],
                ],
                'expenses' => [
                    'cost_of_goods_sold' => 0,
                    'operating_expenses' => 0,
                    'total_expenses' => 0,
                ],
                'net_profit' => $statistics['total_sales'],
            ];
            
            $recentTransactions = [];
            try {
                $recentTransactions = Payment::whereHas('invoice', function($q) use ($id) {
                    $q->where('shop_id', $id);
                })->orderBy('created_at', 'desc')->limit(10)->get()->map(function($payment) {
                    return [
                        'id' => $payment->id,
                        'amount' => (float) $payment->amount,
                        'transaction_type' => 'sale',
                        'transaction_date' => $payment->created_at,
                    ];
                });
            } catch (\Exception $e) {
                Log::error('Error loading recent transactions: ' . $e->getMessage());
            }
            
            $paymentMethods = [];
            try {
                $paymentMethods = DB::table('payments')
                    ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')
                    ->where('invoices.shop_id', $id)
                    ->select('payments.payment_method', DB::raw('SUM(payments.amount) as total'))
                    ->groupBy('payments.payment_method')
                    ->get()
                    ->map(function($method) {
                        return [
                            'payment_method' => $method->payment_method,
                            'total' => (float) $method->total,
                        ];
                    });
            } catch (\Exception $e) {
                Log::error('Error loading payment methods: ' . $e->getMessage());
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'shop' => $shop,
                    'statistics' => $statistics,
                    'balance_sheet' => $balanceSheet,
                    'profit_loss' => $profitLoss,
                    'recent_transactions' => $recentTransactions,
                    'payment_methods' => $paymentMethods,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Get Shop Details Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load shop details: ' . $e->getMessage()
            ], 500);
        }
    }

    // FIXED: createShop method
    public function createShop(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:shops,email',
                'phone' => 'nullable|string',
                'address' => 'nullable|string',
                'city' => 'nullable|string',
                'state' => 'nullable|string',
                'country' => 'nullable|string',
                'store_type' => 'required|in:supermarket,pharmacy',
                'admin_email' => 'required|email|exists:users,email',
            ]);
            
            DB::beginTransaction();
            
            $shop = Shop::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'city' => $validated['city'] ?? null,
                'state' => $validated['state'] ?? null,
                'country' => $validated['country'] ?? 'Ghana',
                'store_type' => $validated['store_type'],
                'status' => 'active',
                'created_by' => Auth::id(),
            ]);
            
            $admin = User::where('email', $validated['admin_email'])->first();
            if ($admin) {
                $admin->shop_id = $shop->id;
                $admin->save();
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Shop created successfully',
                'data' => $shop
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Create Shop Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create shop: ' . $e->getMessage()
            ], 500);
        }
    }

    // FIXED: updateShop method
    public function updateShop(Request $request, $id)
    {
        try {
            $shop = Shop::findOrFail($id);
            
            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|email|unique:shops,email,' . $id,
                'phone' => 'nullable|string',
                'sms_sender_id' => 'sometimes|nullable|string|max:11|regex:/^[A-Za-z0-9]+$/',
                'address' => 'nullable|string',
                'city' => 'nullable|string',
                'state' => 'nullable|string',
                'country' => 'nullable|string',
                'store_type' => 'sometimes|in:supermarket,pharmacy',
                'status' => 'sometimes|in:active,inactive,suspended',
            ]);

            $shop->update($validated);
            
            return response()->json([
                'success' => true,
                'message' => 'Shop updated successfully',
                'data' => $shop
            ]);
        } catch (\Exception $e) {
            Log::error('Update Shop Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update shop: ' . $e->getMessage()
            ], 500);
        }
    }

    // FIXED: deleteShop method
    public function deleteShop($id)
    {
        try {
            $shop = Shop::findOrFail($id);
            
            $hasInvoices = optional($shop->invoices())->exists() ?? false;
            $hasProducts = optional($shop->products())->exists() ?? false;
            
            if ($hasInvoices || $hasProducts) {
                $shop->status = 'suspended';
                $shop->save();
                
                return response()->json([
                    'success' => true,
                    'message' => 'Shop has been suspended due to existing data'
                ]);
            }
            
            $shop->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Shop deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Delete Shop Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete shop: ' . $e->getMessage()
            ], 500);
        }
    }

    // FIXED: getUsers method
    public function getUsers(Request $request)
    {
        try {
            $query = User::query();
            
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }
            
            if ($request->has('role') && $request->role && $request->role !== 'all') {
                $query->where('role', $request->role);
            }
            
            $users = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 20));
            
            // Load shop relationship safely
            foreach ($users as $user) {
                try {
                    if ($user->shop_id) {
                        $user->shop = Shop::find($user->shop_id);
                    }
                } catch (\Exception $e) {
                    Log::error('Error loading shop for user: ' . $e->getMessage());
                }
            }
            
            return response()->json([
                'success' => true,
                'data' => $users
            ]);
        } catch (\Exception $e) {
            Log::error('Get Users Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load users: ' . $e->getMessage()
            ], 500);
        }
    }

    // FIXED: getConsolidatedReports method
    public function getConsolidatedReports(Request $request)
    {
        try {
            $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
            $endDate = $request->get('end_date', now()->endOfMonth()->toDateString());
            
            $shops = Shop::where('status', 'active')->get();
            
            $consolidated = [
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
                'total_revenue' => 0,
                'total_expenses' => 0,
                'net_profit' => 0,
                'shop_breakdown' => [],
                'balance_sheet' => [
                    'total_assets' => 0,
                    'total_liabilities' => 0,
                    'total_equity' => 0,
                ],
                'by_store_type' => [
                    'supermarket' => ['revenue' => 0, 'profit' => 0],
                    'pharmacy' => ['revenue' => 0, 'profit' => 0],
                ],
            ];
            
            foreach ($shops as $shop) {
                try {
                    $revenue = (float) optional($shop->invoices())
                        ->whereBetween('created_at', [$startDate, $endDate])
                        ->where('payment_status', 'paid')
                        ->sum('total') ?? 0;
                    
                    $consolidated['total_revenue'] += $revenue;
                    $consolidated['net_profit'] += $revenue;
                    
                    $type = $shop->store_type;
                    if (isset($consolidated['by_store_type'][$type])) {
                        $consolidated['by_store_type'][$type]['revenue'] += $revenue;
                        $consolidated['by_store_type'][$type]['profit'] += $revenue;
                    }
                    
                    $consolidated['shop_breakdown'][] = [
                        'shop_id' => $shop->id,
                        'shop_name' => $shop->name,
                        'store_type' => $shop->store_type,
                        'revenue' => $revenue,
                        'expenses' => 0,
                        'profit' => $revenue,
                        'margin' => $revenue > 0 ? 100 : 0,
                        'assets' => 0,
                    ];
                } catch (\Exception $e) {
                    Log::error('Error processing shop ' . $shop->id . ': ' . $e->getMessage());
                }
            }
            
            return response()->json([
                'success' => true,
                'data' => $consolidated
            ]);
        } catch (\Exception $e) {
            Log::error('Get Consolidated Reports Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load reports: ' . $e->getMessage()
            ], 500);
        }
    }

    // FIXED: getGlobalTransactions method
    public function getGlobalTransactions(Request $request)
    {
        try {
            $query = Payment::query();
            
            if ($request->has('start_date') && $request->start_date) {
                $query->whereDate('created_at', '>=', $request->start_date);
            }
            
            if ($request->has('end_date') && $request->end_date) {
                $query->whereDate('created_at', '<=', $request->end_date);
            }
            
            if ($request->has('shop_id') && $request->shop_id) {
                $query->whereHas('invoice', function($q) use ($request) {
                    $q->where('shop_id', $request->shop_id);
                });
            }
            
            $transactions = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 20));
            
            $formattedTransactions = [];
            foreach ($transactions as $payment) {
                try {
                    $shopName = 'Unknown';
                    if ($payment->invoice && $payment->invoice->shop) {
                        $shopName = $payment->invoice->shop->name;
                    }
                    
                    $formattedTransactions[] = [
                        'id' => $payment->id,
                        'shop' => ['name' => $shopName],
                        'amount' => (float) $payment->amount,
                        'transaction_type' => 'sale',
                        'reference_type' => 'payment',
                        'reference_id' => $payment->id,
                        'transaction_date' => $payment->created_at,
                    ];
                } catch (\Exception $e) {
                    Log::error('Error formatting transaction: ' . $e->getMessage());
                }
            }
            
            $totalAmount = 0;
            try {
                $totalAmount = (float) $query->sum('amount');
            } catch (\Exception $e) {
                Log::error('Error summing amounts: ' . $e->getMessage());
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'transactions' => [
                        'data' => $formattedTransactions,
                        'current_page' => $transactions->currentPage(),
                        'last_page' => $transactions->lastPage(),
                        'per_page' => $transactions->perPage(),
                        'total' => $transactions->total(),
                    ],
                    'summary' => [
                        'total_amount' => $totalAmount,
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Get Global Transactions Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load transactions: ' . $e->getMessage()
            ], 500);
        }
    }
}