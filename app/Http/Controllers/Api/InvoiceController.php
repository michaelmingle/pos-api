<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Shop;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\Customer;
use App\Jobs\SendSMSJob;
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
            
            $query = Invoice::with(['customer', 'user', 'items', 'shop', 'branch'])
                ->where('shop_id', $shopId);

            // Branch scoping: admins can pass branch_id (or 'all'); non-admins with a branch are pinned to it.
            if ($request->filled('branch_id') && $request->branch_id !== 'all') {
                $query->where('branch_id', $request->branch_id);
            } elseif (in_array($user->role, ['cashier', 'sales_person'], true) && !empty($user->branch_id)) {
                $query->where('branch_id', $user->branch_id);
            }

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
     * Store a newly created invoice (SAVE ONLY - no payment)
     * This creates an invoice with pending payment status
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        
        try {
            Log::info('Invoice creation request (SAVE ONLY):', $request->all());
            
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
                'order_type' => 'nullable|in:dine_in,takeaway,delivery',
            ]);
            
            $user = Auth::user();
            $shopId = $user->shop_id;
            $shop = Shop::find($shopId);
            
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
            
            // Create or get customer
            $customerId = $validated['customer_id'] ?? null;
            $customerPhone = $validated['customer_phone'] ?? null;
            $customerName = $validated['customer_name'] ?? 'Walk-in Customer';
            
            if (!$customerId && $customerPhone) {
                $existingCustomer = Customer::where('phone', $customerPhone)
                    ->where('shop_id', $shopId)
                    ->first();
                
                if ($existingCustomer) {
                    $customerId = $existingCustomer->id;
                    $customerName = $existingCustomer->name;
                } else {
                    $newCustomer = Customer::create([
                        'id' => (string) Str::uuid(),
                        'shop_id' => $shopId,
                        'name' => $customerName,
                        'phone' => $customerPhone,
                        'email' => $validated['customer_email'] ?? null,
                        'total_spent' => 0,
                        'total_orders' => 0,
                    ]);
                    $customerId = $newCustomer->id;
                }
            }
            
            // Create invoice with SAVE ONLY status (no payment). Retry on
            // unique-number collisions caused by concurrent inserts.
            $invoice = $this->createWithRetry(function ($number) use (
                $shopId, $user, $request, $customerId, $subtotal, $tax,
                $discountAmount, $discountType, $shippingCost, $total, $validated
            ) {
                return Invoice::create([
                    'id' => (string) Str::uuid(),
                    'shop_id' => $shopId,
                    'branch_id' => $request->input('branch_id') ?? $user->branch_id,
                    'customer_id' => $customerId,
                    'user_id' => $user->id,
                    'invoice_number' => $number,
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
                    'order_type' => $validated['order_type'] ?? 'takeaway',
                    'created_by' => $user->id,
                ]);
            }, $shopId, $invoiceNumber);
            
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
            
            // Send order confirmation SMS asynchronously (doesn't block response)
            if ($customerId && $customerPhone && env('SMS_BEARER_TOKEN')) {
                dispatch(new SendSMSJob(
                    'order_confirmation',
                    ['invoice_id' => $invoice->id],
                    $shopId,
                    $customerId
                ));
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Invoice saved successfully. Payment pending.',
                'data' => $invoice->load('items', 'shop')
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
     * Create invoice AND process payment in one atomic transaction
     * This is called when customer pays immediately
     */
    public function createWithPayment(Request $request)
    {
        DB::beginTransaction();
        
        try {
            Log::info('Invoice with payment creation request:', $request->all());
            
            $validated = $request->validate([
                'customer_id' => 'nullable|exists:customers,id',
                'customer_name' => 'nullable|string|max:255',
                'customer_email' => 'nullable|email|max:255',
                'customer_phone' => 'nullable|string|max:50',
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|string|exists:products,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.unit_price' => 'required|numeric|min:0',
                'payment_method' => 'required|in:cash,card,digital,bank_transfer,check',
                'discount' => 'nullable|numeric|min:0',
                'discount_type' => 'nullable|in:fixed,percentage',
                'shipping_cost' => 'nullable|numeric|min:0',
                'notes' => 'nullable|string',
                'invoice_date' => 'nullable|date',
                'due_date' => 'nullable|date',
                'order_type' => 'nullable|in:dine_in,takeaway,delivery',
            ]);
            
            $user = Auth::user();
            $shopId = $user->shop_id;
            $shop = Shop::find($shopId);
            
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
                
                // Check stock if product tracks inventory
                if ($product->track_stock && $product->stock_quantity < $item['quantity']) {
                    throw new \Exception("Insufficient stock for {$product->name}. Available: {$product->stock_quantity}");
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
            $amountPaid = $total; // Full payment
            
            // Generate invoice number
            $invoiceNumber = $this->generateInvoiceNumber($shopId);
            
            // Create or get customer
            $customerId = $validated['customer_id'] ?? null;
            $customerPhone = $validated['customer_phone'] ?? null;
            $customerName = $validated['customer_name'] ?? 'Walk-in Customer';
            
            if (!$customerId && $customerPhone) {
                $existingCustomer = Customer::where('phone', $customerPhone)
                    ->where('shop_id', $shopId)
                    ->first();
                
                if ($existingCustomer) {
                    $customerId = $existingCustomer->id;
                    $customerName = $existingCustomer->name;
                } else {
                    $newCustomer = Customer::create([
                        'id' => (string) Str::uuid(),
                        'shop_id' => $shopId,
                        'name' => $customerName,
                        'phone' => $customerPhone,
                        'email' => $validated['customer_email'] ?? null,
                        'total_spent' => 0,
                        'total_orders' => 0,
                    ]);
                    $customerId = $newCustomer->id;
                }
            }
            
            // Create invoice with PAID status. Retry on unique-number collisions.
            $invoice = $this->createWithRetry(function ($number) use (
                $shopId, $user, $request, $customerId, $subtotal, $tax,
                $discountAmount, $discountType, $shippingCost, $total, $amountPaid, $validated
            ) {
                return Invoice::create([
                    'id' => (string) Str::uuid(),
                    'shop_id' => $shopId,
                    'branch_id' => $request->input('branch_id') ?? $user->branch_id,
                    'customer_id' => $customerId,
                    'user_id' => $user->id,
                    'invoice_number' => $number,
                    'type' => 'sale',
                    'status' => 'completed',
                    'payment_status' => 'paid',
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'discount' => $discountAmount,
                    'discount_type' => $discountType,
                    'shipping_cost' => $shippingCost,
                    'total' => $total,
                    'amount_paid' => $amountPaid,
                    'amount_due' => 0,
                    'invoice_date' => $validated['invoice_date'] ?? now(),
                    'due_date' => $validated['due_date'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'order_type' => $validated['order_type'] ?? 'takeaway',
                    'created_by' => $user->id,
                ]);
            }, $shopId, $invoiceNumber);

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
            
            // Create payment record
            $paymentNumber = 'PAY-' . date('Ymd') . '-' . strtoupper(Str::random(6));
            
            $payment = Payment::create([
                'id' => (string) Str::uuid(),
                'shop_id' => $shopId,
                'invoice_id' => $invoice->id,
                'customer_id' => $customerId,
                'user_id' => $user->id,
                'payment_number' => $paymentNumber,
                'payment_method' => $validated['payment_method'],
                'payment_status' => 'completed',
                'amount' => $amountPaid,
                'reference_number' => $validated['reference_number'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'payment_date' => now(),
                'created_by' => $user->id,
            ]);
            
            // Update stock quantities
            foreach ($items as $item) {
                $product = Product::find($item['product_id']);
                if ($product && $product->track_stock) {
                    $oldStock = $product->stock_quantity;
                    $newStock = $oldStock - $item['quantity'];
                    $product->stock_quantity = max(0, $newStock);
                    $product->save();
                    
                    // Log stock movement
                    StockMovement::create([
                        'id' => (string) Str::uuid(),
                        'shop_id' => $shopId,
                        'product_id' => $product->id,
                        'invoice_id' => $invoice->id,
                        'type' => 'out',
                        'quantity' => $item['quantity'],
                        'previous_quantity' => $oldStock,
                        'new_quantity' => $product->stock_quantity,
                        'reason' => 'Sale - Invoice ' . $invoice->invoice_number,
                        'user_id' => $user->id,
                    ]);
                }
            }
            
            // Update customer totals
            if ($customerId) {
                $customer = Customer::find($customerId);
                if ($customer) {
                    $customer->total_spent = ($customer->total_spent ?? 0) + $total;
                    $customer->total_orders = ($customer->total_orders ?? 0) + 1;
                    $customer->save();
                }
            }
            
            DB::commit();
            
            // Send SMS asynchronously (doesn't block response)
            if ($customerId && $customerPhone && env('SMS_BEARER_TOKEN')) {
                // Send order confirmation
                dispatch(new SendSMSJob(
                    'order_confirmation',
                    ['invoice_id' => $invoice->id],
                    $shopId,
                    $customerId
                ));

                // Send payment confirmation
                dispatch(new SendSMSJob(
                    'payment_confirmation',
                    [
                        'invoice_id' => $invoice->id,
                        'payment_id' => $payment->id
                    ],
                    $shopId,
                    $customerId
                ));
            }

            // Automatically send the receipt to the customer's phone via WhatsApp.
            // Falls back to a no-op if WhatsApp creds aren't configured in .env.
            if ($customerPhone) {
                dispatch(new \App\Jobs\SendWhatsAppReceiptJob($invoice->id, $customerPhone));
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Invoice created and payment processed successfully',
                'data' => [
                    'invoice' => $invoice->load('items', 'shop'),
                    'payment' => $payment
                ]
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
            Log::error('Invoice with payment creation failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create invoice with payment: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Display the specified invoice
     */
    public function show($id)
    {
        try {
            $invoice = Invoice::with(['items', 'customer', 'user', 'payments', 'shop'])
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
     * Process payment for existing invoice
     */
    public function processPayment(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $validated = $request->validate([
                'payment_method' => 'required|in:cash,card,digital,bank_transfer,check',
                'amount' => 'required|numeric|min:0.01',
                'payment_date' => 'nullable|date',
                'reference_number' => 'nullable|string',
                'notes' => 'nullable|string',
            ]);
            
            $invoice = Invoice::where('shop_id', Auth::user()->shop_id)
                ->with('customer', 'items')
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
                
                // Update stock quantities (only if not already deducted)
                foreach ($invoice->items as $item) {
                    $product = Product::find($item->product_id);
                    if ($product && $product->track_stock) {
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
                
                // Update customer totals
                if ($invoice->customer_id) {
                    $customer = Customer::find($invoice->customer_id);
                    if ($customer) {
                        $customer->total_spent = ($customer->total_spent ?? 0) + $invoice->total;
                        $customer->total_orders = ($customer->total_orders ?? 0) + 1;
                        $customer->save();
                    }
                }
            }
            
            $invoice->save();
            
            DB::commit();
            
            // Send payment confirmation SMS asynchronously
            if ($invoice->customer && $invoice->customer->phone && env('SMS_BEARER_TOKEN')) {
                dispatch(new SendSMSJob(
                    'payment_confirmation',
                    [
                        'invoice_id' => $invoice->id,
                        'payment_id' => $payment->id
                    ],
                    $invoice->shop_id,
                    $invoice->customer_id
                ));
            }
            
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
     * Manually (re)send a receipt for an invoice via WhatsApp. Useful when the
     * automatic send misfired or the customer asks for another copy.
     */
    public function sendWhatsAppReceipt(Request $request, $id)
    {
        $user = Auth::user();
        $invoice = Invoice::where('shop_id', $user->shop_id)
            ->with(['items.product', 'shop', 'customer'])
            ->findOrFail($id);

        $phone = $request->input('phone')
            ?: $invoice->customer?->phone
            ?: $invoice->customer_phone;

        if (!$phone) {
            return response()->json(['success' => false, 'message' => 'No phone number for this invoice'], 422);
        }

        $whatsapp = app(\App\Services\WhatsAppService::class);
        $shopName = $invoice->shop->name ?? 'Our Store';
        $lines = ["*{$shopName}*", "Receipt {$invoice->invoice_number}", ""];
        foreach ($invoice->items as $it) {
            $name = $it->product->name ?? $it->product_name ?? 'Item';
            $lines[] = "• {$it->quantity} × {$name} — GHS " . number_format((float) $it->total, 2);
        }
        $lines[] = "";
        $lines[] = "*Total: GHS " . number_format((float) $invoice->total, 2) . "*";
        $message = implode("\n", $lines);

        if ($whatsapp->isConfigured()) {
            $res = $whatsapp->sendText($phone, $message);
            return response()->json([
                'success' => (bool) ($res['success'] ?? false),
                'message' => $res['success'] ? 'WhatsApp receipt sent' : ($res['reason'] ?? 'Failed to send'),
                'data' => ['phone' => $whatsapp->normalisePhone($phone)],
            ], $res['success'] ? 200 : 502);
        }

        // Cloud API not configured — fall back to a click-to-chat link the
        // cashier can open from the browser.
        $url = $whatsapp->clickToChatUrl($phone, $message);
        return response()->json([
            'success' => true,
            'fallback' => true,
            'message' => 'WhatsApp not configured. Use this link to send manually.',
            'data' => ['click_to_chat' => $url, 'phone' => $whatsapp->normalisePhone($phone)],
        ]);
    }

    /**
     * Edit safe metadata on an invoice (customer info, notes). Item editing
     * is intentionally off-limits — delete + recreate when items change so we
     * never end up with mismatched stock movements.
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        if (!in_array($user->role, ['admin', 'super_admin'], true)) {
            return response()->json(['success' => false, 'message' => 'Only admins can edit invoices'], 403);
        }

        try {
            $invoice = Invoice::where('shop_id', $user->shop_id)->findOrFail($id);

            $validated = $request->validate([
                'customer_id' => 'sometimes|nullable|exists:customers,id',
                'customer_name' => 'sometimes|nullable|string|max:255',
                'customer_email' => 'sometimes|nullable|email|max:255',
                'customer_phone' => 'sometimes|nullable|string|max:50',
                'notes' => 'sometimes|nullable|string',
                'payment_method' => 'sometimes|nullable|in:cash,card,digital,bank_transfer,check',
                'due_date' => 'sometimes|nullable|date',
            ]);

            // Whitelist the fields we actually persist on the invoice itself.
            $patch = array_intersect_key($validated, array_flip([
                'customer_id', 'notes', 'due_date',
            ]));
            $invoice->fill($patch);
            $invoice->save();

            // Sync customer info on a linked customer (if any).
            if ($invoice->customer_id) {
                $customer = Customer::find($invoice->customer_id);
                if ($customer) {
                    if (array_key_exists('customer_name', $validated) && $validated['customer_name']) {
                        $customer->name = $validated['customer_name'];
                    }
                    if (array_key_exists('customer_email', $validated)) {
                        $customer->email = $validated['customer_email'];
                    }
                    if (array_key_exists('customer_phone', $validated)) {
                        $customer->phone = $validated['customer_phone'];
                    }
                    $customer->save();
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Invoice updated',
                'data' => $invoice->fresh(['items', 'customer', 'payments', 'shop']),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('Invoice update failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to update invoice'], 500);
        }
    }

    /**
     * Soft-delete an invoice and refund stock for each item. Logs a stock
     * movement so the audit trail still shows where the inventory went.
     */
    public function destroy($id)
    {
        $user = Auth::user();
        if (!in_array($user->role, ['admin', 'super_admin'], true)) {
            return response()->json(['success' => false, 'message' => 'Only admins can delete invoices'], 403);
        }

        try {
            DB::beginTransaction();

            $invoice = Invoice::where('shop_id', $user->shop_id)
                ->with('items')
                ->findOrFail($id);

            // Refund stock for every paid/completed sale line.
            if ($invoice->status === 'completed' || $invoice->payment_status === 'paid') {
                foreach ($invoice->items as $item) {
                    $product = Product::where('shop_id', $invoice->shop_id)->find($item->product_id);
                    if (!$product) continue;
                    $oldStock = $product->stock_quantity ?? 0;
                    $product->stock_quantity = $oldStock + (int) $item->quantity;
                    $product->save();

                    StockMovement::create([
                        'id' => (string) Str::uuid(),
                        'shop_id' => $invoice->shop_id,
                        'product_id' => $product->id,
                        'type' => 'in',
                        'quantity' => (int) $item->quantity,
                        'previous_quantity' => $oldStock,
                        'new_quantity' => $product->stock_quantity,
                        'reason' => 'Refund - Invoice ' . $invoice->invoice_number . ' deleted',
                        'user_id' => $user->id,
                    ]);
                }
            }

            // Roll back customer totals so reports stay clean.
            if ($invoice->customer_id) {
                $customer = Customer::find($invoice->customer_id);
                if ($customer) {
                    $customer->total_spent = max(0, ($customer->total_spent ?? 0) - (float) $invoice->total);
                    $customer->total_orders = max(0, ($customer->total_orders ?? 0) - 1);
                    $customer->save();
                }
            }

            // Soft-delete any payments tied to this invoice so they fall out
            // of every revenue calculation across dashboard + reports.
            Payment::where('invoice_id', $invoice->id)->delete();

            $invoice->delete(); // soft-delete

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Invoice deleted; stock refunded',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Invoice delete failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to delete invoice'], 500);
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
     * Get invoice statistics
     */
    public function statistics(Request $request)
    {
        try {
            $user = Auth::user();
            $shopId = $user->shop_id;
            
            $today = now()->toDateString();
            $thisMonth = now()->startOfMonth();
            
            $stats = [
                'today' => [
                    'total_invoices' => Invoice::where('shop_id', $shopId)
                        ->whereDate('created_at', $today)
                        ->count(),
                    'total_sales' => Invoice::where('shop_id', $shopId)
                        ->whereDate('created_at', $today)
                        ->where('payment_status', 'paid')
                        ->sum('total'),
                    'pending_payment' => Invoice::where('shop_id', $shopId)
                        ->whereDate('created_at', $today)
                        ->where('payment_status', 'unpaid')
                        ->sum('total'),
                ],
                'this_month' => [
                    'total_invoices' => Invoice::where('shop_id', $shopId)
                        ->where('created_at', '>=', $thisMonth)
                        ->count(),
                    'total_sales' => Invoice::where('shop_id', $shopId)
                        ->where('created_at', '>=', $thisMonth)
                        ->where('payment_status', 'paid')
                        ->sum('total'),
                ],
                'overall' => [
                    'total_invoices' => Invoice::where('shop_id', $shopId)->count(),
                    'total_sales' => Invoice::where('shop_id', $shopId)
                        ->where('payment_status', 'paid')
                        ->sum('total'),
                    'average_order_value' => Invoice::where('shop_id', $shopId)
                        ->where('payment_status', 'paid')
                        ->avg('total'),
                ]
            ];
            
            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics'
            ], 500);
        }
    }
    
    /**
     * Try to create an invoice and, if MySQL rejects the row with a duplicate
     * key error on (shop_id, invoice_number), recompute the next number and
     * retry — up to a handful of times before giving up.
     */
    private function createWithRetry(callable $factory, string $shopId, string $startNumber): Invoice
    {
        $number = $startNumber;
        $maxAttempts = 6;
        for ($i = 0; $i < $maxAttempts; $i++) {
            try {
                return $factory($number);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // Bump the trailing -NNNN by one and try again.
                if (preg_match('/(\d{4})$/', $number, $m)) {
                    $base = substr($number, 0, -strlen($m[1]));
                    $number = $base . str_pad(intval($m[1]) + 1, 4, '0', STR_PAD_LEFT);
                } else {
                    throw $e;
                }
                // Also jump ahead of any newly-inserted concurrent number for safety.
                $number = $this->nextInvoiceNumber($shopId, 1);
            }
        }
        throw new \RuntimeException('Could not generate a unique invoice number after retries');
    }

    /**
     * Generate invoice number with store-specific code
     */
    private function generateInvoiceNumber($shopId)
    {
        $shop = Shop::find($shopId);
        $storeCode = $this->generateStoreCode($shop->name);

        $prefix = 'INV';
        $datePart = date('Ymd');
        $base = "{$prefix}-{$storeCode}-{$datePart}-";

        // Find the highest trailing number that exists for this shop+prefix today,
        // INCLUDING soft-deleted rows — the unique constraint sees them too.
        $highest = Invoice::withTrashed()
            ->where('shop_id', $shopId)
            ->where('invoice_number', 'like', $base . '%')
            ->orderByRaw('CAST(SUBSTRING_INDEX(invoice_number, "-", -1) AS UNSIGNED) DESC')
            ->value('invoice_number');

        $next = 1;
        if ($highest && preg_match('/(\d{4})$/', $highest, $m)) {
            $next = intval($m[1]) + 1;
        }
        return $base . str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Generate a unique invoice number, retrying on race-condition collisions.
     * Two requests landing in the same millisecond can compute the same number;
     * if the insert fails with the unique constraint, we ask for the next one.
     */
    private function nextInvoiceNumber(string $shopId, int $attempt = 0): string
    {
        $number = $this->generateInvoiceNumber($shopId);
        if ($attempt > 0) {
            // On retry, find a number that definitely doesn't collide.
            while (Invoice::withTrashed()
                ->where('shop_id', $shopId)
                ->where('invoice_number', $number)
                ->exists()
            ) {
                if (preg_match('/(\d{4})$/', $number, $m)) {
                    $base = substr($number, 0, -strlen($m[1]));
                    $number = $base . str_pad(intval($m[1]) + 1, 4, '0', STR_PAD_LEFT);
                } else {
                    break;
                }
            }
        }
        return $number;
    }
    
    /**
     * Generate a 2-letter store code from shop name
     */
    private function generateStoreCode($shopName)
    {
        $cleanName = preg_replace('/[^a-zA-Z0-9]/', '', $shopName);
        $cleanName = strtoupper($cleanName);
        
        if (strlen($cleanName) >= 2) {
            $code = substr($cleanName, 0, 2);
        } else {
            $code = $cleanName . 'P';
        }
        
        $code = str_pad($code, 2, 'X');
        $uniqueHash = strtoupper(substr(md5($shopName), 0, 1));
        
        return $code . $uniqueHash;
    }
}