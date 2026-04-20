<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Super Administrator',
            'email' => 'superadmin@pos.com',
            'password' => Hash::make('super123'),
            'role' => 'super_admin',
            'shop_id' => null,
            'status' => 'active',
        ]);
    }
}
