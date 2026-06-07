<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ShopSettingsController extends Controller
{
    public const DEFAULTS = [
        'expiry_warning_days' => 30,
        'low_stock_threshold' => 5,
        'damaged_tracking_enabled' => true,
        'cross_branch_admin_view' => true,
        'currency' => 'GHS',
        'currency_symbol' => 'GHS',
    ];

    public function show(Request $request)
    {
        $shop = $this->resolveShop($request);
        if (!$shop) {
            return response()->json(['success' => false, 'message' => 'Shop not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => array_merge(self::DEFAULTS, $shop->settings ?? []),
        ]);
    }

    public function update(Request $request)
    {
        $shop = $this->resolveShop($request);
        if (!$shop) {
            return response()->json(['success' => false, 'message' => 'Shop not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'expiry_warning_days' => 'sometimes|integer|min:1|max:365',
            'low_stock_threshold' => 'sometimes|integer|min:0|max:1000',
            'damaged_tracking_enabled' => 'sometimes|boolean',
            'cross_branch_admin_view' => 'sometimes|boolean',
            'currency' => 'sometimes|string|max:8',
            'currency_symbol' => 'sometimes|string|max:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $current = array_merge(self::DEFAULTS, $shop->settings ?? []);
        $merged = array_merge($current, $validator->validated());

        $shop->settings = $merged;
        $shop->save();

        return response()->json([
            'success' => true,
            'message' => 'Settings updated',
            'data' => $merged,
        ]);
    }

    private function resolveShop(Request $request): ?Shop
    {
        $user = Auth::user();
        if ($user->role === 'super_admin' && $request->filled('shop_id')) {
            return Shop::find($request->shop_id);
        }
        return $user->shop_id ? Shop::find($user->shop_id) : null;
    }
}
