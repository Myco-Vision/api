<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Seed the default super admin account.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'superadmin@mycovision.com'],
            [
                'name'           => 'Super Admin',
                'first_name'     => 'Super',
                'last_name'      => 'Admin',
                'username'       => 'superadmin',
                'email'          => 'superadmin@mycovision.com',
                'contact_number' => '',
                'address'        => '',
                'password'       => Hash::make('SuperAdmin123!'),
                'role'           => 'super_admin',
            ]
        );
    }
}
