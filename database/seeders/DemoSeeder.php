<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // Superadmin (no tenant)
        $superadmin = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@carniceria.test',
            'password' => Hash::make('password'),
        ]);
        $superadmin->assignRole('superadmin');

        // Tenant: Carnicería El Toro
        $tenant = Tenant::create([
            'name' => 'Carnicería El Toro',
            'slug' => 'el-toro',
            'rfc' => 'TORO850101ABC',
            'address' => 'Av. Juárez 123, Centro',
            'phone' => '993-100-0001',
        ]);

        // Branch: Sucursal Centro
        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Sucursal Centro',
            'address' => 'Av. Juárez 123, Centro',
            'phone' => '993-100-0002',
            'schedule' => 'Lun-Sáb 7am-8pm',
        ]);

        // Admin empresa
        $adminEmpresa = User::create([
            'name' => 'Admin Empresa',
            'email' => 'admin@eltoro.test',
            'password' => Hash::make('password'),
            'tenant_id' => $tenant->id,
        ]);
        $adminEmpresa->assignRole('admin-empresa');

        // Admin sucursal
        $adminSucursal = User::create([
            'name' => 'Admin Sucursal',
            'email' => 'sucursal@eltoro.test',
            'password' => Hash::make('password'),
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
        ]);
        $adminSucursal->assignRole('admin-sucursal');

        // Cajero
        $cajero = User::create([
            'name' => 'Juan Cajero',
            'email' => 'cajero@eltoro.test',
            'password' => Hash::make('password'),
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
        ]);
        $cajero->assignRole('cajero');
    }
}
