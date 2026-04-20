<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\OfflineSyncQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncController extends Controller
{
    public function upload(Request $request)
    {
        $validated = $request->validate([
            'operations' => 'required|array',
            'operations.*.table' => 'required|string',
            'operations.*.operation' => 'required|in:create,update,delete',
            'operations.*.data' => 'required|array',
        ]);

        $results = [];
        
        foreach ($validated['operations'] as $operation) {
            try {
                DB::beginTransaction();
                
                $result = $this->processOperation($operation);
                $results[] = [
                    'success' => true,
                    'data' => $result
                ];
                
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $results[] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return response()->json([
            'message' => 'Sync completed',
            'results' => $results
        ]);
    }
    
    public function download(Request $request)
    {
        $validated = $request->validate([
            'last_sync' => 'nullable|date',
            'tables' => 'nullable|array'
        ]);
        
        $lastSync = $validated['last_sync'] ?? now()->subDays(30);
        $tables = $validated['tables'] ?? ['products', 'customers', 'invoices', 'categories'];
        
        $data = [];
        
        foreach ($tables as $table) {
            $model = $this->getModelForTable($table);
            if ($model && method_exists($model, 'where')) {
                $data[$table] = $model::where('shop_id', $request->user()->shop_id)
                    ->where('updated_at', '>', $lastSync)
                    ->get();
            }
        }
        
        return response()->json([
            'last_sync' => now(),
            'data' => $data
        ]);
    }
    
    public function queue(Request $request)
    {
        $validated = $request->validate([
            'table' => 'required|string',
            'operation' => 'required|in:create,update,delete',
            'data' => 'required|array',
            'device_id' => 'required|string'
        ]);
        
        $queueItem = OfflineSyncQueue::create([
            'id' => Str::uuid(),
            'shop_id' => $request->user()->shop_id,
            'user_id' => $request->user()->id,
            'device_id' => $validated['device_id'],
            'table_name' => $validated['table'],
            'operation' => $validated['operation'],
            'data' => $validated['data'],
            'status' => 'pending'
        ]);
        
        return response()->json([
            'message' => 'Operation queued for sync',
            'queue_id' => $queueItem->id
        ]);
    }
    
    public function status(Request $request)
    {
        $pending = OfflineSyncQueue::where('shop_id', $request->user()->shop_id)
            ->where('status', 'pending')
            ->count();
            
        $failed = OfflineSyncQueue::where('shop_id', $request->user()->shop_id)
            ->where('status', 'failed')
            ->count();
            
        return response()->json([
            'pending' => $pending,
            'failed' => $failed,
            'last_sync' => $request->user()->last_sync_at ?? null
        ]);
    }
    
    private function processOperation($operation)
    {
        $model = $this->getModelForTable($operation['table']);
        
        if (!$model) {
            throw new \Exception("Unknown table: {$operation['table']}");
        }
        
        switch ($operation['operation']) {
            case 'create':
                return $model::create($operation['data']);
            case 'update':
                $record = $model::findOrFail($operation['data']['id']);
                $record->update($operation['data']);
                return $record;
            case 'delete':
                $record = $model::findOrFail($operation['data']['id']);
                $record->delete();
                return ['deleted' => true];
            default:
                throw new \Exception("Unknown operation: {$operation['operation']}");
        }
    }
    
    private function getModelForTable($table)
    {
        $models = [
            'products' => \App\Models\Product::class,
            'customers' => \App\Models\Customer::class,
            'invoices' => \App\Models\Invoice::class,
            'invoice_items' => \App\Models\InvoiceItem::class,
            'payments' => \App\Models\Payment::class,
            'expenses' => \App\Models\Expense::class,
            'categories' => \App\Models\Category::class,
        ];
        
        return $models[$table] ?? null;
    }
}