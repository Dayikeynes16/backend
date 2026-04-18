<?php

namespace Tests\Concerns;

use App\Enums\SaleStatus;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;

trait SeedsMetricsData
{
    protected Tenant $tenant;
    protected Branch $branch;
    protected Branch $secondBranch;
    protected User $adminSucursal;
    protected User $adminEmpresa;
    protected User $cajero;

    protected function seedRoles(): void
    {
        $guard = 'web';
        foreach (['superadmin', 'admin-empresa', 'admin-sucursal', 'cajero'] as $role) {
            \Spatie\Permission\Models\Role::firstOrCreate(['name' => $role, 'guard_name' => $guard]);
        }
    }

    protected function seedTenant(string $slug = 'test-tenant'): void
    {
        $this->seedRoles();
        $this->tenant = Tenant::create(['name' => 'Test', 'slug' => $slug, 'status' => 'active']);
        $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Sucursal 1', 'address' => 'A', 'status' => 'active']);
        $this->secondBranch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Sucursal 2', 'address' => 'B', 'status' => 'active']);

        $this->adminEmpresa = $this->makeUser('admin@test.local', 'admin-empresa', null);
        $this->adminSucursal = $this->makeUser('suc@test.local', 'admin-sucursal', $this->branch->id);
        $this->cajero = $this->makeUser('caja@test.local', 'cajero', $this->branch->id);
    }

    protected function makeUser(string $email, string $role, ?int $branchId): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchId,
            'name' => $role,
            'email' => $email,
            'password' => bcrypt('password'),
        ]);
        $user->assignRole($role);
        return $user;
    }

    protected function makeProduct(array $attrs = []): Product
    {
        return Product::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Product '.uniqid(),
            'price' => 100,
            'cost_price' => 60,
            'unit_type' => 'pieza',
            'status' => 'active',
        ], $attrs));
    }

    protected function makeCompletedSale(array $attrs = [], array $items = []): Sale
    {
        $sale = Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'F'.uniqid(),
            'payment_method' => 'cash',
            'total' => 0,
            'amount_paid' => 0,
            'amount_pending' => 0,
            'origin' => 'admin',
            'status' => SaleStatus::Completed->value,
            'completed_at' => Carbon::parse('2026-04-15 14:00:00'),
        ], $attrs));

        $total = 0;
        foreach ($items as $it) {
            $qty = $it['quantity'] ?? 1;
            $unit = $it['unit_price'] ?? 100;
            $subtotal = $qty * $unit;
            SaleItem::create(array_merge([
                'sale_id' => $sale->id,
                'product_id' => $it['product_id'] ?? null,
                'product_name' => $it['product_name'] ?? 'Product',
                'unit_type' => 'pieza',
                'quantity' => $qty,
                'unit_price' => $unit,
                'subtotal' => $subtotal,
            ], $it));
            $total += $subtotal;
        }
        if ($total > 0) {
            $sale->update(['total' => $total, 'amount_paid' => $total]);
        }
        return $sale;
    }
}
