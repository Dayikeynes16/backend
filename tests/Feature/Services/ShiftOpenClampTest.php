<?php

namespace Tests\Feature\Services;

use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Un turno abierto por usuario» vivía solo en PHP, en tres sitios y sin
 * transacción. Dos peticiones simultáneas del hub podían crear dos turnos
 * abiertos, y entonces `ShiftService::current()` —que no ordena— devolvía uno
 * indefinido: el corte del día saldría de un turno u otro según el humor de la
 * base.
 */
class ShiftOpenClampTest extends TestCase
{
    use RefreshDatabase;

    private User $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'T', 'slug' => 'tenant-turnos', 'status' => 'active']);
        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Sucursal 1',
            'address' => 'A',
            'status' => 'active',
        ]);

        $this->cajero = User::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Cajero',
            'email' => 'cajero-turnos@test.test',
            'password' => bcrypt('password'),
        ]);
    }

    private function makeShift(array $attrs = []): CashRegisterShift
    {
        return CashRegisterShift::create(array_merge([
            'tenant_id' => $this->cajero->tenant_id,
            'branch_id' => $this->cajero->branch_id,
            'user_id' => $this->cajero->id,
            'opened_at' => now(),
            'opening_amount' => 0,
        ], $attrs));
    }

    public function test_la_base_impide_dos_turnos_abiertos_del_mismo_usuario(): void
    {
        $this->makeShift();

        $this->expectException(QueryException::class);

        $this->makeShift();
    }

    public function test_permite_un_turno_nuevo_cuando_el_anterior_esta_cerrado(): void
    {
        $this->makeShift(['closed_at' => now()->subHour()]);

        $abierto = $this->makeShift();

        $this->assertNull($abierto->closed_at);
        $this->assertSame(2, CashRegisterShift::where('user_id', $this->cajero->id)->count());
    }

    private function service(): ShiftService
    {
        return app(ShiftService::class);
    }

    public function test_respeta_una_hora_reciente_propuesta_por_el_hub(): void
    {
        $hace2h = now()->subHours(2);

        $shift = $this->service()->open($this->cajero, 500, $hace2h);

        $this->assertEqualsWithDelta($hace2h->timestamp, $shift->opened_at->timestamp, 2);
    }

    public function test_recorta_una_hora_demasiado_antigua_al_margen(): void
    {
        // Un reloj mal puesto no puede abrir un turno de anteayer: eso permitiría
        // recontar pagos viejos y recuperar permisos sobre comprobantes cerrados.
        $shift = $this->service()->open($this->cajero, 0, now()->subDays(2));

        $this->assertEqualsWithDelta(
            now()->subHours(ShiftService::MAX_BACKDATE_HOURS)->timestamp,
            $shift->opened_at->timestamp,
            5
        );
    }

    public function test_recorta_una_hora_futura_a_ahora(): void
    {
        $shift = $this->service()->open($this->cajero, 0, now()->addHours(3));

        $this->assertEqualsWithDelta(now()->timestamp, $shift->opened_at->timestamp, 5);
    }

    public function test_nunca_se_solapa_con_el_turno_anterior_cerrado(): void
    {
        // El caso que duplica dinero: la ventana del corte es [opened_at, closed_at]
        // filtrada por usuario, sin FK. Si el turno nuevo empieza antes de que
        // cerrara el anterior, los pagos de esa franja se cuentan dos veces.
        $cerradoHace1h = now()->subHour();
        $this->makeShift(['opened_at' => now()->subHours(5), 'closed_at' => $cerradoHace1h]);

        $shift = $this->service()->open($this->cajero, 0, now()->subHours(4));

        $this->assertEqualsWithDelta($cerradoHace1h->timestamp, $shift->opened_at->timestamp, 2);
    }

    public function test_sin_hora_propuesta_se_comporta_como_siempre(): void
    {
        $shift = $this->service()->open($this->cajero, 100);

        $this->assertEqualsWithDelta(now()->timestamp, $shift->opened_at->timestamp, 5);
    }

    public function test_la_misma_referencia_devuelve_el_mismo_turno(): void
    {
        $primero = $this->service()->open($this->cajero, 300, null, 'ref-abc');
        $segundo = $this->service()->open($this->cajero, 300, null, 'ref-abc');

        $this->assertSame($primero->id, $segundo->id);
        $this->assertSame(1, CashRegisterShift::where('user_id', $this->cajero->id)->count());
    }
}
