<?php

namespace Tests\Feature\Console;

use App\Enums\SaleStatus;
use App\Models\CashRegisterShift;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Backfill no-destructivo: shifts cerrados antes del fix tienen los nuevos
 * campos en NULL. El comando los puebla sin tocar columnas legacy.
 */
class RecomputeShiftTotalsTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_backfill_populates_new_columns_on_legacy_shifts(): void
    {
        $shiftOpenedAt = Carbon::parse('2026-04-01 09:00:00');
        $shiftClosedAt = Carbon::parse('2026-04-01 18:00:00');

        // Shift cerrado en estado "legacy": columnas nuevas en NULL.
        $shift = CashRegisterShift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'opened_at' => $shiftOpenedAt,
            'closed_at' => $shiftClosedAt,
            'opening_amount' => 0,
            'total_cash' => 1500,
            'total_card' => 0,
            'total_transfer' => 0,
            'total_sales' => 1500,
            'sale_count' => 2,
            'declared_amount' => 1500,
            'expected_amount' => 1500,
            'difference' => 0,
        ]);

        // Datos: 1 venta vieja con $1000 abonados durante el turno + 1 venta
        // del turno con $500 cobrados.
        $oldSale = $this->makeSale('OLD-1', 2000, '2026-03-25 10:00:00', SaleStatus::Active);
        $todaySale = $this->makeSale('NEW-1', 500, '2026-04-01 14:00:00', SaleStatus::Active);
        $this->makePayment($oldSale, 1000, '2026-04-01 12:00:00');
        $this->makePayment($todaySale, 500, '2026-04-01 14:30:00');

        $this->assertNull($shift->fresh()->sales_generated_amount);

        $this->artisan('shifts:recompute-totals')->assertExitCode(0);

        $shift = $shift->fresh();
        $this->assertSame(500.0, (float) $shift->sales_generated_amount);
        $this->assertSame(1, (int) $shift->sales_generated_count);
        $this->assertSame(500.0, (float) $shift->collections_from_today_amount);
        $this->assertSame(1000.0, (float) $shift->collections_from_previous_amount);
        // Legacy queda igual: el comando no toca total_sales / total_cash.
        $this->assertSame('1500.00', $shift->total_sales);
    }

    public function test_dry_run_does_not_persist_changes(): void
    {
        $shift = CashRegisterShift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'opened_at' => Carbon::parse('2026-04-01 09:00:00'),
            'closed_at' => Carbon::parse('2026-04-01 18:00:00'),
            'opening_amount' => 0,
            'total_cash' => 0,
            'total_card' => 0,
            'total_transfer' => 0,
            'total_sales' => 0,
            'sale_count' => 0,
            'declared_amount' => 0,
            'expected_amount' => 0,
            'difference' => 0,
        ]);

        $this->makeSale('S-1', 100, '2026-04-01 12:00:00', SaleStatus::Active);

        $this->artisan('shifts:recompute-totals --dry-run')->assertExitCode(0);

        $this->assertNull($shift->fresh()->sales_generated_amount);
    }

    public function test_since_filter_skips_older_shifts(): void
    {
        $oldShift = CashRegisterShift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'opened_at' => Carbon::parse('2026-01-01 09:00:00'),
            'closed_at' => Carbon::parse('2026-01-01 18:00:00'),
            'opening_amount' => 0, 'total_cash' => 0, 'total_card' => 0, 'total_transfer' => 0,
            'total_sales' => 0, 'sale_count' => 0, 'declared_amount' => 0, 'expected_amount' => 0, 'difference' => 0,
        ]);

        $this->artisan('shifts:recompute-totals --since=2026-04-01')->assertExitCode(0);

        // El shift de enero NO se tocó.
        $this->assertNull($oldShift->fresh()->sales_generated_amount);
    }

    private function makeSale(string $folio, float $total, string $createdAt, SaleStatus $status): Sale
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => $folio,
            'payment_method' => 'cash',
            'total' => $total,
            'amount_paid' => 0,
            'amount_pending' => $total,
            'origin' => 'admin',
            'status' => $status->value,
        ]);
        DB::table('sales')->where('id', $sale->id)->update(['created_at' => $createdAt]);

        return $sale->refresh();
    }

    private function makePayment(Sale $sale, float $amount, string $createdAt): void
    {
        $payment = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => $amount,
        ]);
        DB::table('payments')->where('id', $payment->id)->update(['created_at' => $createdAt]);
    }
}
