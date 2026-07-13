<?php

namespace Tests\Feature\Api\Hub;

use App\Enums\SaleStatus;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class HistoryApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    private function sale(int $branchId, float $total = 100): Sale
    {
        return Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchId,
            'folio' => 'S-'.fake()->unique()->numerify('#####'),
            'payment_method' => 'cash',
            'total' => $total,
            'amount_paid' => $total,
            'amount_pending' => 0,
            'origin' => 'api',
            'status' => SaleStatus::Completed,
        ]);
    }

    private function payBy(int $userId, Sale $sale): void
    {
        Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $userId,
            'method' => 'cash',
            'amount' => $sale->total,
        ]);
    }

    public function test_lists_only_sales_the_user_charged_today(): void
    {
        $mine = $this->sale($this->branch->id);
        $this->payBy($this->cajero->id, $mine);

        $other = $this->sale($this->branch->id);
        $this->payBy($this->adminSucursal->id, $other); // pagada por otro usuario

        $res = $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/history')
            ->assertOk();

        $folios = collect($res->json('data'))->pluck('folio');
        $this->assertTrue($folios->contains($mine->folio));
        $this->assertFalse($folios->contains($other->folio));
    }

    public function test_filters_by_date(): void
    {
        $today = $this->sale($this->branch->id);
        $this->payBy($this->cajero->id, $today);

        $old = $this->sale($this->branch->id);
        $old->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->payBy($this->cajero->id, $old);

        $res = $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/history?date='.now()->subDays(3)->toDateString())
            ->assertOk();

        $folios = collect($res->json('data'))->pluck('folio');
        $this->assertTrue($folios->contains($old->folio));
        $this->assertFalse($folios->contains($today->folio));
    }

    public function test_admin_empresa_forbidden(): void
    {
        $this->withToken($this->adminEmpresa->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/history')
            ->assertStatus(403);
    }

    private function withItem(Sale $sale, string $name): Sale
    {
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_name' => $name,
            'unit_type' => 'kg',
            'quantity' => 1,
            'unit_price' => $sale->total,
            'subtotal' => $sale->total,
        ]);

        return $sale;
    }

    public function test_filters_by_product(): void
    {
        $beef = $this->withItem($this->sale($this->branch->id), 'Bistec de res');
        $this->payBy($this->cajero->id, $beef);
        $pork = $this->withItem($this->sale($this->branch->id), 'Chuleta de cerdo');
        $this->payBy($this->cajero->id, $pork);

        $res = $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/history?product=res')
            ->assertOk();

        $folios = collect($res->json('data'))->pluck('folio');
        $this->assertTrue($folios->contains($beef->folio));
        $this->assertFalse($folios->contains($pork->folio));
    }

    public function test_filters_by_total_range(): void
    {
        $cheap = $this->sale($this->branch->id, 50);
        $this->payBy($this->cajero->id, $cheap);
        $pricey = $this->sale($this->branch->id, 500);
        $this->payBy($this->cajero->id, $pricey);

        $res = $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/history?min_total=100')
            ->assertOk();

        $folios = collect($res->json('data'))->pluck('folio');
        $this->assertTrue($folios->contains($pricey->folio));
        $this->assertFalse($folios->contains($cheap->folio));
    }

    public function test_admin_sucursal_sees_all_branch_sales_and_searches_by_product(): void
    {
        // Ventas cobradas por el CAJERO (no por la admin), con productos distintos.
        $beef = $this->withItem($this->sale($this->branch->id), 'Pulpa de res');
        $this->payBy($this->cajero->id, $beef);
        $pork = $this->withItem($this->sale($this->branch->id), 'Costilla de cerdo');
        $this->payBy($this->cajero->id, $pork);

        $token = $this->adminSucursal->createToken('hub')->plainTextToken;

        // La admin ve TODAS las ventas de la sucursal, aunque no las cobrara ella.
        $all = $this->withToken($token)->getJson('/api/v1/hub/history')->assertOk();
        $folios = collect($all->json('data'))->pluck('folio');
        $this->assertTrue($folios->contains($beef->folio));
        $this->assertTrue($folios->contains($pork->folio));

        // Y la búsqueda por producto opera sobre ese conjunto completo.
        $res = $this->withToken($token)->getJson('/api/v1/hub/history?product=res')->assertOk();
        $found = collect($res->json('data'))->pluck('folio');
        $this->assertTrue($found->contains($beef->folio));
        $this->assertFalse($found->contains($pork->folio));
    }

    public function test_admin_searches_by_folio_ignoring_date(): void
    {
        // Venta de hace 5 días: la búsqueda por folio la encuentra sin cambiar fecha.
        $old = $this->sale($this->branch->id);
        $old->forceFill(['created_at' => now()->subDays(5), 'completed_at' => now()->subDays(5)])->save();

        $res = $this->withToken($this->adminSucursal->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/history?search='.$old->folio)
            ->assertOk();

        $this->assertTrue(collect($res->json('data'))->pluck('folio')->contains($old->folio));
        // Al buscar, el resumen enriquecido del día no aplica.
        $this->assertNull($res->json('day_summary'));
    }

    public function test_admin_gets_rich_day_summary_and_payment_user(): void
    {
        $sale = $this->sale($this->branch->id, 150);
        $sale->forceFill(['completed_at' => now()])->save();
        $this->payBy($this->cajero->id, $sale);

        $res = $this->withToken($this->adminSucursal->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/history')
            ->assertOk();

        // DaySummaryBar (DailySummaryService): netas, tickets, cobranza y métodos.
        $this->assertEquals(150, $res->json('day_summary.total_sold'));
        $this->assertSame(1, $res->json('day_summary.sale_count'));
        $this->assertEquals(150, $res->json('day_summary.total_collected'));
        $cash = collect($res->json('day_summary.by_method'))->firstWhere('method', 'cash');
        $this->assertEquals(150, $cash['total']);
        $this->assertTrue($res->json('is_admin'));

        // Cada pago trae quién lo cobró.
        $row = collect($res->json('data'))->firstWhere('folio', $sale->folio);
        $this->assertSame($this->cajero->name, $row['payments'][0]['user']['name']);
    }

    public function test_returns_day_summary(): void
    {
        $a = $this->sale($this->branch->id, 120);
        $this->payBy($this->cajero->id, $a);
        $b = $this->sale($this->branch->id, 80);
        $this->payBy($this->cajero->id, $b);

        $res = $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/history')
            ->assertOk();

        $this->assertSame(2, $res->json('summary.count'));
        $this->assertEquals(200, $res->json('summary.total'));
    }
}
