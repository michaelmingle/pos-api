<?php
// app/Http/Controllers/SuperAdmin/SubscriptionsController.php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SubscriptionsController extends Controller
{
    /**
     * List every shop's subscription (one row per shop, including shops that
     * have never subscribed — those show a null subscription).
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 20);

        $shops = Shop::with(['subscription.plan'])
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->whereHas('subscription', fn ($sq) => $sq->where('status', $request->status));
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($sq) use ($search) {
                    $sq->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate($perPage);

        $shops->getCollection()->transform(fn (Shop $shop) => $this->serializeShopSubscription($shop));

        return response()->json([
            'success' => true,
            'data' => $shops,
        ]);
    }

    /**
     * Directly set a shop's plan/status/period, bypassing Paystack. Used to
     * manually grant, extend, downgrade, or reactivate a subscription.
     */
    public function update(Request $request, string $shopId)
    {
        $shop = Shop::find($shopId);
        if (!$shop) {
            return response()->json(['success' => false, 'message' => 'Shop not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'plan_id' => 'required|exists:plans,id',
            'billing_cycle' => 'required|in:monthly,yearly',
            'status' => 'sometimes|in:active,pending,past_due,cancelled,expired',
            'period_days' => 'sometimes|integer|min:1|max:3650',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $plan = Plan::findOrFail($request->plan_id);
        $status = $request->get('status', 'active');
        $start = now();
        $end = $request->filled('period_days')
            ? $start->copy()->addDays((int) $request->period_days)
            : ($request->billing_cycle === 'yearly' ? $start->copy()->addYear() : $start->copy()->addMonth());

        $sub = Subscription::updateOrCreate(
            ['shop_id' => $shop->id],
            [
                'plan_id' => $plan->id,
                'billing_cycle' => $request->billing_cycle,
                'status' => $status,
                'current_period_start' => $start,
                'current_period_end' => $end,
                'amount_pesewas' => $plan->priceForCycle($request->billing_cycle),
                'currency' => 'GHS',
                'cancelled_at' => $status === 'cancelled' ? now() : null,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Subscription updated',
            'data' => $this->serializeShopSubscription($shop->fresh(['subscription.plan'])),
        ]);
    }

    /**
     * Force-cancel a shop's active/past-due subscription.
     */
    public function cancel(string $shopId)
    {
        $shop = Shop::find($shopId);
        if (!$shop) {
            return response()->json(['success' => false, 'message' => 'Shop not found'], 404);
        }

        $sub = Subscription::where('shop_id', $shop->id)
            ->whereIn('status', ['active', 'past_due', 'pending'])
            ->first();

        if (!$sub) {
            return response()->json(['success' => false, 'message' => 'No active subscription for this shop'], 404);
        }

        $sub->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Subscription cancelled',
            'data' => $this->serializeShopSubscription($shop->fresh(['subscription.plan'])),
        ]);
    }

    private function serializeShopSubscription(Shop $shop): array
    {
        $sub = $shop->subscription;

        return [
            'shop_id' => $shop->id,
            'shop_name' => $shop->name,
            'shop_email' => $shop->email,
            'shop_status' => $shop->status,
            'subscription' => $sub ? [
                'id' => $sub->id,
                'plan' => $sub->plan ? [
                    'id' => $sub->plan->id,
                    'slug' => $sub->plan->slug,
                    'name' => $sub->plan->name,
                ] : null,
                'billing_cycle' => $sub->billing_cycle,
                'status' => $sub->status,
                'current_period_start' => optional($sub->current_period_start)->toIso8601String(),
                'current_period_end' => optional($sub->current_period_end)->toIso8601String(),
                'cancelled_at' => optional($sub->cancelled_at)->toIso8601String(),
                'amount' => $sub->amount_pesewas / 100,
                'currency' => $sub->currency,
                'is_active' => $sub->isActive(),
            ] : null,
        ];
    }
}
