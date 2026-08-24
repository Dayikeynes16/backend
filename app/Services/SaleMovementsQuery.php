<?php

namespace App\Services;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Sale;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Lo que se le hizo a las ventas ya cobradas, agrupado por venta.
 *
 * Lee solo de `audit_logs`. La bitácora `sale_item_changes` sigue existiendo para
 * el historial dentro del detalle de una venta, pero esta pantalla no la consulta:
 * una sola fuente evita paginar sobre dos tablas.
 *
 * Se pagina por **venta**, no por evento — si no, una venta con doce cambios
 * empujaría al resto fuera de la página y el orden por impacto mentiría.
 */
class SaleMovementsQuery
{
    /** Ventana dentro de la cual un cambio de precio y uno de pago se leen como un mismo acto. */
    private const SUSPICIOUS_WINDOW_MINUTES = 60;

    private const PRICE_EVENTS = ['item_added', 'item_updated', 'item_removed'];

    private const PAYMENT_EVENTS = ['payment_updated', 'payment_deleted'];

    /**
     * @param  array{from?: ?string, to?: ?string, branch_id?: ?int, event?: ?string, user_id?: ?int}  $filters
     * @return array{sales: LengthAwarePaginator, summary: array<string, mixed>}
     */
    public function run(array $filters, ?int $forcedBranchId = null, int $perPage = 20): array
    {
        $from = $this->parseDate($filters['from'] ?? null) ?? Carbon::today();
        $to = $this->parseDate($filters['to'] ?? null) ?? Carbon::today();

        // `forcedBranchId` gana siempre: el admin-sucursal no elige sucursal, y
        // mandar otra por query string no debe ampliarle el alcance.
        $branchId = $forcedBranchId ?? ($filters['branch_id'] ?? null);

        $base = fn () => AuditLog::query()
            ->where('auditable_type', (new Sale)->getMorphClass())
            ->whereIn('event', AuditEvent::saleMovements())
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($filters['event'] ?? null, fn ($q, $e) => $q->where('event', $e))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id));

        // Una fila por venta, ordenada por el dinero que se le quitó. El neto
        // ignora los eventos no monetarios: `amount_effect` es NULL en ellos y
        // SUM los salta, que es justo lo que se quiere.
        $grouped = $base()
            ->selectRaw('auditable_id, COUNT(*) as movement_count, COALESCE(SUM(amount_effect), 0) as net_effect, MIN(created_at) as first_at, MAX(created_at) as last_at')
            ->groupBy('auditable_id')
            ->orderByRaw('COALESCE(SUM(amount_effect), 0) ASC')
            ->orderByRaw('MAX(created_at) DESC')
            ->paginate($perPage)
            ->withQueryString();

        $saleIds = collect($grouped->items())->pluck('auditable_id')->all();

        $sales = Sale::withoutGlobalScopes()
            ->whereIn('id', $saleIds)
            ->with('customer:id,name')
            ->get(['id', 'folio', 'total', 'status', 'customer_id', 'branch_id', 'created_at'])
            ->keyBy('id');

        $events = $base()
            ->whereIn('auditable_id', $saleIds)
            ->with(['user:id,name', 'branch:id,name'])
            ->orderBy('created_at')
            ->get()
            ->groupBy('auditable_id');

        $rows = collect($grouped->items())->map(function ($row) use ($sales, $events) {
            $sale = $sales->get($row->auditable_id);
            $saleEvents = $events->get($row->auditable_id, collect());

            return [
                'sale_id' => $row->auditable_id,
                'folio' => $sale?->folio,
                'sale_total' => $sale ? (float) $sale->total : null,
                'sale_status' => $sale?->status?->value,
                'customer_name' => $sale?->customer?->name,
                'branch_name' => $saleEvents->first()?->branch?->name,
                'movement_count' => (int) $row->movement_count,
                'net_effect' => round((float) $row->net_effect, 2),
                'first_at' => $row->first_at,
                'last_at' => $row->last_at,
                'suspicious' => $this->looksLikeOneAct($saleEvents),
                'devices' => $this->devices($saleEvents),
                'movements' => $saleEvents->map(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'event' => $log->event->value,
                    'event_label' => $log->event->label(),
                    'is_monetary' => $log->event->isMonetary(),
                    'changes' => $log->changes,
                    'amount_effect' => $log->amount_effect !== null ? (float) $log->amount_effect : null,
                    'user_name' => $log->user?->name,
                    'device' => $this->describeDevice($log->user_agent),
                    'ip_address' => $log->ip_address,
                    'created_at' => $log->created_at,
                ])->values(),
            ];
        })->values();

        $grouped->setCollection($rows);

        return [
            'sales' => $grouped,
            'summary' => $this->summary($base(), $from, $to),
        ];
    }

    /**
     * Bajar un precio y bajar un pago dentro de la misma hora es la firma de lo
     * que se está buscando. Un cambio suelto casi siempre es una corrección
     * legítima, y marcarlo todo sería igual que no marcar nada.
     *
     * @param  Collection<int, AuditLog>  $events
     */
    private function looksLikeOneAct(Collection $events): bool
    {
        $prices = $events->filter(fn (AuditLog $e) => in_array($e->event->value, self::PRICE_EVENTS, true));
        $payments = $events->filter(fn (AuditLog $e) => in_array($e->event->value, self::PAYMENT_EVENTS, true));

        if ($prices->isEmpty() || $payments->isEmpty()) {
            return false;
        }

        foreach ($prices as $price) {
            foreach ($payments as $payment) {
                if ($price->created_at->diffInMinutes($payment->created_at, absolute: true) <= self::SUSPICIOUS_WINDOW_MINUTES) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, AuditLog>  $events
     * @return list<string>
     */
    private function devices(Collection $events): array
    {
        return $events->map(fn (AuditLog $e) => $this->describeDevice($e->user_agent))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Tipo de equipo, no equipo exacto. La IP no sirve para distinguir máquinas
     * dentro de la sucursal —todas salen por el mismo router— pero el sistema
     * operativo sí separa la tablet del mostrador de una laptop.
     */
    private function describeDevice(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }

        return match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iPhone/iPad',
            str_contains($userAgent, 'Electron') => 'Hub de sucursal',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh') => 'Mac',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Otro',
        };
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @return array<string, mixed>
     */
    private function summary($query, Carbon $from, Carbon $to): array
    {
        $row = $query
            ->selectRaw('COUNT(DISTINCT auditable_id) as sales_touched, COUNT(*) as movement_count, COALESCE(SUM(amount_effect), 0) as net_effect')
            ->first();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'sales_touched' => (int) ($row->sales_touched ?? 0),
            'movement_count' => (int) ($row->movement_count ?? 0),
            'net_effect' => round((float) ($row->net_effect ?? 0), 2),
        ];
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
