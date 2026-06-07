<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Traits\SMSServiceTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendSMSJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SMSServiceTrait;

    protected $type;
    protected $data;
    protected $shopId;
    protected $customerId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $type, array $data, $shopId, $customerId = null)
    {
        $this->type = $type; // 'order_confirmation', 'payment_confirmation', 'order_ready'
        $this->data = $data;
        $this->shopId = $shopId;
        $this->customerId = $customerId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $customer = null;
            if ($this->customerId) {
                $customer = Customer::find($this->customerId);
            }

            if (!$customer || !$customer->phone) {
                Log::warning('Cannot send SMS: No customer or phone number', [
                    'type' => $this->type,
                    'customer_id' => $this->customerId
                ]);
                return;
            }

            $message = $this->buildMessage();

            if ($message) {
                $this->sendSMSWithResponse(
                    [$customer->phone],
                    $message,
                    $this->shopId,
                    $customer->id
                );
            }
        } catch (\Exception $e) {
            Log::error('SMS Job failed: ' . $e->getMessage(), [
                'type' => $this->type,
                'customer_id' => $this->customerId
            ]);
        }
    }

    /**
     * Build SMS message based on type
     */
    private function buildMessage(): ?string
    {
        $shop = \App\Models\Shop::find($this->shopId);
        $shopName = $shop ? $shop->name : 'Our Store';

        switch ($this->type) {
            case 'order_confirmation':
                return $this->buildOrderConfirmationMessage($shopName);

            case 'payment_confirmation':
                return $this->buildPaymentConfirmationMessage($shopName);
                
            default:
                return null;
        }
    }

    /**
     * Build order confirmation message
     */
    private function buildOrderConfirmationMessage($shopName): string
    {
        $invoice = Invoice::find($this->data['invoice_id']);
        if (!$invoice) {
            return '';
        }

        $itemCount = count($invoice->items);
        $itemsList = '';
        foreach ($invoice->items->take(3) as $item) {
            $itemsList .= "• {$item->quantity}x {$item->product_name}\n";
        }
        if (count($invoice->items) > 3) {
            $itemsList .= "• +" . (count($invoice->items) - 3) . " more items\n";
        }

        $message = "{$shopName}\n";
        $message .= "Order #{$invoice->invoice_number}\n";
        $message .= "✓ Order Confirmed\n\n";
        $message .= $itemsList;
        $message .= "\nTotal: GHS " . number_format($invoice->total, 2);
        
        if ($invoice->payment_status === 'paid') {
            $message .= "\nStatus: PAID ✓";
        } else {
            $message .= "\nAmount Due: GHS " . number_format($invoice->amount_due, 2);
            $message .= "\nStatus: Pending Payment";
        }
        
        $message .= "\n\nThank you for your order!";

        return $message;
    }

    /**
     * Build payment confirmation message
     */
    private function buildPaymentConfirmationMessage($shopName): string
    {
        $invoice = Invoice::find($this->data['invoice_id']);
        $payment = Payment::find($this->data['payment_id']);
        
        if (!$invoice || !$payment) {
            return '';
        }

        $message = "{$shopName}\n";
        $message .= "✓ Payment Received\n";
        $message .= "Order #{$invoice->invoice_number}\n";
        $message .= "Amount: GHS " . number_format($payment->amount, 2);
        $message .= "\nMethod: " . strtoupper($payment->payment_method);
        $message .= "\nPaid: GHS " . number_format($invoice->amount_paid, 2);
        
        if ($invoice->amount_due > 0) {
            $message .= "\nBalance: GHS " . number_format($invoice->amount_due, 2);
        } else {
            $message .= "\nStatus: FULLY PAID ✓";
        }
        
        $message .= "\n\nThank you for your payment!";

        return $message;
    }

}