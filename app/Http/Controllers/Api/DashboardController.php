<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function stats(Request $request)
    {
        $user = $request->user();
        $shopId = $user->shop_id;
        $today = now()->toDateString();
        $branchId = $this->resolveBranchFilter($request, $user);

        $invoiceFilter = fn ($q) => $q->where('shop_id', $shopId)->when($branchId, fn ($qq) => $qq->where('branch_id', $branchId));

        $todayRevenue = Payment::where('shop_id', $shopId)
            ->whereDate('payment_date', $today)
            ->where('payment_status', 'completed')
            ->when($branchId, fn ($q) => $q->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('invoices')->whereColumn('invoices.id', 'payments.invoice_id')->whereNull('invoices.deleted_at')->where('invoices.branch_id', $branchId)))
            ->sum('amount');

        $todayTransactions = Invoice::tap($invoiceFilter)
            ->whereDate('created_at', $today)
            ->count();

        $totalProducts = Product::where('shop_id', $shopId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->count();

        $totalCustomers = Customer::where('shop_id', $shopId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->count();

        $yesterdayRevenue = Payment::where('shop_id', $shopId)
            ->whereDate('payment_date', now()->subDay())
            ->where('payment_status', 'completed')
            ->when($branchId, fn ($q) => $q->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('invoices')->whereColumn('invoices.id', 'payments.invoice_id')->whereNull('invoices.deleted_at')->where('invoices.branch_id', $branchId)))
            ->sum('amount');
            
        $revenueChange = $yesterdayRevenue > 0 
            ? (($todayRevenue - $yesterdayRevenue) / $yesterdayRevenue) * 100 
            : 0;
            
        return response()->json([
            'todayRevenue' => $todayRevenue,
            'transactions' => $todayTransactions,
            'itemsSold' => $totalProducts,
            'activeCustomers' => $totalCustomers,
            'revenueChange' => round($revenueChange, 1),
            'transactionsChange' => 8.1, // Simplified
            'itemsSoldChange' => 5.4, // Simplified
            'customersChange' => -2.1, // Simplified
        ]);
    }
    
    public function weeklyRevenue(Request $request)
    {
        $user = $request->user();
        $shopId = $user->shop_id;
        $branchId = $this->resolveBranchFilter($request, $user);

        $weeklyData = Invoice::where('shop_id', $shopId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('created_at', '>=', now()->subDays(7))
            ->select(
                DB::raw('DAYNAME(created_at) as day'),
                DB::raw('SUM(total) as revenue')
            )
            ->groupBy('day')
            ->orderBy(DB::raw('FIELD(day, "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday")'))
            ->get();
            
        // Map to ensure all days are present
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $result = [];
        
        foreach ($days as $day) {
            $data = $weeklyData->firstWhere('day', $day);
            $result[] = [
                'day' => substr($day, 0, 3),
                'revenue' => $data ? (float) $data->revenue : 0
            ];
        }
        
        return response()->json($result);
    }
    
    public function hourlySales(Request $request)
    {
        $user = $request->user();
        $shopId = $user->shop_id;
        $branchId = $this->resolveBranchFilter($request, $user);

        $hourlyData = Invoice::where('shop_id', $shopId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('created_at', now()->toDateString())
            ->select(
                DB::raw('HOUR(created_at) as hour'),
                DB::raw('COUNT(*) as sales')
            )
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();
            
        $hours = range(8, 20);
        $result = [];
        
        foreach ($hours as $hour) {
            $data = $hourlyData->firstWhere('hour', $hour);
            $result[] = [
                'hour' => $hour . ($hour < 12 ? 'AM' : 'PM'),
                'sales' => $data ? (int) $data->sales : 0
            ];
        }
        
        return response()->json($result);
    }
    
    public function topProducts(Request $request)
    {
        $user = $request->user();
        $shopId = $user->shop_id;
        $branchId = $this->resolveBranchFilter($request, $user);

        $topProducts = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->whereNull('invoices.deleted_at')
            ->join('products', 'invoice_items.product_id', '=', 'products.id')
            ->where('invoices.shop_id', $shopId)
            ->when($branchId, fn ($q) => $q->where('invoices.branch_id', $branchId))
            ->whereDate('invoices.created_at', now()->toDateString())
            ->select(
                'products.name',
                DB::raw('SUM(invoice_items.quantity) as sold'),
                DB::raw('SUM(invoice_items.total) as revenue')
            )
            ->groupBy('products.id', 'products.name')
            ->orderBy('sold', 'desc')
            ->limit(5)
            ->get();
            
        return response()->json($topProducts);
    }
    
    public function lowStock(Request $request)
    {
        $user = $request->user();
        $shopId = $user->shop_id;
        $branchId = $this->resolveBranchFilter($request, $user);

        $lowStockProducts = Product::where('shop_id', $shopId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereRaw('stock_quantity <= min_stock_level')
            ->where('status', 'active')
            ->select('name', 'stock_quantity as stock', 'sku')
            ->limit(10)
            ->get();

        return response()->json($lowStockProducts);
    }

    /**
     * Resolve which branch_id to filter by. Returns null if the caller wants shop-wide data.
     * Cashiers and sales-persons are pinned to their assigned branch when they have one.
     */
    private function resolveBranchFilter(Request $request, $user): ?string
    {
        if (in_array($user->role, ['cashier', 'sales_person'], true) && !empty($user->branch_id)) {
            return $user->branch_id;
        }
        $b = $request->input('branch_id');
        if (!$b || $b === 'all') {
            return null;
        }
        return (string) $b;
    }
}