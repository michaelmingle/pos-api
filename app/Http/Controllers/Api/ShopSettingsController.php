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
        // Branding
        'system_logo_url' => null,
        'receipt_logo_url' => null,
        // Receipt details — shown on every printed receipt instead of
        // whatever happens to be cached in the logged-in user's profile.
        'receipt_business_name' => null,
        'receipt_email' => null,
        'receipt_phone' => null,
        'receipt_address' => null,
        'receipt_footer' => null,
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
            'receipt_business_name' => 'sometimes|nullable|string|max:150',
            'receipt_email' => 'sometimes|nullable|email|max:150',
            'receipt_phone' => 'sometimes|nullable|string|max:50',
            'receipt_address' => 'sometimes|nullable|string|max:255',
            'receipt_footer' => 'sometimes|nullable|string|max:255',
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

    // Logo URLs are intentionally NOT accepted by update() above — they can
    // only be set via this endpoint so every logo is guaranteed to have gone
    // through validation/resizing here rather than accepting an arbitrary
    // string pointing anywhere.
    public function uploadLogo(Request $request)
    {
        $shop = $this->resolveShop($request);
        if (!$shop) {
            return response()->json(['success' => false, 'message' => 'Shop not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:system,receipt',
            'logo' => 'required|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $type = $request->input('type');
        $file = $request->file('logo');

        $dir = storage_path("app/public/shop-logos/{$shop->id}");
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = time() . '_' . $type . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $file->move($dir, $filename);

        $field = $type === 'system' ? 'system_logo_url' : 'receipt_logo_url';
        $url = url("/api/shop-logos/{$shop->id}/{$filename}");

        $current = array_merge(self::DEFAULTS, $shop->settings ?? []);
        $current[$field] = $url;
        $shop->settings = $current;
        $shop->save();

        return response()->json([
            'success' => true,
            'message' => 'Logo uploaded',
            'data' => $current,
        ]);
    }

    // Streams shop logo files directly through PHP instead of relying on the
    // public/storage symlink — same rationale as ProductController::serveImage()
    // (shared hosts commonly disable FollowSymLinks, which 403s the symlinked
    // path even when the underlying file is fine).
    public function serveLogo(string $shopId, string $filename)
    {
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $shopId)) {
            abort(404);
        }
        if (str_contains($filename, '..') || str_contains($filename, '/')) {
            abort(404);
        }

        $base = realpath(storage_path('app/public/shop-logos'));
        $path = storage_path("app/public/shop-logos/{$shopId}/{$filename}");
        $real = realpath($path);

        if (!$real || !$base || !str_starts_with($real, $base) || !is_file($real)) {
            abort(404);
        }

        return response()->file($real, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Access-Control-Allow-Origin' => '*',
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
