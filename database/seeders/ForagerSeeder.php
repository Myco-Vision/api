<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ForagerSeeder extends Seeder
{
    /**
     * Seed the default forager account.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'forager@example.com'],
            [
                'name'           => 'Forager',
                'first_name'     => 'Forager',
                'last_name'      => 'Test',
                'username'       => 'forager',
                'email'          => 'forager@example.com',
                'contact_number' => '',
                'address'        => '',
                'password'       => Hash::make('password'),
                'role'           => 'forager',
            ]
        );
    }
}
