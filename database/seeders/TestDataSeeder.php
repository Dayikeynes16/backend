<?php

namespace Database\Seeders;

use App\Enums\SaleStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->warn('TestDataSeeder solo en local/testing. Saltando.');

            return;
        }

        $tenant = Tenant::where('slug', 'el-toro')->firstOrFail();
        $branch = Branch::where('tenant_id', $tenant->id)->firstOrFail();
        $cajero = User::where('email', 'cajero@eltoro.test')->firstOrFail();
        $adminSucursal = User::where('email', 'sucursal@eltoro.test')->firstOrFail();

        app()->instance('tenant', $tenant);

        $products = Product::where('branch_id', $branch->id)->get()->keyBy('name');

        $customers = $this->seedCustomers($branch);

        $this->seedSales($tenant, $branch, $cajero, $adminSucursal, $products, $customers);

        $this->command?->info('TestDataSeeder: '.Customer::count().' clientes, '.Sale::count().' ventas creadas.');
    }

    private function seedCustomers(Branch $branch): array
    {
        $data = [
            ['name' => 'María González',       'phone' => '993-200-0001', 'notes' => 'Cliente frecuente, prefiere arrachera'],
            ['name' => 'Roberto Hernández',    'phone' => '993-200-0002', 'notes' => 'Restaurante "La Parrilla", compra al mayoreo'],
            ['name' => 'Lucía Pérez',          'phone' => '993-200-0003', 'notes' => null],
            ['name' => 'Carlos Ramírez',       'phone' => '993-200-0004', 'notes' => 'Suele dejar pendiente, paga viernes'],
            ['name' => 'Ana Sofía Martínez',   'phone' => '993-200-0005', 'notes' => null],
            ['name' => 'Pedro Jiménez',        'phone' => '993-200-0006', 'notes' => 'Taquería "El Güero"'],
            ['name' => 'Verónica Castillo',    'phone' => '993-200-0007', 'notes' => null],
            ['name' => 'Jorge Mendoza',        'phone' => '993-200-0008', 'notes' => 'Pide pollo entero los domingos'],
            ['name' => 'Patricia Vázquez',     'phone' => '993-200-0009', 'notes' => null],
            ['name' => 'Miguel Torres',        'phone' => '993-200-0010', 'notes' => 'Compra costilla para asados'],
        ];

        $customers = [];
        foreach ($data as $c) {
            $customers[] = Customer::create([
                'branch_id' => $branch->id,
                'name' => $c['name'],
                'phone' => $c['phone'],
                'notes' => $c['notes'],
                'status' => 'active',
            ]);
        }

        return $customers;
    }

    /**
     * @param  array<string, Product>  $products
     * @param  array<int, Customer>  $customers
     */
    private function seedSales(Tenant $tenant, Branch $branch, User $cajero, User $adminSucursal, $products, array $customers): void
    {
        $now = Carbon::now();
        $folio = 1;

        // Helpers
        $bistec = $products['Bistec de res'];
        $chuleta = $products['Chuleta de cerdo'];
        $arrachera = $products['Arrachera'];
        $molida = $products['Carne molida'];
        $pollo = $products['Pollo entero'];
        $costilla = $products['Costilla de res'];

        $mkFolio = function () use (&$folio) {
            return 'F-'.str_pad((string) $folio++, 6, '0', STR_PAD_LEFT);
        };

        // ============ COMPLETADAS (cobradas) ============
        // 1. Walk-in, efectivo, hoy
        $this->makeSale($branch, $cajero, $mkFolio(), SaleStatus::Completed, $now->copy()->subHours(2), [
            [$bistec, 1.250],
            [$molida, 0.800],
        ], 'cash', null);

        // 2. Cliente: María, tarjeta, ayer
        $this->makeSale($branch, $cajero, $mkFolio(), SaleStatus::Completed, $now->copy()->subDay()->setTime(11, 15), [
            [$arrachera, 0.950],
            [$pollo, 2.000],
        ], 'card', $customers[0]->id);

        // 3. Cliente: Roberto (mayoreo), transferencia, hace 3 días
        $this->makeSale($branch, $cajero, $mkFolio(), SaleStatus::Completed, $now->copy()->subDays(3)->setTime(9, 30), [
            [$bistec, 5.000],
            [$arrachera, 3.500],
            [$costilla, 4.200],
        ], 'transfer', $customers[1]->id);

        // 4. Walk-in, efectivo, hace 2 días
        $this->makeSale($branch, $cajero, $mkFolio(), SaleStatus::Completed, $now->copy()->subDays(2)->setTime(17, 45), [
            [$chuleta, 1.500],
        ], 'cash', null);

        // 5. Cliente: Pedro (taquería), transferencia, hace 5 días
        $this->makeSale($branch, $cajero, $mkFolio(), SaleStatus::Completed, $now->copy()->subDays(5)->setTime(7, 50), [
            [$molida, 6.000],
            [$bistec, 2.500],
        ], 'transfer', $customers[5]->id);

        // 6. Cliente: Verónica, efectivo, hoy mañana
        $this->makeSale($branch, $cajero, $mkFolio(), SaleStatus::Completed, $now->copy()->setTime(8, 30), [
            [$pollo, 1.000],
            [$chuleta, 0.700],
        ], 'cash', $customers[6]->id);

        // ============ ACTIVAS (en proceso, sin pago todavía) ============
        // 7. Walk-in en proceso
        $this->makeSale($branch, $cajero, $mkFolio(), SaleStatus::Active, $now->copy()->subMinutes(15), [
            [$arrachera, 0.600],
            [$pollo, 1.000],
        ], null, null);

        // 8. Cliente: Jorge, en proceso
        $this->makeSale($branch, $cajero, $mkFolio(), SaleStatus::Active, $now->copy()->subMinutes(8), [
            [$pollo, 3.000],
        ], null, $customers[7]->id);

        // 9. Walk-in en proceso (varios items)
        $this->makeSale($branch, $cajero, $mkFolio(), SaleStatus::Active, $now->copy()->subMinutes(2), [
            [$bistec, 0.500],
            [$molida, 0.500],
            [$costilla, 1.000],
        ], null, null);

        // ============ PENDIENTES (pago parcial) ============
        // 10. Cliente: Carlos (paga viernes), pago parcial efectivo
        $this->makeSale($branch, $cajero, $mkFolio(), SaleStatus::Pending, $now->copy()->subDay()->setTime(14, 0), [
            [$arrachera, 2.000],
            [$bistec, 1.500],
        ], 'cash', $customers[3]->id, partialFraction: 0.4);

        // 11. Cliente: Miguel, pago parcial transferencia
        $this->makeSale($branch, $cajero, $mkFolio(), SaleStatus::Pending, $now->copy()->subDays(2)->setTime(12, 30), [
            [$costilla, 3.500],
        ], 'transfer', $customers[9]->id, partialFraction: 0.5);

        // ============ CANCELADAS ============
        // 12. Walk-in cancelada (cliente arrepentido)
        $this->makeSale($branch, $cajero, $mkFolio(), SaleStatus::Cancelled, $now->copy()->subDays(4)->setTime(16, 10), [
            [$chuleta, 1.000],
        ], null, null, cancelledBy: $adminSucursal->id, cancelReason: 'Cliente decidió no llevar');

        // 13. Cliente: Patricia, cancelada por error
        $this->makeSale($branch, $cajero, $mkFolio(), SaleStatus::Cancelled, $now->copy()->subDays(6)->setTime(10, 0), [
            [$pollo, 2.000],
            [$molida, 1.000],
        ], null, $customers[8]->id, cancelledBy: $adminSucursal->id, cancelReason: 'Folio duplicado, se rehízo');
    }

    /**
     * @param  array<int, array{0: Product, 1: float}>  $items  [product, quantity]
     */
    private function makeSale(
        Branch $branch,
        User $cajero,
        string $folio,
        SaleStatus $status,
        Carbon $when,
        array $items,
        ?string $paymentMethod,
        ?int $customerId,
        float $partialFraction = 1.0,
        ?int $cancelledBy = null,
        ?string $cancelReason = null,
    ): Sale {
        $sale = Sale::create([
            'branch_id' => $branch->id,
            'user_id' => $cajero->id,
            'customer_id' => $customerId,
            'folio' => $folio,
            'origin' => 'caja',
            'status' => $status->value,
            'payment_method' => $paymentMethod,
            'total' => 0,
            'amount_paid' => 0,
            'amount_pending' => 0,
            'created_at' => $when,
            'updated_at' => $when,
            'completed_at' => $status === SaleStatus::Completed ? $when : null,
            'cancelled_at' => $status === SaleStatus::Cancelled ? $when : null,
            'cancelled_by' => $status === SaleStatus::Cancelled ? $cancelledBy : null,
            'cancel_reason' => $status === SaleStatus::Cancelled ? $cancelReason : null,
        ]);

        $total = 0.0;
        foreach ($items as [$product, $qty]) {
            $unitPrice = (float) $product->price;
            $subtotal = round($unitPrice * $qty, 2);
            $total += $subtotal;

            SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'unit_type' => $product->unit_type,
                'sale_mode_at_sale' => $product->sale_mode,
                'quantity' => $qty,
                'quantity_unit' => 'kg',
                'unit_price' => $unitPrice,
                'original_unit_price' => $unitPrice,
                'subtotal' => $subtotal,
                'created_by' => $cajero->id,
                'created_at' => $when,
                'updated_at' => $when,
            ]);
        }

        $total = round($total, 2);
        $amountPaid = 0.0;
        if ($status === SaleStatus::Completed) {
            $amountPaid = $total;
        } elseif ($status === SaleStatus::Pending) {
            $amountPaid = round($total * $partialFraction, 2);
        }

        $sale->update([
            'total' => $total,
            'amount_paid' => $amountPaid,
            'amount_pending' => round($total - $amountPaid, 2),
        ]);

        if ($amountPaid > 0 && $paymentMethod) {
            Payment::create([
                'sale_id' => $sale->id,
                'user_id' => $cajero->id,
                'method' => $paymentMethod,
                'amount' => $amountPaid,
                'created_at' => $when,
                'updated_at' => $when,
            ]);
        }

        return $sale;
    }
}
