<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'help@piniastudio.com'],
            [
                'name' => 'Piniastudio',
                'password' => Hash::make('password'),
            ]
        );
    }
}
