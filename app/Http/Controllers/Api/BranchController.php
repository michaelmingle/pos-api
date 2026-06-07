<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Branch::query()->with('manager:id,name,email');

        if ($user->role !== 'super_admin') {
            $query->where('shop_id', $user->shop_id);
        } elseif ($request->filled('shop_id')) {
            $query->where('shop_id', $request->shop_id);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->get('per_page', 25);
        $branches = $query->orderByDesc('is_main')->orderBy('name')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $branches,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $shopId = $user->role === 'super_admin'
            ? $request->input('shop_id')
            : $user->shop_id;

        if (!$shopId) {
            return response()->json([
                'success' => false,
                'message' => 'Shop is required to create a branch.',
            ], 422);
        }

        // Plan limit (super_admin bypasses)
        if ($user->role !== 'super_admin') {
            $limits = app(\App\Services\SubscriptionLimits::class);
            if (!$limits->canCreate($shopId, 'branches')) {
                $limit = $limits->limitFor($shopId, 'branches');
                return response()->json([
                    'success' => false,
                    'code' => 'PLAN_LIMIT_REACHED',
                    'resource' => 'branches',
                    'limit' => $limit,
                    'message' => "Your plan allows up to {$limit} branch(es). Upgrade to add more.",
                ], 402);
            }
        }

        $request->merge(['shop_id' => $shopId]);

        $validator = Validator::make($request->all(), [
            'shop_id' => 'required|uuid|exists:shops,id',
            'name' => 'required|string|max:255',
            'code' => "nullable|string|max:32|unique:branches,code,NULL,id,shop_id,{$shopId}",
            'phone' => 'nullable|string|max:32',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:120',
            'state' => 'nullable|string|max:120',
            'country' => 'nullable|string|max:120',
            'manager_id' => 'nullable|uuid|exists:users,id',
            'is_main' => 'sometimes|boolean',
            'status' => 'sometimes|in:active,inactive,suspended',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $isMain = (bool) $request->boolean('is_main');
            if ($isMain) {
                Branch::where('shop_id', $shopId)->update(['is_main' => false]);
            }

            $branch = Branch::create([
                'shop_id' => $shopId,
                'name' => $request->name,
                'code' => $request->code,
                'phone' => $request->phone,
                'email' => $request->email,
                'address' => $request->address,
                'city' => $request->city,
                'state' => $request->state,
                'country' => $request->country,
                'manager_id' => $request->manager_id,
                'is_main' => $isMain,
                'status' => $request->status ?? 'active',
                'created_by' => $user->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Branch created successfully',
                'data' => $branch->load('manager:id,name,email'),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Create branch failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create branch',
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $branch = $this->findScoped($request, $id);

        return response()->json([
            'success' => true,
            'data' => $branch->load('manager:id,name,email'),
        ]);
    }

    public function update(Request $request, $id)
    {
        $branch = $this->findScoped($request, $id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'code' => "sometimes|nullable|string|max:32|unique:branches,code,{$branch->id},id,shop_id,{$branch->shop_id}",
            'phone' => 'sometimes|nullable|string|max:32',
            'email' => 'sometimes|nullable|email|max:255',
            'address' => 'sometimes|nullable|string',
            'city' => 'sometimes|nullable|string|max:120',
            'state' => 'sometimes|nullable|string|max:120',
            'country' => 'sometimes|nullable|string|max:120',
            'manager_id' => 'sometimes|nullable|uuid|exists:users,id',
            'is_main' => 'sometimes|boolean',
            'status' => 'sometimes|in:active,inactive,suspended',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            if ($request->boolean('is_main')) {
                Branch::where('shop_id', $branch->shop_id)
                    ->where('id', '!=', $branch->id)
                    ->update(['is_main' => false]);
            }

            $branch->update($validator->validated());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Branch updated successfully',
                'data' => $branch->fresh()->load('manager:id,name,email'),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Update branch failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update branch',
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $branch = $this->findScoped($request, $id);

        if ($branch->is_main) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete the main branch. Promote another branch first.',
            ], 422);
        }

        $branch->delete();

        return response()->json([
            'success' => true,
            'message' => 'Branch deleted successfully',
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $branch = $this->findScoped($request, $id);

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:active,inactive,suspended',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $branch->update(['status' => $request->status]);

        return response()->json([
            'success' => true,
            'message' => 'Branch status updated',
            'data' => $branch,
        ]);
    }

    private function findScoped(Request $request, $id): Branch
    {
        $user = $request->user();
        $query = Branch::query();

        if ($user->role !== 'super_admin') {
            $query->where('shop_id', $user->shop_id);
        }

        return $query->findOrFail($id);
    }
}
