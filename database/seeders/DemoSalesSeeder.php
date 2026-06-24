<?php

namespace Database\Seeders;

use App\Enums\SaleStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Seeder de datos demo: ~1000 ventas para poblar Métricas (Resumen).
 * Dev-only: NO se ejecuta en DatabaseSeeder; correr a mano con
 * `sail artisan db:seed --class=DemoSalesSeeder`.
 *
 * Distribución: ~20 ventas hoy + ~980 repartidas en los últimos 89 días.
 * Mezcla: ~88% cobradas, ~9% a crédito (a un cliente, con fechas viejas para
 * poblar cobranza vencida), ~3% canceladas. Pone `cost_price_at_sale` explícito
 * (los productos demo no tienen costo) para que Utilidad/Margen del Resumen
 * tengan datos.
 */
class DemoSalesSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'el-toro')->first();
        if (! $tenant) {
            $this->command?->error('No existe el tenant "el-toro". Corre primero DemoSeeder.');

            return;
        }
        app()->instance('tenant', $tenant);

        $branchIds = Branch::where('tenant_id', $tenant->id)->pluck('id')->all();
        $products = Product::where('tenant_id', $tenant->id)->get(['id', 'name', 'price', 'unit_type', 'branch_id'])->all();
        $customerIds = Customer::where('tenant_id', $tenant->id)->pluck('id')->all();
        $cajero = User::where('tenant_id', $tenant->id)->whereHas('roles', fn ($q) => $q->where('name', 'cajero'))->first()
            ?? User::where('tenant_id', $tenant->id)->first();

        if (empty($branchIds) || empty($products) || ! $cajero) {
            $this->command?->error('Faltan sucursales, productos o usuarios en el-toro.');

            return;
        }

        $methods = ['cash', 'cash', 'cash', 'card', 'transfer']; // efectivo más frecuente
        $total = 1000;
        $today = 20;

        $this->command?->info("Generando {$total} ventas para {$tenant->name}…");

        for ($i = 0; $i < $total; $i++) {
            // Fecha: las primeras 20 son de hoy; el resto en los últimos 89 días.
            if ($i < $today) {
                $date = Carbon::now()->setTime(rand(8, 20), rand(0, 59), rand(0, 59));
            } else {
                $date = Carbon::now()->subDays(rand(1, 89))->setTime(rand(8, 20), rand(0, 59), rand(0, 59));
            }

            // Tipo de venta.
            $roll = rand(1, 100);
            $isCancelled = $roll <= 3;
            $isCredit = ! $isCancelled && $roll <= 12 && ! empty($customerIds);
            if ($isCredit) {
                // Crédito: sesgo a fechas viejas para que parte caiga vencida (>30 días).
                $date = Carbon::now()->subDays(rand(20, 89))->setTime(rand(8, 20), rand(0, 59));
            }

            $branchId = $branchIds[array_rand($branchIds)];

            $sale = Sale::create([
                'tenant_id' => $tenant->id,
                'branch_id' => $branchId,
                'user_id' => $cajero->id,
                'customer_id' => $isCredit ? $customerIds[array_rand($customerIds)] : null,
                'folio' => 'VD-'.$date->format('ymd').'-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'payment_method' => $methods[array_rand($methods)],
                'origin' => 'admin',
                'status' => $isCancelled ? SaleStatus::Cancelled->value : SaleStatus::Completed->value,
                'total' => 0,
                'amount_paid' => 0,
                'amount_pending' => 0,
                'completed_at' => $isCancelled ? null : $date,
                'cancelled_at' => $isCancelled ? $date : null,
                'created_at' => $date,
                'updated_at' => $date,
            ]);

            // 1–3 líneas.
            $lineCount = rand(1, 3);
            $saleTotal = 0;
            for ($l = 0; $l < $lineCount; $l++) {
                $product = $products[array_rand($products)];
                $price = (float) $product->price;
                $weightLike = in_array($product->unit_type, ['kg', 'g', 'cut', 'l', 'ml'], true);
                $qty = $weightLike ? round(rand(5, 40) / 10, 2) : rand(1, 6);
                $subtotal = round($qty * $price, 2);
                $cost = round($price * (rand(55, 72) / 100), 2); // costo 55–72% del precio

                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_type' => $product->unit_type,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'cost_price_at_sale' => $cost,
                    'subtotal' => $subtotal,
                ]);
                $saleTotal += $subtotal;
            }

            $saleTotal = round($saleTotal, 2);
            $sale->update([
                'total' => $saleTotal,
                'amount_paid' => $isCredit || $isCancelled ? 0 : $saleTotal,
                'amount_pending' => $isCredit ? $saleTotal : 0,
            ]);
        }

        Cache::flush(); // limpia caché de métricas para ver los datos al instante.

        $this->command?->info('Listo. Ventas totales del tenant: '.Sale::where('tenant_id', $tenant->id)->count());
    }
}
