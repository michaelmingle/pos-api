<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\Customer; // Add this import
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InvoiceController extends Controller
{
    /**
     * Display a listing of invoices
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            $shopId = $user->shop_id;
            
            $query = Invoice::with(['customer', 'user', 'items'])
                ->where('shop_id', $shopId);
            
            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
            
            // Filter by payment status
            if ($request->has('payment_status')) {
                $query->where('payment_status', $request->payment_status);
            }
            
            // Filter by date range
            if ($request->has('from_date')) {
                $query->whereDate('invoice_date', '>=', $request->from_date);
            }
            if ($request->has('to_date')) {
                $query->whereDate('invoice_date', '<=', $request->to_date);
            }
            
            // Search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('invoice_number', 'like', "%{$search}%")
                      ->orWhere('customer_name', 'like', "%{$search}%")
                      ->orWhereHas('customer', function($q2) use ($search) {
                          $q2->where('name', 'like', "%{$search}%")
                             ->orWhere('email', 'like', "%{$search}%")
                             ->orWhere('phone', 'like', "%{$search}%");
                      });
                });
            }
            
            $invoices = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 20));
            
            return response()->json([
                'success' => true,
                'data' => $invoices
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching invoices: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch invoices'
            ], 500);
        }
    }
    
    /**
     * Store a newly created invoice
     */
    public function store(Request $request)
    {
        try {
            Log::info('Invoice creation request:', $request->all());
            
            $validated = $request->validate([
                'customer_id' => 'nullable|exists:customers,id',
                'customer_name' => 'nullable|string|max:255',
                'customer_email' => 'nullable|email|max:255',
                'customer_phone' => 'nullable|string|max:50',
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|string|exists:products,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.unit_price' => 'required|numeric|min:0',
                'discount' => 'nullable|numeric|min:0',
                'discount_type' => 'nullable|in:fixed,percentage',
                'shipping_cost' => 'nullable|numeric|min:0',
                'notes' => 'nullable|string',
                'invoice_date' => 'nullable|date',
                'due_date' => 'nullable|date',
            ]);
            
            DB::beginTransaction();
            
            $user = Auth::user();
            $shopId = $user->shop_id;
            
            // Calculate totals
            $subtotal = 0;
            $tax = 0;
            $items = [];
            
            foreach ($validated['items'] as $item) {
                $product = Product::where('shop_id', $shopId)
                    ->find($item['product_id']);
                
                if (!$product) {
                    throw new \Exception("Product not found: {$item['product_id']}");
                }
                
                $itemTotal = $item['unit_price'] * $item['quantity'];
                $productTaxRate = $product->tax_rate ?? 0;
                $itemTax = $itemTotal * ($productTaxRate / 100);
                $itemTotalWithTax = $itemTotal + $itemTax;
                
                $subtotal += $itemTotal;
                $tax += $itemTax;
                
                $items[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => $productTaxRate,
                    'tax_amount' => $itemTax,
                    'total' => $itemTotalWithTax,
                ];
            }
            
            // Apply discount
            $discount = $validated['discount'] ?? 0;
            $discountType = $validated['discount_type'] ?? null;
            
            if ($discountType === 'percentage') {
                $discountAmount = ($subtotal * $discount) / 100;
            } else {
                $discountAmount = $discount;
            }
            
            $shippingCost = $validated['shipping_cost'] ?? 0;
            $total = $subtotal + $tax + $shippingCost - $discountAmount;
            
            // Generate invoice number
            $invoiceNumber = $this->generateInvoiceNumber($shopId);
            
            // Create customer name
            $customerName = $validated['customer_name'] ?? 'Walk-in Customer';
            
            // Create invoice
            $invoice = Invoice::create([
                'id' => (string) Str::uuid(),
                'shop_id' => $shopId,
                'customer_id' => $validated['customer_id'] ?? null,
                'user_id' => $user->id,
                'invoice_number' => $invoiceNumber,
                'type' => 'sale',
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'subtotal' => $subtotal,
                'tax' => $tax,
                'discount' => $discountAmount,
                'discount_type' => $discountType,
                'shipping_cost' => $shippingCost,
                'total' => $total,
                'amount_paid' => 0,
                'amount_due' => $total,
                'invoice_date' => $validated['invoice_date'] ?? now(),
                'due_date' => $validated['due_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $user->id,
            ]);
            
            // Create invoice items
            foreach ($items as $item) {
                InvoiceItem::create([
                    'id' => (string) Str::uuid(),
                    'invoice_id' => $invoice->id,
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'product_sku' => $item['product_sku'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => $item['tax_rate'],
                    'tax_amount' => $item['tax_amount'],
                    'total' => $item['total'],
                ]);
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Invoice created successfully',
                'data' => $invoice->load('items')
            ], 201);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Invoice creation failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create invoice: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Display the specified invoice
     */
    public function show($id)
    {
        try {
            $invoice = Invoice::with(['items', 'customer', 'user', 'payments'])
                ->where('shop_id', Auth::user()->shop_id)
                ->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $invoice
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice not found'
            ], 404);
        }
    }
    
    /**
     * Process payment for invoice
     */
    public function processPayment(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'payment_method' => 'required|in:cash,card,digital,bank_transfer,check',
                'amount' => 'required|numeric|min:0.01',
                'payment_date' => 'nullable|date',
                'reference_number' => 'nullable|string',
                'notes' => 'nullable|string',
            ]);
            
            DB::beginTransaction();
            
            $invoice = Invoice::where('shop_id', Auth::user()->shop_id)
                ->findOrFail($id);
            
            if ($invoice->payment_status === 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice is already paid'
                ], 400);
            }
            
            $amount = $validated['amount'];
            $newAmountPaid = $invoice->amount_paid + $amount;
            $paymentStatus = $newAmountPaid >= $invoice->total ? 'paid' : 'partial';
            
            // Generate payment number
            $paymentNumber = 'PAY-' . date('Ymd') . '-' . strtoupper(Str::random(6));
            
            // Create payment record
            $payment = Payment::create([
                'id' => (string) Str::uuid(),
                'shop_id' => $invoice->shop_id,
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'user_id' => Auth::id(),
                'payment_number' => $paymentNumber,
                'payment_method' => $validated['payment_method'],
                'payment_status' => 'completed',
                'amount' => $amount,
                'reference_number' => $validated['reference_number'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'payment_date' => $validated['payment_date'] ?? now(),
                'created_by' => Auth::id(),
            ]);
            
            // Update invoice
            $invoice->amount_paid = $newAmountPaid;
            $invoice->amount_due = $invoice->total - $newAmountPaid;
            $invoice->payment_status = $paymentStatus;
            
            if ($paymentStatus === 'paid') {
                $invoice->status = 'completed';
                
                // Update stock quantities
                foreach ($invoice->items as $item) {
                    $product = Product::find($item->product_id);
                    if ($product) {
                        $oldStock = $product->stock_quantity;
                        $newStock = $oldStock - $item->quantity;
                        $product->stock_quantity = max(0, $newStock);
                        $product->save();
                        
                        // Log stock movement
                        StockMovement::create([
                            'id' => (string) Str::uuid(),
                            'shop_id' => $invoice->shop_id,
                            'product_id' => $product->id,
                            'invoice_id' => $invoice->id,
                            'type' => 'out',
                            'quantity' => $item->quantity,
                            'previous_quantity' => $oldStock,
                            'new_quantity' => $product->stock_quantity,
                            'reason' => 'Sale - Invoice ' . $invoice->invoice_number,
                            'user_id' => Auth::id(),
                        ]);
                    }
                }
                
                // ========== UPDATE CUSTOMER TOTALS ==========
                if ($invoice->customer_id) {
                    $customer = Customer::find($invoice->customer_id);
                    if ($customer) {
                        // Update total spent and orders count
                        $customer->total_spent = ($customer->total_spent ?? 0) + $invoice->total;
                        $customer->total_orders = ($customer->total_orders ?? 0) + 1;
                        $customer->save();
                        
                        Log::info('Customer totals updated', [
                            'customer_id' => $customer->id,
                            'customer_name' => $customer->name,
                            'total_spent' => $customer->total_spent,
                            'total_orders' => $customer->total_orders
                        ]);
                    }
                }
            }
            
            $invoice->save();
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Payment processed successfully',
                'data' => [
                    'invoice' => $invoice,
                    'payment' => $payment
                ]
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Payment processing failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to process payment: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Cancel invoice
     */
    public function cancel($id)
    {
        try {
            DB::beginTransaction();
            
            $invoice = Invoice::where('shop_id', Auth::user()->shop_id)
                ->findOrFail($id);
            
            if ($invoice->status === 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot cancel a completed invoice'
                ], 400);
            }
            
            $invoice->status = 'cancelled';
            $invoice->save();
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Invoice cancelled successfully'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel invoice'
            ], 500);
        }
    }
    
    /**
     * Get pending invoices (for cashier)
     */
    public function pendingInvoices(Request $request)
    {
        try {
            $invoices = Invoice::with(['customer', 'items'])
                ->where('shop_id', Auth::user()->shop_id)
                ->where('status', 'pending')
                ->where('payment_status', 'unpaid')
                ->orderBy('created_at', 'asc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $invoices
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch pending invoices'
            ], 500);
        }
    }
    
    /**
     * Generate invoice number
     */
    private function generateInvoiceNumber($shopId)
    {
        $prefix = 'INV-';
        $year = date('Y');
        $month = date('m');
        
        $lastInvoice = Invoice::where('shop_id', $shopId)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('created_at', 'desc')
            ->first();
        
        if ($lastInvoice) {
            preg_match('/(\d+)$/', $lastInvoice->invoice_number, $matches);
            $lastNumber = isset($matches[1]) ? intval($matches[1]) : 0;
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }
        
        return $prefix . $year . $month . '-' . $newNumber;
    }
}