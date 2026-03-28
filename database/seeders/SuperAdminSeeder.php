<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure roles exist
        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);

        $user = User::firstOrCreate(
            ['email' => 'admin@carniceriasaas.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('Admin2026!'),
            ]
        );

        if (! $user->hasRole('superadmin')) {
            $user->assignRole('superadmin');
        }

        $this->command?->info("Superadmin listo: admin@carniceriasaas.com");
    }
}
