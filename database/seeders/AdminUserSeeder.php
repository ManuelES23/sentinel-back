<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Crear usuario administrador
        User::create([
            'name' => 'Administrador',
            'email' => 'admin@sentinel.com',
            'password' => Hash::make('password'),
            'role' => 'superadmin',
            'phone' => '1234567890',
        ]);

        $this->command->info('Usuario administrador creado exitosamente!');
        $this->command->info('Email: admin@sentinel.com');
        $this->command->info('Password: password');
    }
}
