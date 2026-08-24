<?php

namespace Tests\Feature\Services;

use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Tenant;
use App\Models\User;
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
}
