<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug' => 'free',
                'name' => 'Free',
                'tagline' => 'Get started, no card needed',
                'monthly_price_pesewas' => 0,
                'yearly_price_pesewas' => 0,
                'item_limit' => 50,
                'branch_limit' => 1,
                'user_limit' => 2,
                'features' => [
                    'Point of sale',
                    'Basic invoices',
                    'Cash, card & mobile money',
                    'Daily sales report',
                ],
                'sort_order' => 1,
            ],
            [
                'slug' => 'starter',
                'name' => 'Starter',
                'tagline' => 'For one shop, growing fast',
                'monthly_price_pesewas' => 7_900,        // GHS 79
                'yearly_price_pesewas' => 76_000,        // GHS 760 (≈20% off)
                'item_limit' => 500,
                'branch_limit' => 1,
                'user_limit' => 5,
                'features' => [
                    'Everything in Free',
                    'Customer database',
                    'Expiry & damaged-stock alerts',
                    'Email support',
                    'Offline mode',
                ],
                'sort_order' => 2,
            ],
            [
                'slug' => 'business',
                'name' => 'Business',
                'tagline' => 'Multi-branch businesses',
                'monthly_price_pesewas' => 19_900,       // GHS 199
                'yearly_price_pesewas' => 191_000,       // GHS 1,910 (≈20% off)
                'item_limit' => 5000,
                'branch_limit' => 5,
                'user_limit' => 20,
                'features' => [
                    'Everything in Starter',
                    'Multi-branch (up to 5)',
                    'SMS receipts & alerts',
                    'Advanced reports',
                    'Audit log',
                    'Priority support',
                ],
                'sort_order' => 3,
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'tagline' => 'Chains & franchises',
                'monthly_price_pesewas' => 49_900,       // GHS 499
                'yearly_price_pesewas' => 479_000,       // GHS 4,790 (≈20% off)
                'item_limit' => null,
                'branch_limit' => null,
                'user_limit' => null,
                'features' => [
                    'Everything in Business',
                    'Unlimited items, branches & users',
                    'Custom integrations',
                    'Dedicated account manager',
                    '24/7 phone support',
                ],
                'sort_order' => 4,
            ],
        ];

        foreach ($plans as $data) {
            Plan::updateOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
