<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AuditTrailController extends Controller
{
    /**
     * Get audit logs with filters
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            $isSuperAdmin = $user->role === 'super_admin';
            // Super admins may view logs across every shop (optionally filtered
            // to one shop via ?shop_id=). Everyone else stays hard-scoped to
            // their own shop — this is the only place that distinction is made.
            $scopeShopId = $isSuperAdmin
                ? ($request->filled('shop_id') && $request->shop_id !== 'all' ? $request->shop_id : null)
                : $user->shop_id;

            $baseQuery = function () use ($scopeShopId) {
                $q = AuditTrail::query();
                if ($scopeShopId) {
                    $q->where('shop_id', $scopeShopId);
                }
                return $q;
            };

            $query = $baseQuery()->with($isSuperAdmin ? ['user', 'shop'] : ['user']);

            // Filter by module
            if ($request->has('module') && $request->module !== 'all') {
                $query->where('module', $request->module);
            }

            // Filter by action
            if ($request->has('action') && $request->action !== 'all') {
                $query->where('action', $request->action);
            }

            // Filter by user
            if ($request->has('user_id') && $request->user_id !== 'all') {
                $query->where('user_id', $request->user_id);
            }

            // Date range filter
            if ($request->has('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }
            if ($request->has('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }

            // Search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('user_name', 'like', "%{$search}%")
                      ->orWhere('user_email', 'like', "%{$search}%")
                      ->orWhere('action', 'like', "%{$search}%")
                      ->orWhere('module', 'like', "%{$search}%")
                      ->orWhere('ip_address', 'like', "%{$search}%");
                });
            }

            $logs = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 50));

            // Get statistics
            $stats = [
                'total' => $baseQuery()->count(),
                'today' => $baseQuery()->whereDate('created_at', today())->count(),
                'this_week' => $baseQuery()->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
                'this_month' => $baseQuery()->whereMonth('created_at', now()->month)->count(),
                'by_module' => $baseQuery()
                    ->select('module', DB::raw('count(*) as total'))
                    ->groupBy('module')
                    ->get(),
                'by_action' => $baseQuery()
                    ->select('action', DB::raw('count(*) as total'))
                    ->groupBy('action')
                    ->get(),
            ];

            return response()->json([
                'success' => true,
                'data' => $logs,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch audit logs'
            ], 500);
        }
    }
    
    /**
     * Get audit log details
     */
    public function show($id)
    {
        try {
            $log = AuditTrail::where('shop_id', Auth::user()->shop_id)
                ->with('user')
                ->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $log
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Log not found'
            ], 404);
        }
    }
    
    /**
     * Clear old logs
     */
    public function clear(Request $request)
    {
        try {
            $days = $request->get('days', 30);
            $deleted = AuditTrail::where('shop_id', Auth::user()->shop_id)
                ->where('created_at', '<', now()->subDays($days))
                ->delete();
            
            return response()->json([
                'success' => true,
                'message' => "Deleted {$deleted} old logs"
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear logs'
            ], 500);
        }
    }
    
    /**
     * Export audit logs
     */
    public function export(Request $request)
    {
        try {
            $user = Auth::user();
            $shopId = $user->shop_id;
            
            $query = AuditTrail::where('shop_id', $shopId);
            
            // Apply filters
            if ($request->has('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }
            if ($request->has('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }
            if ($request->has('module') && $request->module !== 'all') {
                $query->where('module', $request->module);
            }
            if ($request->has('action') && $request->action !== 'all') {
                $query->where('action', $request->action);
            }
            
            $logs = $query->orderBy('created_at', 'desc')->get();
            
            $csvData = [
                ['ID', 'User', 'Email', 'Role', 'Action', 'Module', 'Record ID', 'IP Address', 'Device', 'Date', 'Time']
            ];
            
            foreach ($logs as $log) {
                $csvData[] = [
                    $log->id,
                    $log->user_name,
                    $log->user_email,
                    $log->user_role,
                    $log->action,
                    $log->module,
                    $log->record_id,
                    $log->ip_address,
                    $log->device,
                    $log->created_at->format('Y-m-d'),
                    $log->created_at->format('H:i:s'),
                ];
            }
            
            $csvContent = implode("\n", array_map(function($row) {
                return '"' . implode('","', $row) . '"';
            }, $csvData));
            
            return response($csvContent, 200)
                ->header('Content-Type', 'text/csv')
                ->header('Content-Disposition', "attachment; filename=audit-logs-{$shopId}-" . date('Y-m-d') . ".csv");
                
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to export logs'], 500);
        }
    }
}