<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One-time backfill: assign every row that pre-dates the multi-branch system
 * (branch_id IS NULL) to its shop's main branch. If the shop doesn't have one
 * yet, create a "Main Branch" first and mark it as main.
 *
 * Idempotent — only touches rows where branch_id IS NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('branches') || !Schema::hasTable('shops')) {
            return;
        }

        $shopIds = DB::table('shops')->pluck('id');

        foreach ($shopIds as $shopId) {
            $mainBranch = DB::table('branches')
                ->where('shop_id', $shopId)
                ->orderByDesc('is_main')
                ->orderBy('created_at')
                ->first();

            if (!$mainBranch) {
                $newId = (string) Str::uuid();
                DB::table('branches')->insert([
                    'id' => $newId,
                    'shop_id' => $shopId,
                    'name' => 'Main Branch',
                    'is_main' => true,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $branchId = $newId;
            } else {
                $branchId = $mainBranch->id;
                if (!$mainBranch->is_main) {
                    // Promote it to main when no main exists.
                    $hasMain = DB::table('branches')->where('shop_id', $shopId)->where('is_main', true)->exists();
                    if (!$hasMain) {
                        DB::table('branches')->where('id', $branchId)->update(['is_main' => true]);
                    }
                }
            }

            foreach (['products', 'customers', 'invoices', 'users', 'expenses'] as $table) {
                if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'branch_id')) {
                    continue;
                }
                DB::table($table)
                    ->where('shop_id', $shopId)
                    ->whereNull('branch_id')
                    ->update(['branch_id' => $branchId]);
            }
        }
    }

    public function down(): void
    {
        // Not reversible — we can't tell which rows were originally null.
    }
};
