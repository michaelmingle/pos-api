<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Payment;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReportController extends Controller
{
    /**
     * Main dashboard report
     */
    public function dashboard(Request $request)
    {
        try {
            $user = Auth::user();
            $shopId = $user->shop_id;
            $branchId = $this->resolveBranchFilter($request, $user);
            $period = $request->get('period', 'monthly');

            $dates = $this->getDateRange($period);

            $revenueData = $this->getDashboardRevenueData($shopId, $dates, $branchId);
            $profitData = $this->getDashboardProfitData($shopId, $dates, $branchId);

            $topCustomers = Customer::where('shop_id', $shopId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->orderBy('total_spent', 'desc')
                ->limit(10)
                ->get(['id', 'name', 'total_spent', 'total_orders', 'customer_type']);

            $topProducts = DB::table('invoice_items')
                ->join('products', 'invoice_items.product_id', '=', 'products.id')
                ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
                ->whereNull('invoices.deleted_at')
                ->where('invoices.shop_id', $shopId)
                ->when($branchId, fn ($q) => $q->where('invoices.branch_id', $branchId))
                ->where('invoices.status', 'completed')
                ->select(
                    'products.name',
                    DB::raw('SUM(invoice_items.quantity) as sold'),
                    DB::raw('SUM(invoice_items.total) as revenue'),
                    'products.stock_quantity as stock'
                )
                ->groupBy('products.id', 'products.name', 'products.stock_quantity')
                ->orderBy('sold', 'desc')
                ->limit(10)
                ->get();

            $totalProducts = Product::where('shop_id', $shopId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->count();
            $lowStock = Product::where('shop_id', $shopId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->whereRaw('stock_quantity <= min_stock_level')
                ->count();
            $outOfStock = Product::where('shop_id', $shopId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->where('stock_quantity', '<=', 0)
                ->count();

            $stockData = [
                ['name' => 'In Stock', 'value' => $totalProducts > 0 ? round((($totalProducts - $lowStock - $outOfStock) / $totalProducts) * 100) : 0],
                ['name' => 'Low Stock', 'value' => $totalProducts > 0 ? round(($lowStock / $totalProducts) * 100) : 0],
                ['name' => 'Out of Stock', 'value' => $totalProducts > 0 ? round(($outOfStock / $totalProducts) * 100) : 0],
            ];

            $invFilter = fn ($q) => $q->where('shop_id', $shopId)->when($branchId, fn ($qq) => $qq->where('branch_id', $branchId));
            $invoiceSummary = [
                ['status' => 'paid', 'count' => Invoice::tap($invFilter)->where('payment_status', 'paid')->count(), 'amount' => Invoice::tap($invFilter)->where('payment_status', 'paid')->sum('total')],
                ['status' => 'pending', 'count' => Invoice::tap($invFilter)->where('payment_status', 'unpaid')->count(), 'amount' => Invoice::tap($invFilter)->where('payment_status', 'unpaid')->sum('amount_due')],
                ['status' => 'overdue', 'count' => Invoice::tap($invFilter)->where('due_date', '<', now())->where('payment_status', '!=', 'paid')->count(), 'amount' => Invoice::tap($invFilter)->where('due_date', '<', now())->where('payment_status', '!=', 'paid')->sum('amount_due')],
            ];

            $paymentBranchExists = fn ($q) => $q->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('invoices')->whereColumn('invoices.id', 'payments.invoice_id')->whereNull('invoices.deleted_at')->where('invoices.branch_id', $branchId));

            $totalRevenue = Payment::where('shop_id', $shopId)
                ->where('payment_status', 'completed')
                ->when($branchId, $paymentBranchExists)
                ->sum('amount');
            $totalExpenses = Expense::where('shop_id', $shopId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->sum('amount');
            $totalProfit = $totalRevenue - $totalExpenses;
            $totalOrders = Invoice::where('shop_id', $shopId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->where('status', 'completed')
                ->count();
            $totalCustomers = Customer::where('shop_id', $shopId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->count();
            $averageOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'revenueData' => $revenueData,
                    'profitData' => $profitData,
                    'topCustomers' => $topCustomers,
                    'products' => $topProducts,
                    'stockData' => $stockData,
                    'invoices' => $invoiceSummary,
                    'summary' => [
                        'totalRevenue' => (float) $totalRevenue,
                        'totalExpenses' => (float) $totalExpenses,
                        'totalProfit' => (float) $totalProfit,
                        'totalOrders' => $totalOrders,
                        'totalCustomers' => $totalCustomers,
                        'averageOrderValue' => round($averageOrderValue, 2),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Dashboard report error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate dashboard report',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Get dashboard revenue data (monthly)
     */
    private function getDashboardRevenueData($shopId, $dates, $branchId = null)
    {
        try {
            // Get monthly revenue and expenses
            $data = DB::table('payments')
                ->whereNull('deleted_at')
                ->where('shop_id', $shopId)
                ->where('payment_status', 'completed')
                ->when($branchId, fn ($q) => $q->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('invoices')->whereColumn('invoices.id', 'payments.invoice_id')->whereNull('invoices.deleted_at')->where('invoices.branch_id', $branchId)))
                ->whereBetween('payment_date', [$dates['start'], $dates['end']])
                ->select(
                    DB::raw('MONTH(payment_date) as month_num'),
                    DB::raw("DATE_FORMAT(payment_date, '%b') as month"),
                    DB::raw('SUM(amount) as revenue')
                )
                ->groupBy(DB::raw('MONTH(payment_date)'), DB::raw("DATE_FORMAT(payment_date, '%b')"))
                ->orderBy('month_num', 'asc')
                ->get();

            // Get monthly expenses
            $expensesData = DB::table('expenses')
                ->where('shop_id', $shopId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->whereBetween('expense_date', [$dates['start'], $dates['end']])
                ->select(
                    DB::raw('MONTH(expense_date) as month_num'),
                    DB::raw("DATE_FORMAT(expense_date, '%b') as month"),
                    DB::raw('SUM(amount) as expenses')
                )
                ->groupBy(DB::raw('MONTH(expense_date)'), DB::raw("DATE_FORMAT(expense_date, '%b')"))
                ->orderBy('month_num', 'asc')
                ->get();

            $allMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            $result = [];

            foreach ($allMonths as $index => $month) {
                $monthNum = $index + 1;
                $revenueItem = $data->firstWhere('month', $month);
                $expenseItem = $expensesData->firstWhere('month', $month);

                $result[] = [
                    'month' => $month,
                    'revenue' => $revenueItem ? (float) $revenueItem->revenue : 0,
                    'expenses' => $expenseItem ? (float) $expenseItem->expenses : 0,
                    'profit' => ($revenueItem ? (float) $revenueItem->revenue : 0) - ($expenseItem ? (float) $expenseItem->expenses : 0),
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Error getting dashboard revenue data: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get dashboard profit data (margin trend)
     */
    private function getDashboardProfitData($shopId, $dates, $branchId = null)
    {
        try {
            // Get monthly revenue
            $revenueData = DB::table('payments')
                ->whereNull('deleted_at')
                ->where('shop_id', $shopId)
                ->where('payment_status', 'completed')
                ->when($branchId, fn ($q) => $q->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('invoices')->whereColumn('invoices.id', 'payments.invoice_id')->whereNull('invoices.deleted_at')->where('invoices.branch_id', $branchId)))
                ->whereBetween('payment_date', [$dates['start'], $dates['end']])
                ->select(
                    DB::raw('MONTH(payment_date) as month_num'),
                    DB::raw("DATE_FORMAT(payment_date, '%b') as month"),
                    DB::raw('SUM(amount) as revenue')
                )
                ->groupBy(DB::raw('MONTH(payment_date)'), DB::raw("DATE_FORMAT(payment_date, '%b')"))
                ->orderBy('month_num', 'asc')
                ->get();

            // Get monthly expenses
            $expensesData = DB::table('expenses')
                ->where('shop_id', $shopId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->whereBetween('expense_date', [$dates['start'], $dates['end']])
                ->select(
                    DB::raw('MONTH(expense_date) as month_num'),
                    DB::raw("DATE_FORMAT(expense_date, '%b') as month"),
                    DB::raw('SUM(amount) as expenses')
                )
                ->groupBy(DB::raw('MONTH(expense_date)'), DB::raw("DATE_FORMAT(expense_date, '%b')"))
                ->orderBy('month_num', 'asc')
                ->get();

            $allMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            $result = [];

            foreach ($allMonths as $index => $month) {
                $monthNum = $index + 1;
                $revenueItem = $revenueData->firstWhere('month', $month);
                $expenseItem = $expensesData->firstWhere('month', $month);

                $revenue = $revenueItem ? (float) $revenueItem->revenue : 0;
                $expenses = $expenseItem ? (float) $expenseItem->expenses : 0;
                $profit = $revenue - $expenses;
                $margin = $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0;

                $result[] = [
                    'month' => $month,
                    'profit' => $profit,
                    'margin' => $margin,
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Error getting dashboard profit data: ' . $e->getMessage());
            return [];
        }
    }


    /**
     * Accountant Dashboard - Financial overview
     */
    public function accountantDashboard(Request $request)
    {
        try {
            $user = Auth::user();
            $shopId = $user->shop_id;
            $branchId = $this->resolveBranchFilter($request, $user);
            $period = $request->get('period', 'month');

            // Get date range based on period
            $dateRange = $this->getAccountantDateRange($period);

            $paymentBranch = fn ($q) => $q->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('invoices')->whereColumn('invoices.id', 'payments.invoice_id')->whereNull('invoices.deleted_at')->where('invoices.branch_id', $branchId));

            // Total revenue
            $totalRevenue = Payment::where('shop_id', $shopId)
                ->where('payment_status', 'completed')
                ->when($branchId, $paymentBranch)
                ->whereBetween('payment_date', [$dateRange['start'], $dateRange['end']])
                ->sum('amount');

            // Total expenses
            $totalExpenses = Expense::where('shop_id', $shopId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->whereBetween('expense_date', [$dateRange['start'], $dateRange['end']])
                ->sum('amount');

            $netProfit = $totalRevenue - $totalExpenses;

            $pendingInvoices = Invoice::where('shop_id', $shopId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->where('payment_status', 'unpaid')
                ->where('status', '!=', 'cancelled')
                ->sum('amount_due');

            $cashOnHand = Payment::where('shop_id', $shopId)
                ->when($branchId, $paymentBranch)
                ->where('payment_method', 'cash')
                ->where('payment_status', 'completed')
                ->whereBetween('payment_date', [now()->subDays(30), now()])
                ->sum('amount');

            $bankBalance = Payment::where('shop_id', $shopId)
                ->when($branchId, $paymentBranch)
                ->whereIn('payment_method', ['card', 'digital', 'bank_transfer'])
                ->where('payment_status', 'completed')
                ->whereBetween('payment_date', [now()->subDays(30), now()])
                ->sum('amount');

            $monthlyData = $this->getMonthlyFinancialData($shopId, $branchId);

            $previousPeriodRevenue = Payment::where('shop_id', $shopId)
                ->where('payment_status', 'completed')
                ->when($branchId, $paymentBranch)
                ->whereBetween('payment_date', [$dateRange['previous_start'], $dateRange['previous_end']])
                ->sum('amount');

            $revenueChange = $previousPeriodRevenue > 0
                ? (($totalRevenue - $previousPeriodRevenue) / $previousPeriodRevenue) * 100
                : 0;

            $previousPeriodExpenses = Expense::where('shop_id', $shopId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->whereBetween('expense_date', [$dateRange['previous_start'], $dateRange['previous_end']])
                ->sum('amount');

            $expenseChange = $previousPeriodExpenses > 0
                ? (($totalExpenses - $previousPeriodExpenses) / $previousPeriodExpenses) * 100
                : 0;

            $netMargin = $totalRevenue > 0 ? ($netProfit / $totalRevenue) * 100 : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'totalRevenue' => (float) $totalRevenue,
                    'totalExpenses' => (float) $totalExpenses,
                    'netProfit' => (float) $netProfit,
                    'pendingInvoices' => (float) $pendingInvoices,
                    'cashOnHand' => (float) $cashOnHand,
                    'bankBalance' => (float) $bankBalance,
                    'revenueChange' => round($revenueChange, 1),
                    'expenseChange' => round($expenseChange, 1),
                    'netMargin' => round($netMargin, 1),
                    'monthlyData' => $monthlyData,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Accountant dashboard error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load financial data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get date range for accountant dashboard based on period
     */
    private function getAccountantDateRange($period)
    {
        $now = now();

        switch ($period) {
            case 'week':
                return [
                    'start' => $now->copy()->startOfWeek(),
                    'end' => $now->copy()->endOfWeek(),
                    'previous_start' => $now->copy()->subWeek()->startOfWeek(),
                    'previous_end' => $now->copy()->subWeek()->endOfWeek(),
                ];
            case 'month':
                return [
                    'start' => $now->copy()->startOfMonth(),
                    'end' => $now->copy()->endOfMonth(),
                    'previous_start' => $now->copy()->subMonth()->startOfMonth(),
                    'previous_end' => $now->copy()->subMonth()->endOfMonth(),
                ];
            case 'quarter':
                return [
                    'start' => $now->copy()->startOfQuarter(),
                    'end' => $now->copy()->endOfQuarter(),
                    'previous_start' => $now->copy()->subQuarter()->startOfQuarter(),
                    'previous_end' => $now->copy()->subQuarter()->endOfQuarter(),
                ];
            case 'year':
                return [
                    'start' => $now->copy()->startOfYear(),
                    'end' => $now->copy()->endOfYear(),
                    'previous_start' => $now->copy()->subYear()->startOfYear(),
                    'previous_end' => $now->copy()->subYear()->endOfYear(),
                ];
            default:
                return [
                    'start' => $now->copy()->startOfMonth(),
                    'end' => $now->copy()->endOfMonth(),
                    'previous_start' => $now->copy()->subMonth()->startOfMonth(),
                    'previous_end' => $now->copy()->subMonth()->endOfMonth(),
                ];
        }
    }

    /**
     * Get monthly financial data for chart
     */
    private function getMonthlyFinancialData($shopId, $branchId = null)
    {
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $year = now()->year;
        $data = [];

        $paymentBranch = fn ($q) => $q->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('invoices')->whereColumn('invoices.id', 'payments.invoice_id')->whereNull('invoices.deleted_at')->where('invoices.branch_id', $branchId));

        foreach ($months as $index => $month) {
            $monthNum = $index + 1;

            $revenue = Payment::where('shop_id', $shopId)
                ->where('payment_status', 'completed')
                ->when($branchId, $paymentBranch)
                ->whereYear('payment_date', $year)
                ->whereMonth('payment_date', $monthNum)
                ->sum('amount');

            $expenses = Expense::where('shop_id', $shopId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->whereYear('expense_date', $year)
                ->whereMonth('expense_date', $monthNum)
                ->sum('amount');

            $profit = $revenue - $expenses;

            $data[] = [
                'month' => $month,
                'revenue' => (float) $revenue,
                'expenses' => (float) $expenses,
                'profit' => (float) $profit,
            ];
        }

        return $data;
    }


    /**
     * Export financial report as CSV
     */
    public function exportFinancialReport(Request $request)
    {
        try {
            $user = Auth::user();
            $shopId = $user->shop_id;
            $period = $request->get('period', 'month');

            $dateRange = $this->getAccountantDateRange($period);

            // Get data
            $totalRevenue = Payment::where('shop_id', $shopId)
                ->where('payment_status', 'completed')
                ->whereBetween('payment_date', [$dateRange['start'], $dateRange['end']])
                ->sum('amount');

            $totalExpenses = Expense::where('shop_id', $shopId)
                ->whereBetween('expense_date', [$dateRange['start'], $dateRange['end']])
                ->sum('amount');

            $monthlyData = $this->getMonthlyFinancialData($shopId);

            // Create CSV
            $csvData = [];
            $csvData[] = ['Financial Report', date('Y-m-d H:i:s')];
            $csvData[] = ['Period', $period];
            $csvData[] = [];
            $csvData[] = ['Summary', 'Amount'];
            $csvData[] = ['Total Revenue', $totalRevenue];
            $csvData[] = ['Total Expenses', $totalExpenses];
            $csvData[] = ['Net Profit', $totalRevenue - $totalExpenses];
            $csvData[] = [];
            $csvData[] = ['Monthly Breakdown', 'Revenue', 'Expenses', 'Profit'];

            foreach ($monthlyData as $month) {
                $csvData[] = [$month['month'], $month['revenue'], $month['expenses'], $month['profit']];
            }

            $filename = "financial-report-{$period}-" . date('Y-m-d') . ".csv";
            $handle = fopen('php://temp', 'w+');
            foreach ($csvData as $row) {
                fputcsv($handle, $row);
            }
            rewind($handle);
            $content = stream_get_contents($handle);
            fclose($handle);

            return response($content, 200)
                ->header('Content-Type', 'text/csv')
                ->header('Content-Disposition', "attachment; filename={$filename}");
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to export report'], 500);
        }
    }

    /**
 * Financial Report - Sales, Cost, Gross Profit, Expenses, Net Profit
 */
public function financial(Request $request)
{
    try {
        $user = Auth::user();
        $shopId = $user->shop_id;
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');
        
        // Set date range (default to last 30 days)
        if ($fromDate && $toDate) {
            $startDate = \Carbon\Carbon::parse($fromDate)->startOfDay();
            $endDate = \Carbon\Carbon::parse($toDate)->endOfDay();
        } else {
            $startDate = now()->subDays(30)->startOfDay();
            $endDate = now()->endOfDay();
        }
        
        // Today's data
        $today = now()->toDateString();
        
        // Today's Sales
        $todaySales = Payment::where('shop_id', $shopId)
            ->where('payment_status', 'completed')
            ->whereDate('payment_date', $today)
            ->sum('amount');
        
        // Today's Cost Price (NEW)
        $todayCostPrice = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->whereNull('invoices.deleted_at')
            ->join('payments', 'invoices.id', '=', 'payments.invoice_id')
            ->where('invoices.shop_id', $shopId)
            ->where('payments.payment_status', 'completed')
            ->whereDate('payments.payment_date', $today)
            ->join('products', 'invoice_items.product_id', '=', 'products.id')
            ->select(DB::raw('SUM(invoice_items.quantity * products.cost_price) as total_cost'))
            ->first();
        
        $todayCostPriceValue = $todayCostPrice->total_cost ?? 0;
        $todayGrossProfit = $todaySales - $todayCostPriceValue;
        
        // Totals for the period
        $totalSales = Payment::where('shop_id', $shopId)
            ->where('payment_status', 'completed')
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->sum('amount');
        
        // Total Cost Price
        $totalCostPrice = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->whereNull('invoices.deleted_at')
            ->join('payments', 'invoices.id', '=', 'payments.invoice_id')
            ->where('invoices.shop_id', $shopId)
            ->where('payments.payment_status', 'completed')
            ->whereBetween('payments.payment_date', [$startDate, $endDate])
            ->join('products', 'invoice_items.product_id', '=', 'products.id')
            ->select(DB::raw('SUM(invoice_items.quantity * products.cost_price) as total_cost'))
            ->first();
        
        $totalCostPriceValue = $totalCostPrice->total_cost ?? 0;
        $totalGrossProfit = $totalSales - $totalCostPriceValue;
        
        // Total Expenses
        $totalExpenses = Expense::where('shop_id', $shopId)
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->sum('amount');
        
        $totalNetProfit = $totalGrossProfit - $totalExpenses;
        
        // Profit Margins
        $grossProfitMargin = $totalSales > 0 ? ($totalGrossProfit / $totalSales) * 100 : 0;
        $netProfitMargin = $totalSales > 0 ? ($totalNetProfit / $totalSales) * 100 : 0;
        
        // Daily Sales Data (for tables)
        $dailySalesData = $this->getDailySalesData($shopId, $startDate, $endDate);
        
        // Monthly Sales Data (for charts) - Now includes expenses and net profit
        $monthlySalesData = $this->getMonthlySalesDataWithExpenses($shopId, $startDate, $endDate);
        
        // Expense Breakdown
        $expenseBreakdown = DB::table('expenses')
            ->where('shop_id', $shopId)
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->select('title as category', DB::raw('SUM(amount) as total_amount'))
            ->groupBy('title')
            ->get()
            ->map(function($item) use ($totalExpenses) {
                return [
                    'category' => $item->category,
                    'amount' => (float) $item->total_amount,
                    'percentage' => $totalExpenses > 0 ? round(($item->total_amount / $totalExpenses) * 100, 1) : 0,
                ];
            });
        
        // Product Performance
        $productPerformance = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->whereNull('invoices.deleted_at')
            ->join('payments', 'invoices.id', '=', 'payments.invoice_id')
            ->where('invoices.shop_id', $shopId)
            ->where('payments.payment_status', 'completed')
            ->whereBetween('payments.payment_date', [$startDate, $endDate])
            ->join('products', 'invoice_items.product_id', '=', 'products.id')
            ->select(
                'products.name',
                DB::raw('SUM(invoice_items.quantity) as sold'),
                'products.selling_price',
                'products.cost_price',
                DB::raw('(products.selling_price - products.cost_price) as gross_profit_per_unit'),
                DB::raw('SUM(invoice_items.quantity * products.selling_price) as revenue'),
                DB::raw('SUM(invoice_items.quantity * products.cost_price) as cost'),
                DB::raw('SUM(invoice_items.quantity * (products.selling_price - products.cost_price)) as profit')
            )
            ->groupBy('products.id', 'products.name', 'products.selling_price', 'products.cost_price')
            ->orderBy('profit', 'desc')
            ->limit(20)
            ->get()
            ->map(function($item) {
                return [
                    'name' => $item->name,
                    'sold' => (int) $item->sold,
                    'sellingPrice' => (float) $item->selling_price,
                    'costPrice' => (float) $item->cost_price,
                    'grossProfit' => (float) $item->gross_profit_per_unit,
                    'revenue' => (float) $item->revenue,
                    'cost' => (float) $item->cost,
                    'profit' => (float) $item->profit,
                ];
            });
        
        // Profit Margin Data
        $profitMarginData = $this->getProfitMarginData($shopId, $startDate, $endDate);
        
        return response()->json([
            'success' => true,
            'data' => [
                'today' => [
                    'sales' => (float) $todaySales,
                    'costPrice' => (float) $todayCostPriceValue,  // NEW
                    'grossProfit' => (float) $todayGrossProfit,
                ],
                'summary' => [
                    'totalGrossProfit' => (float) $totalGrossProfit,
                    'totalExpenses' => (float) $totalExpenses,
                    'totalNetProfit' => (float) $totalNetProfit,
                    'grossProfitMargin' => round($grossProfitMargin, 1),
                    'netProfitMargin' => round($netProfitMargin, 1),
                ],
                'salesData' => $dailySalesData,
                'monthlyData' => $monthlySalesData,
                'expenseBreakdown' => $expenseBreakdown,
                'productPerformance' => $productPerformance,
                'profitMarginData' => $profitMarginData,
            ]
        ]);
    } catch (\Exception $e) {
        Log::error('Financial report error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to generate financial report',
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * Get monthly sales data with expenses and net profit for charts
 */
private function getMonthlySalesDataWithExpenses($shopId, $startDate, $endDate)
{
    // Get monthly sales and cost data
    $salesQuery = DB::table('invoice_items')
        ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
        ->whereNull('invoices.deleted_at')
        ->join('payments', 'invoices.id', '=', 'payments.invoice_id')
        ->where('invoices.shop_id', $shopId)
        ->where('payments.payment_status', 'completed')
        ->whereBetween('payments.payment_date', [$startDate, $endDate])
        ->join('products', 'invoice_items.product_id', '=', 'products.id')
        ->select(
            DB::raw('MONTH(payments.payment_date) as month_num'),
            DB::raw("DATE_FORMAT(payments.payment_date, '%b') as month"),
            DB::raw('SUM(invoice_items.quantity * invoice_items.unit_price) as sales'),
            DB::raw('SUM(invoice_items.quantity * products.cost_price) as costPrice'),
            DB::raw('SUM(invoice_items.quantity * (invoice_items.unit_price - products.cost_price)) as grossProfit')
        )
        ->groupBy(DB::raw('MONTH(payments.payment_date)'), DB::raw("DATE_FORMAT(payments.payment_date, '%b')"))
        ->orderBy('month_num', 'asc')
        ->get();
    
    // Get monthly expenses
    $expensesQuery = DB::table('expenses')
        ->where('shop_id', $shopId)
        ->whereBetween('expense_date', [$startDate, $endDate])
        ->select(
            DB::raw('MONTH(expense_date) as month_num'),
            DB::raw("DATE_FORMAT(expense_date, '%b') as month"),
            DB::raw('SUM(amount) as expenses')
        )
        ->groupBy(DB::raw('MONTH(expense_date)'), DB::raw("DATE_FORMAT(expense_date, '%b')"))
        ->orderBy('month_num', 'asc')
        ->get();
    
    $allMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $monthlyData = [];
    
    foreach ($allMonths as $index => $month) {
        $monthNum = $index + 1;
        $salesItem = $salesQuery->firstWhere('month', $month);
        $expenseItem = $expensesQuery->firstWhere('month', $month);
        
        $sales = $salesItem ? (float) $salesItem->sales : 0;
        $costPrice = $salesItem ? (float) $salesItem->costPrice : 0;
        $grossProfit = $salesItem ? (float) $salesItem->grossProfit : 0;
        $expenses = $expenseItem ? (float) $expenseItem->expenses : 0;
        $netProfit = $grossProfit - $expenses;
        
        $monthlyData[] = [
            'month' => $month,
            'sales' => $sales,
            'costPrice' => $costPrice,
            'grossProfit' => $grossProfit,
            'expenses' => $expenses,
            'netProfit' => $netProfit,
        ];
    }
    
    return $monthlyData;
}

    /**
     * Customer Report
     */
    public function customers(Request $request)
    {
        try {
            $user = Auth::user();
            $shopId = $user->shop_id;
            $search = $request->get('search');
            $customerType = $request->get('customer_type');

            $query = Customer::where('shop_id', $shopId);

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            if ($customerType && $customerType !== 'all') {
                $query->where('customer_type', $customerType);
            }

            $customers = $query->orderBy('total_spent', 'desc')->get();

            $totalCustomers = $customers->count();
            $totalRevenue = $customers->sum('total_spent');
            $totalOrders = $customers->sum('total_orders');
            $averageOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;

            $vipCustomers = $customers->where('customer_type', 'vip')->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'customers' => $customers,
                    'vipCustomers' => $vipCustomers,
                    'summary' => [
                        'totalCustomers' => $totalCustomers,
                        'totalRevenue' => (float) $totalRevenue,
                        'totalOrders' => $totalOrders,
                        'averageOrderValue' => round($averageOrderValue, 2),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Customer report error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate customer report'
            ], 500);
        }
    }

    /**
     * Products Report
     */
    public function products(Request $request)
    {
        try {
            $user = Auth::user();
            $shopId = $user->shop_id;
            $search = $request->get('search');

            $query = Product::where('shop_id', $shopId);

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            }

            $products = $query->get();

            foreach ($products as $product) {
                $salesData = DB::table('invoice_items')
                    ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
                    ->whereNull('invoices.deleted_at')
                    ->leftJoin('payments', 'invoices.id', '=', 'payments.invoice_id')
                    ->where('invoices.shop_id', $shopId)
                    ->where('invoices.status', 'completed')
                    ->where('invoice_items.product_id', $product->id)
                    ->select(
                        DB::raw('SUM(invoice_items.quantity) as sold'),
                        DB::raw('SUM(invoice_items.total) as revenue')
                    )
                    ->first();

                $product->sold = $salesData->sold ?? 0;
                $product->revenue = $salesData->revenue ?? 0;
                $product->profit = ($product->selling_price - $product->cost_price) * ($product->sold ?? 0);
                $product->margin = $product->selling_price > 0 ? (($product->selling_price - $product->cost_price) / $product->selling_price) * 100 : 0;
            }

            $totalProducts = $products->count();
            $totalRevenue = $products->sum('revenue');
            $totalUnitsSold = $products->sum('sold');
            $totalProfit = $products->sum('profit');

            return response()->json([
                'success' => true,
                'data' => [
                    'products' => $products,
                    'summary' => [
                        'totalProducts' => $totalProducts,
                        'totalRevenue' => (float) $totalRevenue,
                        'totalUnitsSold' => (int) $totalUnitsSold,
                        'totalProfit' => (float) $totalProfit,
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Products report error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate products report'
            ], 500);
        }
    }

    /**
     * Stock Report
     */
    public function stock(Request $request)
    {
        try {
            $user = Auth::user();
            $shopId = $user->shop_id;
            $filter = $request->get('filter', 'all');
            $search = $request->get('search');

            $query = Product::where('shop_id', $shopId);

            if ($filter === 'low') {
                $query->whereRaw('stock_quantity <= min_stock_level');
            } elseif ($filter === 'critical') {
                $query->whereRaw('stock_quantity <= (min_stock_level / 2)');
            } elseif ($filter === 'out') {
                $query->where('stock_quantity', 0);
            } elseif ($filter === 'healthy') {
                $query->whereRaw('stock_quantity > min_stock_level');
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            }

            $products = $query->get();

            $totalProducts = $products->count();
            $lowStockCount = Product::where('shop_id', $shopId)->whereRaw('stock_quantity <= min_stock_level')->count();
            $criticalStockCount = Product::where('shop_id', $shopId)->whereRaw('stock_quantity <= (min_stock_level / 2)')->count();
            $outOfStockCount = Product::where('shop_id', $shopId)->where('stock_quantity', 0)->count();
            $healthyStockCount = Product::where('shop_id', $shopId)->whereRaw('stock_quantity > min_stock_level')->count();
            $totalStockValue = $products->sum(function ($product) {
                return $product->stock_quantity * $product->cost_price;
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'products' => $products,
                    'summary' => [
                        'totalProducts' => $totalProducts,
                        'lowStockCount' => $lowStockCount,
                        'criticalStockCount' => $criticalStockCount,
                        'outOfStockCount' => $outOfStockCount,
                        'healthyStockCount' => $healthyStockCount,
                        'totalStockValue' => (float) $totalStockValue,
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Stock report error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate stock report'
            ], 500);
        }
    }
    
    // ============= PRIVATE HELPER METHODS =============

    /**
     * Get date range based on period
     */
    private function getDateRange($period)
    {
        switch ($period) {
            case 'daily':
                return ['start' => now()->startOfDay(), 'end' => now()->endOfDay()];
            case 'weekly':
                return ['start' => now()->startOfWeek(), 'end' => now()->endOfWeek()];
            case 'monthly':
                return ['start' => now()->startOfMonth(), 'end' => now()->endOfMonth()];
            case 'yearly':
                return ['start' => now()->startOfYear(), 'end' => now()->endOfYear()];
            default:
                return ['start' => now()->subMonth(), 'end' => now()];
        }
    }

    /**
     * Get daily sales data for tables
     */
    private function getDailySalesData($shopId, $startDate, $endDate)
    {
        $salesQuery = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->whereNull('invoices.deleted_at')
            ->join('payments', 'invoices.id', '=', 'payments.invoice_id')
            ->where('invoices.shop_id', $shopId)
            ->where('payments.payment_status', 'completed')
            ->whereBetween('payments.payment_date', [$startDate, $endDate])
            ->join('products', 'invoice_items.product_id', '=', 'products.id')
            ->select(
                DB::raw('DATE(payments.payment_date) as date'),
                DB::raw('SUM(invoice_items.quantity * invoice_items.unit_price) as sales'),
                DB::raw('SUM(invoice_items.quantity * products.cost_price) as costPrice'),
                DB::raw('SUM(invoice_items.quantity * (invoice_items.unit_price - products.cost_price)) as grossProfit')
            )
            ->groupBy(DB::raw('DATE(payments.payment_date)'))
            ->orderBy('date', 'asc')
            ->get();

        $result = [];
        foreach ($salesQuery as $item) {
            $result[] = [
                'date' => $item->date,
                'sales' => (float) $item->sales,
                'costPrice' => (float) $item->costPrice,
                'grossProfit' => (float) $item->grossProfit,
            ];
        }

        return $result;
    }

    /**
     * Get monthly sales data for charts
     */
    private function getMonthlySalesData($shopId, $startDate, $endDate)
    {
        $salesQuery = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->whereNull('invoices.deleted_at')
            ->join('payments', 'invoices.id', '=', 'payments.invoice_id')
            ->where('invoices.shop_id', $shopId)
            ->where('payments.payment_status', 'completed')
            ->whereBetween('payments.payment_date', [$startDate, $endDate])
            ->join('products', 'invoice_items.product_id', '=', 'products.id')
            ->select(
                DB::raw('MONTH(payments.payment_date) as month_num'),
                DB::raw("DATE_FORMAT(payments.payment_date, '%b') as month"),
                DB::raw('SUM(invoice_items.quantity * invoice_items.unit_price) as sales'),
                DB::raw('SUM(invoice_items.quantity * products.cost_price) as costPrice'),
                DB::raw('SUM(invoice_items.quantity * (invoice_items.unit_price - products.cost_price)) as grossProfit')
            )
            ->groupBy(DB::raw('MONTH(payments.payment_date)'), DB::raw("DATE_FORMAT(payments.payment_date, '%b')"))
            ->orderBy('month_num', 'asc')
            ->get();

        $allMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $monthlyData = [];

        foreach ($allMonths as $month) {
            $found = $salesQuery->firstWhere('month', $month);

            if ($found) {
                $monthlyData[] = [
                    'month' => $month,
                    'sales' => (float) $found->sales,
                    'costPrice' => (float) $found->costPrice,
                    'grossProfit' => (float) $found->grossProfit,
                ];
            } else {
                $monthlyData[] = [
                    'month' => $month,
                    'sales' => 0,
                    'costPrice' => 0,
                    'grossProfit' => 0,
                ];
            }
        }

        return $monthlyData;
    }

    /**
     * Get revenue data for charts
     */
    private function getRevenueData($shopId, $dates)
    {
        $revenueQuery = Payment::where('shop_id', $shopId)
            ->where('payment_status', 'completed')
            ->whereBetween('payment_date', [$dates['start'], $dates['end']])
            ->select(
                DB::raw("DATE_FORMAT(payment_date, '%Y-%m-%d') as date"),
                DB::raw('SUM(amount) as revenue')
            )
            ->groupBy(DB::raw("DATE_FORMAT(payment_date, '%Y-%m-%d')"))
            ->orderBy('date', 'asc')
            ->get();

        $expenseQuery = Expense::where('shop_id', $shopId)
            ->whereBetween('expense_date', [$dates['start'], $dates['end']])
            ->select(
                DB::raw("DATE_FORMAT(expense_date, '%Y-%m-%d') as date"),
                DB::raw('SUM(amount) as expenses')
            )
            ->groupBy(DB::raw("DATE_FORMAT(expense_date, '%Y-%m-%d')"))
            ->orderBy('date', 'asc')
            ->get();

        $dates_range = [];
        $current = clone $dates['start'];
        while ($current <= $dates['end']) {
            $dateKey = $current->format('Y-m-d');
            $dates_range[$dateKey] = ['date' => $dateKey, 'revenue' => 0, 'expenses' => 0];
            $current->modify('+1 day');
        }

        foreach ($revenueQuery as $rev) {
            if (isset($dates_range[$rev->date])) {
                $dates_range[$rev->date]['revenue'] = $rev->revenue;
            }
        }

        foreach ($expenseQuery as $exp) {
            if (isset($dates_range[$exp->date])) {
                $dates_range[$exp->date]['expenses'] = $exp->expenses;
            }
        }

        $revenue = [];
        $profit = [];
        foreach ($dates_range as $key => $data) {
            $revenue[] = [
                'month' => date('M', strtotime($data['date'])),
                'revenue' => (float) $data['revenue'],
                'expenses' => (float) $data['expenses'],
            ];

            $profitAmount = $data['revenue'] - $data['expenses'];
            $profit[] = [
                'month' => date('M', strtotime($data['date'])),
                'profit' => (float) $profitAmount,
                'margin' => $data['revenue'] > 0 ? round(($profitAmount / $data['revenue']) * 100, 1) : 0,
            ];
        }

        return ['revenue' => $revenue, 'profit' => $profit];
    }

    /**
     * Get profit margin data for chart
     */
    private function getProfitMarginData($shopId, $startDate, $endDate)
    {
        $data = DB::table('payments')
            ->whereNull('deleted_at')
            ->where('shop_id', $shopId)
            ->where('payment_status', 'completed')
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->select(
                DB::raw('MONTH(payment_date) as month_num'),
                DB::raw("DATE_FORMAT(payment_date, '%b') as month"),
                DB::raw('SUM(amount) as total_sales')
            )
            ->groupBy(DB::raw('MONTH(payment_date)'), DB::raw("DATE_FORMAT(payment_date, '%b')"))
            ->orderBy('month_num', 'asc')
            ->get();

        $allMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $result = [];

        foreach ($allMonths as $index => $month) {
            $monthNum = $index + 1;
            $found = $data->firstWhere('month', $month);

            if ($found) {
                $costPrice = DB::table('invoice_items')
                    ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
                    ->whereNull('invoices.deleted_at')
                    ->join('payments', 'invoices.id', '=', 'payments.invoice_id')
                    ->where('invoices.shop_id', $shopId)
                    ->where('payments.payment_status', 'completed')
                    ->whereMonth('payments.payment_date', $monthNum)
                    ->join('products', 'invoice_items.product_id', '=', 'products.id')
                    ->select(DB::raw('SUM(invoice_items.quantity * products.cost_price) as total_cost'))
                    ->first();

                $grossProfit = $found->total_sales - ($costPrice->total_cost ?? 0);
                $grossMargin = $found->total_sales > 0 ? ($grossProfit / $found->total_sales) * 100 : 0;

                $expenses = Expense::where('shop_id', $shopId)
                    ->whereMonth('expense_date', $monthNum)
                    ->sum('amount');

                $netProfit = $grossProfit - $expenses;
                $netMargin = $found->total_sales > 0 ? ($netProfit / $found->total_sales) * 100 : 0;

                $result[] = [
                    'month' => $month,
                    'grossMargin' => round($grossMargin, 1),
                    'netMargin' => round($netMargin, 1),
                ];
            } else {
                $result[] = [
                    'month' => $month,
                    'grossMargin' => 0,
                    'netMargin' => 0,
                ];
            }
        }

        return $result;
    }


    /**
 * Main Dashboard - Admin Dashboard
 */
public function adminDashboard(Request $request)
{
    try {
        $user = Auth::user();
        $shopId = $user->shop_id;
        
        // Get today's date
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        
        // Today's Revenue
        $todayRevenue = Payment::where('shop_id', $shopId)
            ->where('payment_status', 'completed')
            ->whereDate('payment_date', $today)
            ->sum('amount');
        
        // Yesterday's Revenue for comparison
        $yesterdayRevenue = Payment::where('shop_id', $shopId)
            ->where('payment_status', 'completed')
            ->whereDate('payment_date', $yesterday)
            ->sum('amount');
        
        $revenueChange = $yesterdayRevenue > 0 
            ? (($todayRevenue - $yesterdayRevenue) / $yesterdayRevenue) * 100 
            : 0;
        
        // Today's Transactions
        $todayTransactions = Invoice::where('shop_id', $shopId)
            ->whereDate('created_at', $today)
            ->count();
        
        $yesterdayTransactions = Invoice::where('shop_id', $shopId)
            ->whereDate('created_at', $yesterday)
            ->count();
        
        $transactionsChange = $yesterdayTransactions > 0 
            ? (($todayTransactions - $yesterdayTransactions) / $yesterdayTransactions) * 100 
            : 0;
        
        // Today's Items Sold
        $todayItemsSold = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->whereNull('invoices.deleted_at')
            ->where('invoices.shop_id', $shopId)
            ->whereDate('invoices.created_at', $today)
            ->sum('invoice_items.quantity');
        
        $yesterdayItemsSold = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->whereNull('invoices.deleted_at')
            ->where('invoices.shop_id', $shopId)
            ->whereDate('invoices.created_at', $yesterday)
            ->sum('invoice_items.quantity');
        
        $itemsSoldChange = $yesterdayItemsSold > 0 
            ? (($todayItemsSold - $yesterdayItemsSold) / $yesterdayItemsSold) * 100 
            : 0;
        
        // Active Customers (customers with orders in last 30 days)
        $activeCustomers = Customer::where('shop_id', $shopId)
            ->whereHas('invoices', function($q) {
                $q->where('created_at', '>=', now()->subDays(30));
            })
            ->count();
        
        $previousActiveCustomers = Customer::where('shop_id', $shopId)
            ->whereHas('invoices', function($q) {
                $q->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)]);
            })
            ->count();
        
        $customersChange = $previousActiveCustomers > 0 
            ? (($activeCustomers - $previousActiveCustomers) / $previousActiveCustomers) * 100 
            : 0;
        
        // Hourly Sales Data
        $hourlyData = $this->getHourlySalesData($shopId, $today);
        
        // Weekly Revenue Data
        $weeklyData = $this->getWeeklyRevenueData($shopId);
        
        // Top Products (Today)
        $topProducts = $this->getTopProductsData($shopId, $today);
        
        // Low Stock Alerts
        $lowStock = $this->getLowStockAlerts($shopId);
        
        return response()->json([
            'success' => true,
            'data' => [
                'stats' => [
                    'todayRevenue' => (float) $todayRevenue,
                    'transactions' => (int) $todayTransactions,
                    'itemsSold' => (int) $todayItemsSold,
                    'activeCustomers' => (int) $activeCustomers,
                    'revenueChange' => round($revenueChange, 1),
                    'transactionsChange' => round($transactionsChange, 1),
                    'itemsSoldChange' => round($itemsSoldChange, 1),
                    'customersChange' => round($customersChange, 1),
                ],
                'hourlyData' => $hourlyData,
                'weeklyData' => $weeklyData,
                'topProducts' => $topProducts,
                'lowStock' => $lowStock,
            ]
        ]);
        
    } catch (\Exception $e) {
        Log::error('Admin dashboard error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to load dashboard data',
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * Get hourly sales data for today
 */
private function getHourlySalesData($shopId, $today)
{
    $hourlyData = [];
    
    for ($hour = 8; $hour <= 20; $hour++) {
        $hourStart = "$today $hour:00:00";
        $hourEnd = "$today " . ($hour + 1) . ":00:00";
        
        $sales = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->whereNull('invoices.deleted_at')
            ->where('invoices.shop_id', $shopId)
            ->whereBetween('invoices.created_at', [$hourStart, $hourEnd])
            ->sum('invoice_items.quantity');
        
        $hourlyData[] = [
            'hour' => ($hour <= 11 ? $hour . 'AM' : ($hour == 12 ? '12PM' : ($hour - 12) . 'PM')),
            'sales' => (int) $sales,
        ];
    }
    
    return $hourlyData;
}

/**
 * Get weekly revenue data
 */
private function getWeeklyRevenueData($shopId)
{
    $weeklyData = [];
    $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    
    foreach ($days as $index => $day) {
        $date = now()->startOfWeek()->addDays($index);
        
        $revenue = Payment::where('shop_id', $shopId)
            ->where('payment_status', 'completed')
            ->whereDate('payment_date', $date)
            ->sum('amount');
        
        $weeklyData[] = [
            'day' => $day,
            'revenue' => (float) $revenue,
        ];
    }
    
    return $weeklyData;
}

/**
 * Get top products for today
 */
private function getTopProductsData($shopId, $today)
{
    $topProducts = DB::table('invoice_items')
        ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
        ->whereNull('invoices.deleted_at')
        ->join('products', 'invoice_items.product_id', '=', 'products.id')
        ->where('invoices.shop_id', $shopId)
        ->whereDate('invoices.created_at', $today)
        ->select(
            'products.name',
            DB::raw('SUM(invoice_items.quantity) as sold'),
            DB::raw('SUM(invoice_items.total) as revenue')
        )
        ->groupBy('products.id', 'products.name')
        ->orderBy('sold', 'desc')
        ->limit(5)
        ->get();
    
    return $topProducts->map(function($item) {
        return [
            'name' => $item->name,
            'sold' => (int) $item->sold,
            'revenue' => '$' . number_format($item->revenue, 2),
        ];
    });
}

/**
 * Get low stock alerts
 */
private function getLowStockAlerts($shopId)
{
    $lowStock = Product::where('shop_id', $shopId)
        ->whereRaw('stock_quantity <= min_stock_level')
        ->where('status', 'active')
        ->orderByRaw('stock_quantity / min_stock_level ASC')
        ->limit(5)
        ->get(['name', 'stock_quantity as stock', 'sku']);

    return $lowStock;
}

/**
 * Resolve which branch_id to filter by. Returns null when the caller wants shop-wide data.
 * Cashiers and sales-persons assigned to a branch are server-pinned to it.
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
