<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::firstOrCreate(
            ['email' => 'dev@tiptok.io'],
            [
                'name' => 'Alex Developer',
                'password' => Hash::make('Secret123!'),
                'phone_country_code' => '+33',
                'phone_number' => '0612345678',
                'email_verified_at' => now(),
            ]
        );
    }
}
