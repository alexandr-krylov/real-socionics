<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('users')->insert([
            'name' => env('ADMIN_LOGIN', 'admin'),
            'password' => Hash::make(env('ADMIN_PASSWORD', 'secret123')),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
