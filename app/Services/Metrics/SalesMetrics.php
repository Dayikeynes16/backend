<?php

namespace App\Services\Metrics;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use Illuminate\Support\Facades\DB;

class SalesMetrics extends AbstractMetrics
{
    /** @var list<string> */
    public const DEFAULT_STATUSES = ['completed', 'pending'];

    public function summary(DateRange $range, ?int $branchId, int $tenantId, array $statuses = self::DEFAULT_STATUSES): array
    {
        return [
            'current' => $this->aggregate($range, $branchId, $tenantId, $statuses),
            'previous' => $this->aggregate($range->previousComparable(), $branchId, $tenantId, $statuses),
        ];
    }

    private function aggregate(DateRange $range, ?int $branchId, int $tenantId, array $statuses): array
    {
        $gross = $this->grossSales($range, $branchId, $tenantId, $statuses);
        $cancelled = $this->cancelled($range, $branchId, $tenantId);
        $tickets = $this->ticketStats($range, $branchId, $tenantId, $statuses);

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

    private function grossSales(DateRange $range, ?int $branchId, int $tenantId, array $statuses): float
    {
        return (float) $this->grossQuery($range, $branchId, $tenantId, $statuses)->sum('total');
    }

    private function ticketStats(DateRange $range, ?int $branchId, int $tenantId, array $statuses): array
    {
        $count = (int) $this->grossQuery($range, $branchId, $tenantId, $statuses)->count();

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
     * Query base para ventas brutas. Acepta el filtro dinámico de estados
     * desde el chip global (default: Completed + Pending). Excluye cancelled_at
     * a nivel de fila aunque venga 'cancelled' en $statuses, porque el caller
     * típico es "venta generada" — usa cancelled() para ese conteo.
     */
    private function grossQuery(DateRange $range, ?int $branchId, int $tenantId, array $statuses)
    {
        $values = $this->normalizeStatuses($statuses);

        $query = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNull('deleted_at')
            ->whereBetween(DB::raw('COALESCE(completed_at, created_at)'), [$range->start, $range->end]);

        if (empty($values)) {
            return $query->whereRaw('1=0');
        }

        $query->whereIn('status', $values);

        // Si NO se pidió cancelled explícitamente, excluir filas con cancelled_at
        // (defensivo — algunas ventas pueden tener cancelled_at sin status=cancelled).
        if (! in_array(SaleStatus::Cancelled->value, $values, true)) {
            $query->whereNull('cancelled_at');
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    private function normalizeStatuses(array $statuses): array
    {
        return collect($statuses)
            ->map(fn ($s) => match (strtolower((string) $s)) {
                'completed' => SaleStatus::Completed->value,
                'pending' => SaleStatus::Pending->value,
                'cancelled' => SaleStatus::Cancelled->value,
                default => null,
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Serie diaria zero-filled: un punto por cada día del rango, con
     * total=0 y tickets=0 en días sin ventas. Garantiza que el chart de
     * área siempre tenga ≥2 puntos cuando el rango cubre ≥2 días y que
     * la comparación con periodo previo se alinee día por día.
     */
    public function dailySeries(DateRange $range, ?int $branchId, int $tenantId, array $statuses = self::DEFAULT_STATUSES): array
    {
        $rows = $this->grossQuery($range, $branchId, $tenantId, $statuses)
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

    public function hourDayHeatmap(DateRange $range, ?int $branchId, int $tenantId, array $statuses = self::DEFAULT_STATUSES): array
    {
        $rows = $this->grossQuery($range, $branchId, $tenantId, $statuses)
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

    public function dailyTable(DateRange $range, ?int $branchId, int $tenantId, array $statuses = self::DEFAULT_STATUSES): array
    {
        // Ventas brutas por día, filtradas al glosario canónico.
        $grossByDay = $this->grossQuery($range, $branchId, $tenantId, $statuses)
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
