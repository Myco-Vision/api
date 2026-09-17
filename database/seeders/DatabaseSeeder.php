<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

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
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => '$2y$12$R8io71eIxWu4gKK/88DCnetiwgOoSHoD2s2/GqnKbq/9GgrTq8h7i',
            ]
        );

        $this->call([
            SuperAdminSeeder::class,
            ForagerSeeder::class,
        ]);
    }
}
