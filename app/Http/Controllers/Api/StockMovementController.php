<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class StockMovementController extends Controller
{
    public function index(Request $request)
    {
        $query = StockMovement::with(['product', 'user'])
            ->where('shop_id', Auth::user()->shop_id);
        
        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }
        
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        
        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        
        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }
        
        $movements = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 50));
        
        return response()->json($movements);
    }
    
    public function adjustStock(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'type' => 'required|in:add,subtract,set',
            'quantity' => 'required|integer|min:0',
            'reason' => 'required|string',
        ]);
        
        $product = Product::where('shop_id', Auth::user()->shop_id)
            ->findOrFail($validated['product_id']);
        
        $oldStock = $product->stock_quantity;
        
        switch ($validated['type']) {
            case 'add':
                $newStock = $oldStock + $validated['quantity'];
                $movementType = 'in';
                $quantity = $validated['quantity'];
                break;
            case 'subtract':
                $newStock = max(0, $oldStock - $validated['quantity']);
                $movementType = 'out';
                $quantity = -$validated['quantity'];
                break;
            case 'set':
                $newStock = max(0, $validated['quantity']);
                $movementType = 'adjustment';
                $quantity = $newStock - $oldStock;
                break;
            default:
                return response()->json(['error' => 'Invalid type'], 400);
        }
        
        $product->stock_quantity = $newStock;
        $product->save();
        
        $movement = StockMovement::create([
            'id' => (string) Str::uuid(),
            'shop_id' => $product->shop_id,
            'product_id' => $product->id,
            'type' => $movementType,
            'quantity' => $quantity,
            'previous_quantity' => $oldStock,
            'new_quantity' => $newStock,
            'reason' => $validated['reason'],
            'user_id' => Auth::id(),
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Stock adjusted successfully',
            'data' => $movement->load('product', 'user')
        ]);
    }
    
    public function summary(Request $request)
    {
        $shopId = Auth::user()->shop_id;
        
        $totalIn = StockMovement::where('shop_id', $shopId)
            ->where('type', 'in')
            ->sum('quantity');
        
        $totalOut = StockMovement::where('shop_id', $shopId)
            ->where('type', 'out')
            ->sum('quantity');
        
        $totalAdjustments = StockMovement::where('shop_id', $shopId)
            ->where('type', 'adjustment')
            ->count();
        
        $netChange = $totalIn - abs($totalOut);
        
        return response()->json([
            'total_in' => abs($totalIn),
            'total_out' => abs($totalOut),
            'total_adjustments' => $totalAdjustments,
            'net_change' => $netChange,
        ]);
    }
}