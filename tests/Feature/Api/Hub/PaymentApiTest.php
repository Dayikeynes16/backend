<?php

namespace Tests\Feature\Api\Hub;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    private function token(): string
    {
        return $this->cajero->createToken('hub')->plainTextToken;
    }

    private function openShift(string $token): void
    {
        $this->withToken($token)->postJson('/api/v1/hub/shift/open', ['opening_amount' => 0])->assertCreated();
    }

    private function activeSale(int $branchId, float $total = 100): Sale
    {
        return Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchId,
            'folio' => 'S-'.fake()->unique()->numerify('#####'),
            'payment_method' => 'cash',
            'total' => $total,
            'amount_paid' => 0,
            'amount_pending' => $total,
            'origin' => 'api',
            'status' => SaleStatus::Active,
        ]);
    }

    public function test_payments_index_cajero_sees_only_own(): void
    {
        $sale = $this->activeSale($this->branch->id, 500);
        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 100]);
        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->adminSucursal->id, 'method' => 'card', 'amount' => 50]);

        $res = $this->withToken($this->token())->getJson('/api/v1/hub/payments')->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertEquals(100, $res->json('data.0.amount'));
        $this->assertEquals(100, $res->json('summary.total'));
        $this->assertFalse($res->json('is_admin'));
        $this->assertCount(0, $res->json('users'));
    }

    public function test_payments_index_admin_sees_all_and_filters_by_user(): void
    {
        $sale = $this->activeSale($this->branch->id, 500);
        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 100]);
        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->adminSucursal->id, 'method' => 'card', 'amount' => 50]);

        $adminToken = $this->adminSucursal->createToken('hub')->plainTextToken;

        $all = $this->withToken($adminToken)->getJson('/api/v1/hub/payments')->assertOk();
        $this->assertCount(2, $all->json('data'));
        $this->assertEquals(150, $all->json('summary.total'));
        $this->assertTrue($all->json('is_admin'));
        $this->assertNotEmpty($all->json('users'));

        $filtered = $this->withToken($adminToken)
            ->getJson('/api/v1/hub/payments?user_id='.$this->cajero->id)
            ->assertOk();
        $this->assertCount(1, $filtered->json('data'));
        $this->assertEquals(100, $filtered->json('summary.total'));
    }

    public function test_payments_index_filters_by_customer_and_exposes_sale_context(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'name' => 'Doña Mary', 'status' => 'active',
        ]);
        // Venta con cliente, creada AYER (para el chip "Venta de ayer").
        $withCustomer = $this->activeSale($this->branch->id, 300);
        $withCustomer->forceFill(['customer_id' => $customer->id, 'created_at' => now()->subDay()])->save();
        Payment::create(['sale_id' => $withCustomer->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 100, 'updated_by' => $this->adminSucursal->id]);
        // Venta de mostrador.
        $counter = $this->activeSale($this->branch->id, 50);
        Payment::create(['sale_id' => $counter->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 50]);

        $with = $this->withToken($this->token())->getJson('/api/v1/hub/payments?customer=with')->assertOk();
        $this->assertCount(1, $with->json('data'));
        $row = $with->json('data.0');
        // Contexto para chips: fecha de la venta, pagado, y quién editó el pago.
        $this->assertSame(now()->subDay()->toDateString(), substr($row['sale']['created_at'], 0, 10));
        $this->assertSame('Doña Mary', $row['sale']['customer']['name']);
        $this->assertSame($this->adminSucursal->name, $row['updated_by_user']['name']);

        $without = $this->withToken($this->token())->getJson('/api/v1/hub/payments?customer=without')->assertOk();
        $this->assertCount(1, $without->json('data'));
        $this->assertNull($without->json('data.0.sale.customer'));
    }

    public function test_payments_index_collapses_global_fifo_collection(): void
    {
        $sale1 = $this->activeSale($this->branch->id, 2000);
        $sale2 = $this->activeSale($this->branch->id, 200);

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Mike',
            'status' => 'active',
        ]);

        $cp = CustomerPayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'user_id' => $this->cajero->id,
            'folio' => 'CG-00001',
            'method' => 'transfer',
            'amount_received' => 2100,
            'amount_applied' => 2100,
            'change_given' => 0,
            'sales_affected_count' => 2,
        ]);

        // Dos pagos hijos del mismo cobro global + un pago directo (standalone).
        Payment::create(['sale_id' => $sale1->id, 'customer_payment_id' => $cp->id, 'user_id' => $this->cajero->id, 'method' => 'transfer', 'amount' => 1977.89]);
        Payment::create(['sale_id' => $sale2->id, 'customer_payment_id' => $cp->id, 'user_id' => $this->cajero->id, 'method' => 'transfer', 'amount' => 122.11]);
        Payment::create(['sale_id' => $sale1->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 50]);

        $res = $this->withToken($this->token())->getJson('/api/v1/hub/payments')->assertOk();

        // La lista colapsa el cobro global en 1 renglón → 2 entradas (global + directo).
        $this->assertCount(2, $res->json('data'));
        // El KPI total SÍ suma todo: 1977.89 + 122.11 + 50 = 2150.
        $this->assertEquals(2150, $res->json('summary.total'));

        $global = collect($res->json('data'))->firstWhere('type', 'global');
        $this->assertNotNull($global);
        $this->assertEquals('CG-00001', $global['folio']);
        $this->assertEquals(2100, $global['amount']); // amount_applied, no el hijo
        $this->assertEquals('Mike', $global['customer']['name']);
    }

    public function test_payment_requires_open_shift(): void
    {
        $sale = $this->activeSale($this->branch->id);
        $this->withToken($this->token())
            ->postJson("/api/v1/hub/sales/{$sale->id}/payments", ['method' => 'cash', 'amount' => 100])
            ->assertStatus(409);
    }

    public function test_full_payment_completes_sale_and_returns_change(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $sale = $this->activeSale($this->branch->id, 100);

        $this->withToken($token)
            ->postJson("/api/v1/hub/sales/{$sale->id}/payments", ['method' => 'cash', 'amount' => 120])
            ->assertCreated()
            // JSON serializa floats enteros sin decimales (20.0 -> 20); assertJsonPath compara estricto.
            ->assertJsonPath('change', 20)
            ->assertJsonPath('sale.status', SaleStatus::Completed->value)
            ->assertJsonPath('sale.amount_pending', 0);
    }

    public function test_payment_is_idempotent_by_client_reference(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $sale = $this->activeSale($this->branch->id, 100);
        $ref = 'pay-ref-1';

        $first = $this->withToken($token)->postJson("/api/v1/hub/sales/{$sale->id}/payments", [
            'method' => 'cash', 'amount' => 50, 'client_reference' => $ref,
        ])->assertCreated();

        $second = $this->withToken($token)->postJson("/api/v1/hub/sales/{$sale->id}/payments", [
            'method' => 'cash', 'amount' => 50, 'client_reference' => $ref,
        ])->assertSuccessful();

        $this->assertSame(1, Payment::where('sale_id', $sale->id)->count());
        $this->assertSame($first->json('payment.id'), $second->json('payment.id'));
    }

    /**
     * Dos reintentos del hub a la vez: el primero aún no ha confirmado cuando
     * el segundo consulta, así que ninguno ve al otro y ambos llegan al INSERT.
     * El índice único (sale_id, client_reference) frena al segundo — y eso NO
     * es un error: es el mismo cobro llegando dos veces. Antes salía un 500 que
     * el hub leía como fallo del servidor y volvía a reintentar un cobro hecho.
     */
    public function test_a_concurrent_retry_replays_the_payment_instead_of_failing(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $sale = $this->activeSale($this->branch->id, 100);
        $ref = 'pay-race-1';

        // El gemelo: se cuela justo DESPUÉS de la consulta de idempotencia y
        // ANTES de que se abra la transacción, que es exactamente la ventana en
        // la que la petición A todavía no ha confirmado y B no puede verla. Al
        // quedar fuera de esa transacción, sobrevive a su rollback igual que
        // sobrevive el COMMIT de la otra petición en la vida real.
        $twinId = null;
        DB::listen(function ($query) use (&$twinId, $sale, $ref) {
            if ($twinId !== null
                || ! str_contains($query->sql, 'client_reference')
                || ! str_starts_with(ltrim($query->sql), 'select')) {
                return;
            }
            $twinId = DB::table('payments')->insertGetId([
                'sale_id' => $sale->id,
                'user_id' => $this->cajero->id,
                'method' => 'cash',
                'amount' => 50,
                'client_reference' => $ref,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $res = $this->withToken($token)->postJson("/api/v1/hub/sales/{$sale->id}/payments", [
            'method' => 'cash', 'amount' => 50, 'client_reference' => $ref,
        ]);

        $res->assertOk();
        $this->assertNotNull($twinId, 'El gemelo debía insertarse para provocar el choque.');
        $this->assertSame($twinId, $res->json('payment.id'), 'Debe devolver el cobro que sí quedó registrado.');
        $this->assertSame(1, Payment::where('sale_id', $sale->id)->count(), 'El dinero no puede cobrarse dos veces.');
    }

    public function test_cannot_pay_other_branch_sale(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $other = $this->activeSale($this->secondBranch->id, 100);

        $this->withToken($token)
            ->postJson("/api/v1/hub/sales/{$other->id}/payments", ['method' => 'cash', 'amount' => 100])
            ->assertStatus(404);
    }

    /** Un cobro a cuenta de tres ventas: un renglón en la lista, tres pagos hijos. */
    private function cobroACuentaDeTresVentas(): void
    {
        $cliente = Customer::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'name' => 'Don Beto', 'status' => 'active',
        ]);
        $cp = CustomerPayment::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'customer_id' => $cliente->id, 'user_id' => $this->cajero->id,
            'folio' => 'CG-00099', 'method' => 'cash',
            'amount_received' => 300, 'amount_applied' => 300,
            'change_given' => 0, 'sales_affected_count' => 3,
        ]);
        foreach ([100, 100, 100] as $monto) {
            $venta = $this->activeSale($this->branch->id, $monto);
            $venta->forceFill(['customer_id' => $cliente->id])->save();
            Payment::create([
                'sale_id' => $venta->id, 'user_id' => $this->cajero->id,
                'method' => 'cash', 'amount' => $monto, 'customer_payment_id' => $cp->id,
            ]);
        }
    }

    public function test_by_method_sigue_siendo_un_mapa(): void
    {
        // El servicio lo devuelve como lista de objetos. Emitirlo tal cual
        // dejaría a las tablets con la 1.4.0 con $0 en todas las pastillas de
        // método: lo leen como mapa. Ya pasó una vez con day_summary.
        $venta = $this->activeSale($this->branch->id, 100);
        Payment::create(['sale_id' => $venta->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 100]);

        $res = $this->withToken($this->token())->getJson('/api/v1/hub/payments')->assertOk();

        $this->assertEquals(100, $res->json('summary.by_method.cash'));
        $this->assertArrayHasKey('card', $res->json('summary.by_method'));
        $this->assertArrayHasKey('transfer', $res->json('summary.by_method'));
    }

    public function test_payment_count_cuenta_las_filas_de_la_lista(): void
    {
        // Un cobro a cuenta se colapsa a un renglón. Si el conteo viniera del
        // servicio, la cabecera diría «3 cobros» sobre una lista de 1.
        $this->cobroACuentaDeTresVentas();

        $res = $this->withToken($this->token())->getJson('/api/v1/hub/payments')->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame(1, $res->json('summary.payment_count'));
        // El total sí suma los tres hijos: son dinero que entró.
        $this->assertEquals(300, $res->json('summary.total'));
    }

    public function test_el_resumen_de_un_cajero_es_solo_suyo(): void
    {
        // El alcance del cajero lo fuerza la consulta de la lista. El servicio
        // no sabe de roles: si se le pasara el user_id sólo cuando lo piden,
        // un cajero vería la cobranza de toda la sucursal en sus tres cifras.
        $mio = $this->activeSale($this->branch->id, 40);
        Payment::create(['sale_id' => $mio->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 40]);
        $ajeno = $this->activeSale($this->branch->id, 500);
        Payment::create(['sale_id' => $ajeno->id, 'user_id' => $this->adminSucursal->id, 'method' => 'cash', 'amount' => 500]);

        $res = $this->withToken($this->token())->getJson('/api/v1/hub/payments')->assertOk();

        $this->assertEquals(40, $res->json('summary.total'));
        $this->assertEquals(40, $res->json('summary.by_method.cash'));
    }

    public function test_el_resumen_no_responde_al_filtro_de_metodo(): void
    {
        // Las pastillas enseñan el importe de cada método: si el resumen se
        // filtrara por método, elegir «Efectivo» pondría las otras en cero y
        // ya no habría con qué comparar. Es la semántica de la web, a propósito.
        $a = $this->activeSale($this->branch->id, 100);
        Payment::create(['sale_id' => $a->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 100]);
        $b = $this->activeSale($this->branch->id, 60);
        Payment::create(['sale_id' => $b->id, 'user_id' => $this->cajero->id, 'method' => 'card', 'amount' => 60]);

        $res = $this->withToken($this->token())->getJson('/api/v1/hub/payments?method=cash')->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertEquals(160, $res->json('summary.total'));
        $this->assertEquals(60, $res->json('summary.by_method.card'));
    }

    public function test_rango_y_su_validacion(): void
    {
        $token = $this->token();

        $this->withToken($token)->getJson('/api/v1/hub/payments?preset=last_7_days')->assertOk();
        $this->withToken($token)->getJson('/api/v1/hub/payments?from=2026-09-20&to=2026-09-18')->assertStatus(422);
        $this->withToken($token)->getJson('/api/v1/hub/payments?from=2026-09-20')->assertStatus(422);
        $this->withToken($token)->getJson('/api/v1/hub/payments?to=2026-09-20')->assertStatus(422);
        $this->withToken($token)->getJson('/api/v1/hub/payments?preset=nunca')->assertStatus(422);

        $res = $this->withToken($token)->getJson('/api/v1/hub/payments?preset=today')->assertOk();
        // `date` conserva su forma —una cadena— y `from`/`to` se añaden al lado.
        $this->assertSame(today()->toDateString(), $res->json('summary.date'));
        $this->assertSame(today()->toDateString(), $res->json('summary.from'));
        $this->assertSame(today()->toDateString(), $res->json('summary.to'));
    }

    public function test_un_rango_de_varios_dias_toma_los_cobros_de_todos(): void
    {
        $venta = $this->activeSale($this->branch->id, 80);
        $pago = Payment::create(['sale_id' => $venta->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 80]);
        $pago->forceFill(['created_at' => now()->subDays(3)])->save();

        $hoy = $this->withToken($this->token())->getJson('/api/v1/hub/payments')->assertOk();
        $this->assertCount(0, $hoy->json('data'));

        $semana = $this->withToken($this->token())->getJson('/api/v1/hub/payments?preset=last_7_days')->assertOk();
        $this->assertCount(1, $semana->json('data'));
        $this->assertEquals(80, $semana->json('summary.total'));
    }

    public function test_cada_cobro_trae_los_demas_cobros_de_su_venta(): void
    {
        // Viendo un cobro de $340 hay que poder saber si fue el único o si
        // hubo otros antes: es lo que se viene a mirar cuando algo no cuadra.
        $venta = $this->activeSale($this->branch->id, 300);
        Payment::create(['sale_id' => $venta->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 200]);
        Payment::create([
            'sale_id' => $venta->id, 'user_id' => $this->cajero->id, 'method' => 'card',
            'amount' => 100, 'updated_by' => $this->adminSucursal->id,
        ]);

        $res = $this->withToken($this->token())->getJson('/api/v1/hub/payments')->assertOk();

        $hermanos = collect($res->json('data'))->firstWhere('sale.id', $venta->id)['sale']['payments'];

        $this->assertCount(2, $hermanos);
        $this->assertEqualsCanonicalizing([200, 100], collect($hermanos)->pluck('amount')->all());

        $tarjeta = collect($hermanos)->firstWhere('method', 'card');
        $this->assertSame($this->cajero->name, $tarjeta['user']['name']);
        // Quién lo corrigió, que es la insignia «Editado».
        $this->assertSame($this->adminSucursal->name, $tarjeta['updated_by_user']['name']);
        // De esto depende que se ofrezca corregirlo: un pago hijo de un cobro
        // a cuenta no se edita suelto.
        $this->assertArrayHasKey('customer_payment_id', $tarjeta);
        $this->assertNull($tarjeta['customer_payment_id']);
    }
}
