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
        // Crear usuario administrador
        User::factory()->create([
            'name' => 'Administrador',
            'email' => 'admin@sentinel.com',
            'password' => bcrypt('admin123'),
            'role' => 'admin',
        ]);

        // Crear usuario regular de prueba
        User::factory()->create([
            'name' => 'Demo User',
            'email' => 'demo@sentinel.com',
            'password' => bcrypt('password123'),
            'role' => 'user',
        ]);

        // Seeders de inventario
        $this->call([
            UnitOfMeasureSeeder::class,
            MovementTypeSeeder::class,
        ]);
    }
}
