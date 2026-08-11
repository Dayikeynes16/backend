<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * `customers:dedup` es un comando **legacy**: se creó para limpiar la cartera
 * antes de instalar el índice `customers_tenant_branch_phone_uniq`
 * (migración 2026_04_17_000005). Con ese índice ya en su sitio, dos clientes
 * con el MISMO teléfono exacto en la misma sucursal son imposibles de insertar,
 * así que este comando no puede encontrar nada que fusionar.
 *
 * Lo que sí sigue ocurriendo son los duplicados que difieren en FORMATO
 * ('993 123 4567' vs '+529931234567'), que para el índice son valores distintos
 * y para este comando también. De ésos se encarga `customers:normalize-phones`,
 * y por eso el comando avisa cuando detecta teléfonos sin normalizar.
 */
class DedupCustomersTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_no_encuentra_duplicados_con_el_indice_unico_vigente(): void
    {
        $this->rawCustomer('+529931234567', 'Juan');
        $this->rawCustomer('+529998887766', 'Pedro');

        $this->artisan('customers:dedup', ['--dry-run' => 'true'])
            ->expectsOutputToContain('No duplicates found')
            ->assertSuccessful();
    }

    public function test_avisa_cuando_hay_telefonos_sin_normalizar(): void
    {
        $this->rawCustomer('993 123 4567', 'Sin normalizar');

        $this->artisan('customers:dedup', ['--dry-run' => 'true'])
            ->expectsOutputToContain('customers:normalize-phones')
            ->assertSuccessful();
    }

    public function test_no_avisa_cuando_todo_esta_normalizado(): void
    {
        $this->rawCustomer('+529931234567', 'Juan');

        $this->artisan('customers:dedup', ['--dry-run' => 'true'])
            ->doesntExpectOutputToContain('customers:normalize-phones')
            ->assertSuccessful();
    }

    private function rawCustomer(string $phone, string $name): int
    {
        return DB::table('customers')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => $name,
            'phone' => $phone,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
