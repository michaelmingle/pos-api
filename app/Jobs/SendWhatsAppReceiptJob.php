<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\Shop;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendWhatsAppReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $invoiceId,
        public string $phone,
    ) {}

    public function handle(WhatsAppService $whatsapp): void
    {
        if (!$whatsapp->isConfigured()) {
            Log::info('WhatsApp not configured; skipping receipt send', ['invoice_id' => $this->invoiceId]);
            return;
        }

        $invoice = Invoice::with(['items.product', 'shop'])->find($this->invoiceId);
        if (!$invoice) return;

        $shop = $invoice->shop ?? Shop::find($invoice->shop_id);
        $message = $this->buildMessage($invoice, $shop);

        $whatsapp->sendText($this->phone, $message);
    }

    private function buildMessage(Invoice $invoice, ?Shop $shop): string
    {
        $currency = 'GHS';
        $shopName = $shop?->name ?? 'Our Store';
        $lines = ["*{$shopName}*", "Receipt {$invoice->invoice_number}", ""];

        foreach ($invoice->items as $it) {
            $name = $it->product->name ?? $it->product_name ?? 'Item';
            $qty = (int) $it->quantity;
            $total = number_format((float) $it->total, 2);
            $lines[] = "• {$qty} × {$name} — {$currency} {$total}";
        }
        $lines[] = '';
        $lines[] = 'Subtotal: ' . $currency . ' ' . number_format((float) $invoice->subtotal, 2);
        if ((float) $invoice->tax > 0) {
            $lines[] = 'Tax:      ' . $currency . ' ' . number_format((float) $invoice->tax, 2);
        }
        if ((float) $invoice->discount > 0) {
            $lines[] = 'Discount: ' . $currency . ' ' . number_format((float) $invoice->discount, 2);
        }
        $lines[] = '*Total:    ' . $currency . ' ' . number_format((float) $invoice->total, 2) . '*';
        $lines[] = '';
        $lines[] = 'Thank you for shopping with us! 🛍️';
        return implode("\n", $lines);
    }
}
