<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Services\PaystackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SubscriptionController extends Controller
{
    public function __construct(
        private PaystackService $paystack,
        private \App\Services\SubscriptionLimits $limits,
    ) {}

    public function usage(Request $request)
    {
        $user = $request->user();
        if (!$user->shop_id) {
            return response()->json(['success' => true, 'data' => null]);
        }
        return response()->json([
            'success' => true,
            'data' => $this->limits->snapshot($user->shop_id),
        ]);
    }

    /**
     * Public list of plans (used by landing + checkout).
     */
    public function plans()
    {
        $plans = Plan::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Plan $p) => $this->serializePlan($p));

        return response()->json([
            'success' => true,
            'data' => $plans,
        ]);
    }

    /**
     * Current shop's active subscription (or the most recent record).
     */
    public function show(Request $request)
    {
        $user = $request->user();
        $shopId = $user->shop_id;
        if (!$shopId) {
            return response()->json(['success' => false, 'message' => 'No shop'], 404);
        }

        $sub = Subscription::with('plan')
            ->where('shop_id', $shopId)
            ->orderByDesc('created_at')
            ->first();

        if (!$sub) {
            return response()->json([
                'success' => true,
                'data' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $this->serializeSubscription($sub),
        ]);
    }

    /**
     * Initialize a Paystack transaction for the chosen plan + cycle.
     * Returns the authorization URL the frontend should redirect to.
     */
    public function initialize(Request $request)
    {
        $user = $request->user();
        if (!$user->shop_id) {
            return response()->json(['success' => false, 'message' => 'No shop'], 422);
        }

        $validator = Validator::make($request->all(), [
            'plan_id' => 'required|exists:plans,id',
            'billing_cycle' => 'required|in:monthly,yearly',
            'callback_url' => 'nullable|url',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $plan = Plan::findOrFail($request->plan_id);
        $cycle = $request->billing_cycle;
        $amountPesewas = $plan->priceForCycle($cycle);
        $shop = Shop::findOrFail($user->shop_id);

        // Free plans just activate without going to Paystack.
        if ($amountPesewas <= 0) {
            $sub = $this->createOrUpdateSubscription($shop, $plan, $cycle, 0, 'active', now(), $this->periodEnd($cycle));
            return response()->json([
                'success' => true,
                'free' => true,
                'data' => $this->serializeSubscription($sub->load('plan')),
            ]);
        }

        if (!$this->paystack->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'Payment provider not configured. Set PAYSTACK_SECRET_KEY in the backend.',
            ], 503);
        }

        // Pending subscription record we'll finalise on verify.
        $sub = $this->createOrUpdateSubscription($shop, $plan, $cycle, $amountPesewas, 'pending', null, null);

        try {
            $data = $this->paystack->initializeTransaction([
                'email' => $user->email,
                'amount' => $amountPesewas,
                'currency' => 'GHS',
                'channels' => ['card', 'bank', 'ussd', 'mobile_money'],
                'callback_url' => $request->input('callback_url')
                    ?: config('services.paystack.callback_url'),
                'metadata' => [
                    'subscription_id' => $sub->id,
                    'shop_id' => $shop->id,
                    'plan_id' => $plan->id,
                    'plan_slug' => $plan->slug,
                    'billing_cycle' => $cycle,
                    'custom_fields' => [
                        ['display_name' => 'Plan', 'variable_name' => 'plan', 'value' => $plan->name],
                        ['display_name' => 'Cycle', 'variable_name' => 'billing_cycle', 'value' => $cycle],
                        ['display_name' => 'Shop', 'variable_name' => 'shop', 'value' => $shop->name],
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Paystack initialize threw', ['err' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }

        SubscriptionPayment::create([
            'subscription_id' => $sub->id,
            'paystack_reference' => $data['reference'],
            'amount_pesewas' => $amountPesewas,
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'authorization_url' => $data['authorization_url'],
                'access_code' => $data['access_code'],
                'reference' => $data['reference'],
                'plan' => $this->serializePlan($plan),
                'billing_cycle' => $cycle,
                'amount_pesewas' => $amountPesewas,
                'amount' => $amountPesewas / 100,
                'currency' => 'GHS',
            ],
        ]);
    }

    /**
     * Verify a transaction (called by the callback page).
     * Returns the updated subscription on success.
     */
    public function verify(Request $request)
    {
        $user = $request->user();
        $reference = (string) $request->input('reference');
        if (!$reference) {
            return response()->json(['success' => false, 'message' => 'Missing reference'], 422);
        }

        $payment = SubscriptionPayment::where('paystack_reference', $reference)->first();
        if (!$payment) {
            return response()->json(['success' => false, 'message' => 'Unknown reference'], 404);
        }

        $sub = $payment->subscription()->with(['plan', 'shop'])->first();
        if (!$sub || $sub->shop_id !== $user->shop_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $data = $this->paystack->verifyTransaction($reference);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }

        $this->applyPaystackResult($sub, $payment, $data);

        return response()->json([
            'success' => true,
            'data' => $this->serializeSubscription($sub->refresh()->load('plan')),
        ]);
    }

    /**
     * Webhook from Paystack. We verify the signature, then upsert the payment.
     */
    public function webhook(Request $request)
    {
        $raw = $request->getContent();
        $sig = $request->header('x-paystack-signature');

        if (!$this->paystack->verifyWebhookSignature($raw, $sig)) {
            return response()->json(['success' => false], 401);
        }

        $event = json_decode($raw, true) ?: [];
        $type = $event['event'] ?? null;
        $payload = $event['data'] ?? [];
        $reference = $payload['reference'] ?? null;

        if (!$reference) {
            return response()->json(['success' => true]);
        }

        $payment = SubscriptionPayment::where('paystack_reference', $reference)->first();
        if (!$payment) {
            return response()->json(['success' => true]);
        }

        $sub = $payment->subscription()->with('plan')->first();
        if (!$sub) {
            return response()->json(['success' => true]);
        }

        if (in_array($type, ['charge.success', 'transaction.success'], true)) {
            $this->applyPaystackResult($sub, $payment, $payload);
        } elseif (in_array($type, ['charge.failed', 'transaction.failed'], true)) {
            $payment->update(['status' => 'failed', 'raw_response' => $payload]);
        }

        return response()->json(['success' => true]);
    }

    public function cancel(Request $request)
    {
        $user = $request->user();
        $sub = Subscription::where('shop_id', $user->shop_id)->whereIn('status', ['active', 'past_due'])->first();
        if (!$sub) {
            return response()->json(['success' => false, 'message' => 'No active subscription'], 404);
        }
        $sub->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);
        return response()->json([
            'success' => true,
            'message' => 'Subscription cancelled. Access continues until the end of the billing period.',
            'data' => $this->serializeSubscription($sub->load('plan')),
        ]);
    }

    private function createOrUpdateSubscription(Shop $shop, Plan $plan, string $cycle, int $amountPesewas, string $status, $start, $end): Subscription
    {
        return Subscription::updateOrCreate(
            ['shop_id' => $shop->id],
            [
                'plan_id' => $plan->id,
                'billing_cycle' => $cycle,
                'status' => $status,
                'current_period_start' => $start,
                'current_period_end' => $end,
                'amount_pesewas' => $amountPesewas,
                'currency' => 'GHS',
            ]
        );
    }

    private function applyPaystackResult(Subscription $sub, SubscriptionPayment $payment, array $data): void
    {
        $status = $data['status'] ?? null;
        if ($status !== 'success') {
            $payment->update(['status' => 'failed', 'raw_response' => $data]);
            return;
        }

        DB::transaction(function () use ($sub, $payment, $data) {
            $payment->update([
                'status' => 'success',
                'channel' => $this->mapChannel($data['channel'] ?? null),
                'paid_at' => isset($data['paid_at']) ? \Carbon\Carbon::parse($data['paid_at']) : now(),
                'raw_response' => $data,
            ]);

            $start = now();
            $end = $this->periodEnd($sub->billing_cycle, $start);

            $sub->update([
                'status' => 'active',
                'current_period_start' => $start,
                'current_period_end' => $end,
                'paystack_customer_code' => $data['customer']['customer_code'] ?? $sub->paystack_customer_code,
                'paystack_authorization_code' => $data['authorization']['authorization_code'] ?? $sub->paystack_authorization_code,
            ]);
        });
    }

    private function periodEnd(string $cycle, $start = null)
    {
        $start = $start ?: now();
        return $cycle === 'yearly' ? $start->copy()->addYear() : $start->copy()->addMonth();
    }

    private function mapChannel(?string $raw): string
    {
        return match ($raw) {
            'card' => 'card',
            'mobile_money' => 'mobile_money',
            'bank', 'bank_transfer' => 'bank',
            'ussd' => 'ussd',
            default => 'other',
        };
    }

    private function serializePlan(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'slug' => $plan->slug,
            'name' => $plan->name,
            'tagline' => $plan->tagline,
            'monthly_price_pesewas' => $plan->monthly_price_pesewas,
            'yearly_price_pesewas' => $plan->yearly_price_pesewas,
            'monthly_price' => $plan->monthly_price_pesewas / 100,
            'yearly_price' => $plan->yearly_price_pesewas / 100,
            'item_limit' => $plan->item_limit,
            'branch_limit' => $plan->branch_limit,
            'user_limit' => $plan->user_limit,
            'features' => $plan->features ?? [],
            'currency' => 'GHS',
        ];
    }

    private function serializeSubscription(Subscription $sub): array
    {
        return [
            'id' => $sub->id,
            'shop_id' => $sub->shop_id,
            'plan' => $sub->plan ? $this->serializePlan($sub->plan) : null,
            'billing_cycle' => $sub->billing_cycle,
            'status' => $sub->status,
            'current_period_start' => optional($sub->current_period_start)->toIso8601String(),
            'current_period_end' => optional($sub->current_period_end)->toIso8601String(),
            'cancelled_at' => optional($sub->cancelled_at)->toIso8601String(),
            'amount_pesewas' => $sub->amount_pesewas,
            'amount' => $sub->amount_pesewas / 100,
            'currency' => $sub->currency,
            'is_active' => $sub->isActive(),
        ];
    }
}
