<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;

/**
 * Helper for plan-based quota checks. A shop without an active subscription
 * is treated as Free (the cheapest tier) so the limits still apply.
 */
class SubscriptionLimits
{
    private const RESOURCES = ['products', 'branches', 'users'];

    public function planFor(string $shopId): ?Plan
    {
        $sub = Subscription::where('shop_id', $shopId)
            ->whereIn('status', ['active', 'past_due'])
            ->latest('current_period_end')
            ->first();

        if ($sub && $sub->plan) {
            return $sub->plan;
        }

        // Fallback so unsubscribed shops still get the Free limits.
        return Plan::where('slug', 'free')->first();
    }

    public function limitFor(string $shopId, string $resource): ?int
    {
        $plan = $this->planFor($shopId);
        if (!$plan) return null;
        return match ($resource) {
            'products' => $plan->item_limit,
            'branches' => $plan->branch_limit,
            'users' => $plan->user_limit,
            default => null,
        };
    }

    public function usageFor(string $shopId, string $resource): int
    {
        return match ($resource) {
            'products' => Product::where('shop_id', $shopId)->count(),
            'branches' => Branch::where('shop_id', $shopId)->count(),
            'users' => User::where('shop_id', $shopId)->count(),
            default => 0,
        };
    }

    public function remaining(string $shopId, string $resource): ?int
    {
        $limit = $this->limitFor($shopId, $resource);
        if ($limit === null) return null; // unlimited
        return max(0, $limit - $this->usageFor($shopId, $resource));
    }

    /**
     * True when the shop can still add one more record of $resource.
     */
    public function canCreate(string $shopId, string $resource): bool
    {
        $limit = $this->limitFor($shopId, $resource);
        if ($limit === null) return true; // unlimited
        return $this->usageFor($shopId, $resource) < $limit;
    }

    /**
     * Full snapshot for the /api/subscription/limits endpoint.
     */
    public function snapshot(string $shopId): array
    {
        $plan = $this->planFor($shopId);
        $out = [
            'plan' => $plan ? [
                'id' => $plan->id,
                'slug' => $plan->slug,
                'name' => $plan->name,
            ] : null,
            'usage' => [],
        ];

        foreach (self::RESOURCES as $resource) {
            $limit = $this->limitFor($shopId, $resource);
            $used = $this->usageFor($shopId, $resource);
            $out['usage'][$resource] = [
                'used' => $used,
                'limit' => $limit, // null = unlimited
                'remaining' => $limit === null ? null : max(0, $limit - $used),
                'percent' => $limit === null || $limit === 0 ? 0 : (int) min(100, round(($used / $limit) * 100)),
            ];
        }

        return $out;
    }
}
