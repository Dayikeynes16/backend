<?php

namespace App\Services\Metrics;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use Illuminate\Support\Facades\DB;

class SalesMetrics extends AbstractMetrics
{
    public function summary(DateRange $range, ?int $branchId, int $tenantId): array
    {
        return [
            'current' => $this->aggregate($range, $branchId, $tenantId),
            'previous' => $this->aggregate($range->previousComparable(), $branchId, $tenantId),
        ];
    }

    private function aggregate(DateRange $range, ?int $branchId, int $tenantId): array
    {
        $gross = $this->grossSales($range, $branchId, $tenantId);
        $cancelled = $this->cancelled($range, $branchId, $tenantId);
        $tickets = $this->ticketStats($range, $branchId, $tenantId);

        $net = $gross - $cancelled['amount'];

        return [
            'gross_sales' => $gross,
            'net_sales' => $net,
            'collected' => $this->collected($range, $branchId, $tenantId),
            'ticket_count' => $tickets['count'],
            'avg_ticket' => $tickets['count'] > 0 ? round($net / $tickets['count'], 2) : null,
            'cancelled_count' => $cancelled['count'],
            'cancelled_amount' => $cancelled['amount'],
        ];
    }

    private function grossSales(DateRange $range, ?int $branchId, int $tenantId): float
    {
        return (float) $this->grossQuery($range, $branchId, $tenantId)->sum('total');
    }

    private function ticketStats(DateRange $range, ?int $branchId, int $tenantId): array
    {
        $count = (int) $this->grossQuery($range, $branchId, $tenantId)->count();

        return ['count' => $count];
    }

    private function cancelled(DateRange $range, ?int $branchId, int $tenantId): array
    {
        $row = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', SaleStatus::Cancelled->value)
            ->whereNull('deleted_at')
            ->whereBetween('cancelled_at', [$range->start, $range->end])
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(total), 0) as t')
            ->first();

        return [
            'count' => (int) $row->c,
            'amount' => (float) $row->t,
        ];
    }

    private function collected(DateRange $range, ?int $branchId, int $tenantId): float
    {
        return (float) DB::table('payments as p')
            ->join('sales as s', 's.id', '=', 'p.sale_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->whereNull('p.deleted_at')
            ->whereBetween('p.created_at', [$range->start, $range->end])
            ->sum('p.amount');
    }

    /**
     * Query base para ventas brutas: entregadas, no canceladas, en el rango.
     * Incluye Completed y Pending (esta última = entregada pero no cobrada).
     * Excluye Active (carrito en curso) y Cancelled.
     */
    private function grossQuery(DateRange $range, ?int $branchId, int $tenantId)
    {
        return DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereIn('status', [SaleStatus::Completed->value, SaleStatus::Pending->value])
            ->whereNull('cancelled_at')
            ->whereNull('deleted_at')
            ->whereBetween(DB::raw('COALESCE(completed_at, created_at)'), [$range->start, $range->end]);
    }

    /**
     * Serie diaria zero-filled: un punto por cada día del rango, con
     * total=0 y tickets=0 en días sin ventas. Garantiza que el chart de
     * área siempre tenga ≥2 puntos cuando el rango cubre ≥2 días y que
     * la comparación con periodo previo se alinee día por día.
     */
    public function dailySeries(DateRange $range, ?int $branchId, int $tenantId): array
    {
        $rows = $this->grossQuery($range, $branchId, $tenantId)
            ->selectRaw('DATE(COALESCE(completed_at, created_at)) as day, COUNT(*) as tickets, COALESCE(SUM(total), 0) as total')
            ->groupBy('day')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->day => [
                'tickets' => (int) $r->tickets,
                'total' => (float) $r->total,
            ]])
            ->all();

        return $this->zeroFillDays($range, $rows, ['tickets' => 0, 'total' => 0.0]);
    }

    public function hourDayHeatmap(DateRange $range, ?int $branchId, int $tenantId): array
    {
        $rows = $this->grossQuery($range, $branchId, $tenantId)
            ->selectRaw('EXTRACT(ISODOW FROM COALESCE(completed_at, created_at)) as dow, EXTRACT(HOUR FROM COALESCE(completed_at, created_at)) as hour, COUNT(*) as tickets, COALESCE(SUM(total), 0) as total')
            ->groupBy('dow', 'hour')
            ->get();

        $matrix = [];
        for ($d = 1; $d <= 7; $d++) {
            for ($h = 0; $h <= 23; $h++) {
                $matrix[$d][$h] = ['tickets' => 0, 'total' => 0.0];
            }
        }

        foreach ($rows as $r) {
            $matrix[(int) $r->dow][(int) $r->hour] = [
                'tickets' => (int) $r->tickets,
                'total' => (float) $r->total,
            ];
        }

        return $matrix;
    }

    /**
     * Desglose dinámico de pagos por método. Agrupa por payments.method
     * (no sales.payment_method) para capturar pagos divididos correctamente.
     * Devuelve labels resueltos desde PaymentMethod enum (tolera slugs no mapeados).
     */
    public function byPaymentMethod(DateRange $range, ?int $branchId, int $tenantId): array
    {
        $rows = DB::table('payments as p')
            ->join('sales as s', 's.id', '=', 'p.sale_id')
            ->where('s.tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->whereNull('p.deleted_at')
            ->whereBetween('p.created_at', [$range->start, $range->end])
            ->selectRaw('p.method as method, COALESCE(SUM(p.amount), 0) as total, COUNT(*) as count, COALESCE(AVG(p.amount), 0) as average')
            ->groupBy('p.method')
            ->orderByDesc('total')
            ->get();

        return $rows->map(fn ($r) => [
            'method' => (string) $r->method,
            'label' => PaymentMethod::resolveLabel((string) $r->method),
            'total' => (float) $r->total,
            'count' => (int) $r->count,
            'average' => round((float) $r->average, 2),
        ])->all();
    }

    public function dailyTable(DateRange $range, ?int $branchId, int $tenantId): array
    {
        // Ventas brutas por día, filtradas al glosario canónico.
        $grossByDay = $this->grossQuery($range, $branchId, $tenantId)
            ->selectRaw('DATE(COALESCE(completed_at, created_at)) as day, COUNT(*) as tickets, COALESCE(SUM(total), 0) as total')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        // Cancelaciones por día (por cancelled_at).
        $cancelledByDay = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', SaleStatus::Cancelled->value)
            ->whereNull('deleted_at')
            ->whereBetween('cancelled_at', [$range->start, $range->end])
            ->selectRaw('DATE(cancelled_at) as day, COUNT(*) as cancelled')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $days = collect($grossByDay->keys())->merge($cancelledByDay->keys())->unique()->sortDesc()->values();

        return $days->map(function ($day) use ($grossByDay, $cancelledByDay) {
            $g = $grossByDay->get($day);
            $c = $cancelledByDay->get($day);
            $tickets = (int) ($g->tickets ?? 0);
            $total = (float) ($g->total ?? 0);

            return [
                'day' => (string) $day,
                'tickets' => $tickets,
                'total' => $total,
                'avg_ticket' => $tickets > 0 ? round($total / $tickets, 2) : 0.0,
                'cancelled' => (int) ($c->cancelled ?? 0),
            ];
        })->all();
    }
}
