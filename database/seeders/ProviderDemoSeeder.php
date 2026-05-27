<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PurchaseFolioGenerator;
use App\Services\PurchasePaymentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Datos de prueba para el módulo de Proveedores/Compras del tenant demo
 * "el-toro": proveedores con compras, líneas y pagos en distintos estados
 * (pendiente/abonada/pagada), incluyendo el caso de pago "a cuenta" FIFO.
 *
 * Idempotente: si ya existen los proveedores demo, no hace nada.
 * Ejecutar: vendor/bin/sail artisan db:seed --class=Database\\Seeders\\ProviderDemoSeeder
 */
class ProviderDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->warn('ProviderDemoSeeder solo corre en local/testing. Saltando.');

            return;
        }

        $tenant = Tenant::where('slug', 'el-toro')->first();
        if (! $tenant) {
            $this->command?->error('No existe el tenant demo "el-toro". Corre primero el DemoSeeder.');

            return;
        }

        // Bind del tenant para que BelongsToTenant y el PurchasePaymentService
        // resuelvan el scope correctamente fuera de una request HTTP.
        app()->instance('tenant', $tenant);

        if (Provider::where('tenant_id', $tenant->id)->where('name', 'Ganadería Don Beto')->exists()) {
            $this->command?->info('Datos demo de proveedores ya presentes. Saltando.');

            return;
        }

        $branch = Branch::where('tenant_id', $tenant->id)->orderBy('id')->firstOrFail();
        $user = User::where('tenant_id', $tenant->id)->where('email', 'admin@eltoro.test')->first()
            ?? User::where('tenant_id', $tenant->id)->firstOrFail();

        $folios = app(PurchaseFolioGenerator::class);
        $payments = app(PurchasePaymentService::class);

        // Catálogo de productos de compra reutilizable.
        $catalog = [];
        foreach ([
            ['Canal de res', 'kg'], ['Becerro en pie', 'kg'], ['Pierna de cerdo', 'kg'],
            ['Pollo entero', 'pza'], ['Bolsas para carne', 'pza'], ['Guantes', 'caja'],
            ['Flete', 'serv'], ['Costilla', 'kg'],
        ] as [$name, $unit]) {
            $catalog[$name] = PurchaseProduct::firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $name],
                ['unit' => $unit, 'status' => 'active', 'created_by' => $user->id],
            );
        }

        $thisMonth = fn (int $day) => Carbon::now()->startOfMonth()->addDays($day - 1)->setTime(10, 0);

        // 1) Ganadero — una pagada, una abonada, una vieja pendiente.
        $beto = $this->provider($tenant, $user, [
            'name' => 'Ganadería Don Beto', 'type' => 'ganadero',
            'phone' => '993-200-1001', 'email' => 'ventas@donbeto.mx', 'rfc' => 'GDB920101AAA',
            'address' => 'Rancho El Sauce, km 12 carretera a Tepetitán',
        ]);
        $p = $this->purchase($tenant, $branch, $beto, $user, $folios, $thisMonth(3), [
            ['Canal de res', 320, 78.5], ['Costilla', 60, 95],
        ]);
        $payments->applyPayment($p, ['amount' => $p->total, 'payment_method' => 'transfer', 'paid_at' => $thisMonth(3), 'user_id' => $user->id]);

        $p = $this->purchase($tenant, $branch, $beto, $user, $folios, $thisMonth(12), [
            ['Becerro en pie', 180, 92], ['Canal de res', 120, 80],
        ]);
        $payments->applyPayment($p, ['amount' => 8000, 'payment_method' => 'cash', 'paid_at' => $thisMonth(13), 'user_id' => $user->id]);

        $this->purchase($tenant, $branch, $beto, $user, $folios, Carbon::now()->subMonth()->setTime(9, 0), [
            ['Canal de res', 150, 79],
        ]);

        // 2) Mayorista — caso FIFO: 3 compras pendientes 10k/15k/20k + pago a cuenta 40k.
        $bajio = $this->provider($tenant, $user, [
            'name' => 'Carnes del Bajío', 'type' => 'mayorista_carne',
            'phone' => '993-200-1002', 'email' => 'cobranza@carnesbajio.mx', 'rfc' => 'CBA880505BBB',
            'address' => 'Central de Abastos, bodega 44',
        ]);
        $this->purchaseFixedTotal($tenant, $branch, $bajio, $user, $folios, Carbon::now()->subMonths(2)->setTime(8, 0), 'Pierna de cerdo', 10000);
        $this->purchaseFixedTotal($tenant, $branch, $bajio, $user, $folios, Carbon::now()->subMonth()->setTime(8, 0), 'Canal de res', 15000);
        $this->purchaseFixedTotal($tenant, $branch, $bajio, $user, $folios, $thisMonth(8), 'Costilla', 20000);
        // Pago grande a cuenta: cubre 10k + 15k completos y abona 15k al de 20k → queda 5k.
        $payments->applyAccountPayment($bajio, [
            'amount' => 40000, 'payment_method' => 'transfer', 'paid_at' => $thisMonth(15),
            'reference' => 'SPEI 7788', 'user_id' => $user->id, 'branch_id' => null,
        ]);

        // 3) Insumos — al corriente (todo pagado) + una pendiente chica.
        $insumos = $this->provider($tenant, $user, [
            'name' => 'Insumos La Económica', 'type' => 'insumos',
            'phone' => '993-200-1003', 'email' => 'pedidos@laeconomica.mx', 'rfc' => 'ILE100202CCC',
            'address' => 'Calle Morelos 88, Centro',
        ]);
        $p = $this->purchase($tenant, $branch, $insumos, $user, $folios, $thisMonth(5), [
            ['Bolsas para carne', 50, 38], ['Guantes', 10, 160],
        ]);
        $payments->applyPayment($p, ['amount' => $p->total, 'payment_method' => 'cash', 'paid_at' => $thisMonth(5), 'user_id' => $user->id]);
        $this->purchase($tenant, $branch, $insumos, $user, $folios, $thisMonth(18), [
            ['Bolsas para carne', 30, 38],
        ]);

        // 4) Servicios — un flete pendiente.
        $transp = $this->provider($tenant, $user, [
            'name' => 'Transportes del Sur', 'type' => 'servicios',
            'phone' => '993-200-1004', 'email' => 'logistica@transur.mx', 'rfc' => 'TSU150303DDD',
            'address' => 'Bodega 3, Parque Industrial',
        ]);
        $this->purchase($tenant, $branch, $transp, $user, $folios, $thisMonth(10), [
            ['Flete', 1, 2200],
        ]);

        // 5) Otro — compra vieja abonada (fuera del mes, alimenta la deuda histórica).
        $mixta = $this->provider($tenant, $user, [
            'name' => 'Distribuidora Mixta', 'type' => 'otro',
            'phone' => '993-200-1005', 'email' => 'admin@distmixta.mx', 'rfc' => 'DMI170707EEE',
            'address' => 'Av. Reforma 200',
        ]);
        $p = $this->purchaseFixedTotal($tenant, $branch, $mixta, $user, $folios, Carbon::now()->subMonths(2)->setTime(11, 0), 'Pollo entero', 7000);
        $payments->applyPayment($p, ['amount' => 3000, 'payment_method' => 'cash', 'paid_at' => Carbon::now()->subMonths(2)->addDays(5), 'user_id' => $user->id]);

        $this->command?->info('Proveedores demo creados para "el-toro" (5 proveedores, compras y pagos).');
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function provider(Tenant $tenant, User $user, array $attrs): Provider
    {
        return Provider::create(array_merge([
            'tenant_id' => $tenant->id,
            'status' => 'active',
            'created_by' => $user->id,
        ], $attrs));
    }

    /**
     * @param  array<int, array{0: string, 1: float|int, 2: float|int}>  $lines  [concepto, cantidad, precio]
     */
    private function purchase(Tenant $tenant, Branch $branch, Provider $provider, User $user, PurchaseFolioGenerator $folios, Carbon $when, array $lines): Purchase
    {
        $subtotal = 0.0;
        foreach ($lines as [, $qty, $price]) {
            $subtotal += $qty * $price;
        }
        $subtotal = round($subtotal, 2);

        $purchase = Purchase::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'provider_id' => $provider->id,
            'folio' => $folios->nextFolio($tenant->id),
            'purchased_at' => $when,
            'status' => 'received',
            'subtotal' => $subtotal,
            'total' => $subtotal,
            'amount_paid' => 0,
            'amount_pending' => $subtotal,
            'created_by' => $user->id,
        ]);

        foreach ($lines as [$concept, $qty, $price]) {
            $product = PurchaseProduct::where('tenant_id', $tenant->id)->where('name', $concept)->first();
            PurchaseItem::create([
                'purchase_id' => $purchase->id,
                'purchase_product_id' => $product?->id,
                'concept' => $concept,
                'quantity' => $qty,
                'unit' => $product?->unit ?? 'kg',
                'unit_price' => $price,
                'subtotal' => round($qty * $price, 2),
            ]);
        }

        return $purchase;
    }

    /**
     * Crea una compra de un total exacto con una sola línea (para escenarios de
     * saldo redondo como el FIFO).
     */
    private function purchaseFixedTotal(Tenant $tenant, Branch $branch, Provider $provider, User $user, PurchaseFolioGenerator $folios, Carbon $when, string $concept, float $total): Purchase
    {
        return $this->purchase($tenant, $branch, $provider, $user, $folios, $when, [[$concept, 1, $total]]);
    }
}
