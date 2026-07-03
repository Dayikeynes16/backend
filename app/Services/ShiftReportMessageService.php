<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Models\CashRegisterShift;
use App\Models\Sale;

/**
 * Construye el texto del reporte de cierre de turno que se envía por WhatsApp
 * al dueño. Pensado para leerse rápido en el teléfono, en este orden:
 *
 * 1. Veredicto NETO del turno (de {@see ShiftVerdictService}): si la caja
 *    cuadra, si hay un faltante/sobrante total, o si el neto cuadra pero con
 *    diferencias cruzadas entre métodos (p. ej. falta efectivo pero sobra la
 *    misma cantidad en tarjeta).
 * 2. Resumen del turno: vendido → cobrado → esperado total vs declarado total
 *    → diferencia total.
 * 3. Desglose por método (esperado → declarado, con faltante/sobrante).
 * 4. Arqueo de efectivo con la cuenta explícita, que descuenta retiros, gastos
 *    y compras en efectivo (fondo + cobrado − retiros − gastos − compras).
 */
class ShiftReportMessageService
{
    private const MAX_TEXT_BYTES = 3500;

    public function __construct(private ShiftVerdictService $verdictService) {}

    public function buildShiftCloseText(CashRegisterShift $shift): string
    {
        $shift->loadMissing(['branch:id,tenant_id,name', 'user:id,name', 'withdrawals', 'tenant:id,name']);

        $verdict = $this->verdictService->build($shift);

        $cancelled = $this->cancelledSalesFor($shift);
        $cancelledCount = $cancelled->count();
        $cancelledAmount = (float) $cancelled->sum('total');

        $withdrawalsTotal = (float) $shift->withdrawals->sum('amount');
        $cashExpenses = (float) $shift->total_cash_expenses;
        $cashProviderPayments = (float) $shift->total_cash_provider_payments;
        $totalCollected = (float) $shift->total_cash + (float) $shift->total_card + (float) $shift->total_transfer;
        $fromToday = (float) $shift->collections_from_today_amount;
        $fromPrevious = (float) $shift->collections_from_previous_amount;

        $lines = [];

        // ── Encabezado ─────────────────────────────────────────────
        $lines[] = '*CORTE DE CAJA*';
        if ($shift->tenant && $shift->branch) {
            $lines[] = '_'.$shift->tenant->name.' — '.$shift->branch->name.'_';
        } elseif ($shift->branch) {
            $lines[] = '_'.$shift->branch->name.'_';
        }
        $lines[] = '';

        // Veredicto NETO — lo primero que se ve.
        $lines[] = $verdict['headline'];
        if ($verdict['detail'] !== null) {
            $lines[] = '_'.$verdict['detail'].'_';
        }
        $lines[] = '';

        $lines[] = 'Cierre: '.($shift->closed_at?->format('d/m/Y H:i') ?? '—');
        $lines[] = 'Cajero: '.($shift->user?->name ?? '—');
        $lines[] = 'Turno: '.$this->shiftRange($shift);
        $lines[] = '';
        $lines[] = '━━━━━━━━━━━━━━━━━━';
        $lines[] = '';

        // ── Resumen del turno (vendido → cobrado → esperado vs declarado) ──
        $count = (int) $shift->sales_generated_count;
        $lines[] = '📊 *RESUMEN DEL TURNO*';
        $lines[] = '• Vendido: '.$count.' '.($count === 1 ? 'venta' : 'ventas').' · *'.$this->money($shift->sales_generated_amount).'*';
        if ($cancelledCount > 0) {
            $lines[] = '• Canceladas: '.$cancelledCount.' ('.$this->money($cancelledAmount).') — no cuentan en el total';
        }
        $lines[] = '• Cobrado en el turno: '.$this->money($totalCollected);
        if ($fromPrevious > 0.0) {
            $lines[] = '   ↳ De ventas del turno: '.$this->money($fromToday);
            $lines[] = '   ↳ Abonos a fiados anteriores: '.$this->money($fromPrevious);
        }
        $lines[] = '• Esperado total (todos los métodos): '.$this->money($verdict['expected_total']);
        if ($verdict['status'] === 'undeclared') {
            $lines[] = '• Declarado por el cajero: no declarado';
        } else {
            $lines[] = '• Declarado por el cajero: '.$this->money($verdict['declared_total']);
            if ((float) $verdict['total_diff'] !== 0.0) {
                $lines[] = '• *Diferencia total: '.$this->signedMoney($verdict['total_diff']).'* ⚠️';
            } elseif ($verdict['status'] === 'cross_balanced') {
                // El neto es 0 pero hay descuadres cruzados: no marcamos ✅ para
                // no contradecir el encabezado ⚖️ de arriba.
                $lines[] = '• *Diferencia total: '.$this->money(0).'* ⚖️';
            } else {
                $lines[] = '• *Diferencia total: '.$this->money(0).'* ✅';
            }
        }
        $lines[] = '';

        // ── Desglose por método (esperado → declarado) ──
        $lines[] = '💳 *DESGLOSE POR MÉTODO*  _esperado → declarado_';
        foreach ($verdict['by_method'] as $m) {
            $label = ucfirst($m['label']);
            if ($m['declared_is_null']) {
                $lines[] = '• '.$label.': '.$this->money($m['expected']).' _(no declarado)_';

                continue;
            }
            $tail = (float) $m['diff'] === 0.0
                ? '✅'
                : '('.$this->signedMoney($m['diff']).' '.($m['diff'] < 0.0 ? 'faltante' : 'sobrante').')';
            $lines[] = '• '.$label.': '.$this->money($m['expected']).' → '.$this->money($m['declared']).' '.$tail;
        }
        $lines[] = '';

        // ── Arqueo de efectivo (cuenta explícita, ahora con gastos y compras) ──
        $lines[] = '🧾 *ARQUEO DE EFECTIVO*';
        $lines[] = '• Fondo inicial: '.$this->money($shift->opening_amount);
        $lines[] = '• + Efectivo cobrado: '.$this->money($shift->total_cash);
        if ($withdrawalsTotal > 0.0) {
            $lines[] = '• − Retiros: '.$this->money($withdrawalsTotal);
        }
        if ($cashExpenses > 0.0) {
            $lines[] = '• − Gastos en efectivo: '.$this->money($cashExpenses);
        }
        if ($cashProviderPayments > 0.0) {
            $lines[] = '• − Compras en efectivo: '.$this->money($cashProviderPayments);
        }
        $lines[] = '• = Esperado en cajón: *'.$this->money($shift->expected_amount).'*';
        if ($shift->declared_amount !== null) {
            $lines[] = '• Contado por el cajero: '.$this->money($shift->declared_amount);
            $lines[] = (float) $shift->difference === 0.0
                ? '• Diferencia: ninguna ✅'
                : '• *Diferencia: '.$this->signedMoney($shift->difference).'* '.$this->diffMarker($shift->difference);
        } else {
            $lines[] = '• Conteo de efectivo no declarado por el cajero';
        }

        // ── Notas ──────────────────────────────────────────────────
        if (! empty($shift->notes)) {
            $lines[] = '';
            $lines[] = '_Notas del cajero: '.trim($shift->notes).'_';
        }

        $lines[] = '';
        $lines[] = '━━━━━━━━━━━━━━━━━━';
        $lines[] = '_Reporte automático del corte_';

        return $this->truncateIfNeeded(implode("\n", $lines));
    }

    private function shiftRange(CashRegisterShift $shift): string
    {
        $opened = $shift->opened_at;
        $closed = $shift->closed_at;

        if (! $opened || ! $closed) {
            return ($opened?->format('d/m/Y H:i') ?? '—').' → '.($closed?->format('d/m/Y H:i') ?? '—');
        }

        $range = $opened->isSameDay($closed)
            ? $opened->format('d/m/Y H:i').' → '.$closed->format('H:i')
            : $opened->format('d/m/Y H:i').' → '.$closed->format('d/m/Y H:i');

        $mins = (int) round(abs($opened->diffInMinutes($closed)));
        if ($mins >= 1) {
            $h = intdiv($mins, 60);
            $m = $mins % 60;
            $duration = $h === 0 ? "{$m} min" : ($m === 0 ? "{$h} h" : "{$h} h {$m} min");
            $range .= ' ('.$duration.')';
        }

        return $range;
    }

    private function cancelledSalesFor(CashRegisterShift $shift)
    {
        if (! $shift->closed_at) {
            return Sale::query()->whereRaw('1=0')->get();
        }

        return Sale::query()
            ->where('branch_id', $shift->branch_id)
            ->where('user_id', $shift->user_id)
            ->whereBetween('created_at', [$shift->opened_at, $shift->closed_at])
            ->where('status', SaleStatus::Cancelled->value)
            ->get(['id', 'total']);
    }

    private function money($value): string
    {
        return '$'.number_format((float) $value, 2, '.', ',');
    }

    private function signedMoney($value): string
    {
        $n = (float) $value;
        $sign = $n > 0 ? '+' : ($n < 0 ? '-' : '');

        return $sign.'$'.number_format(abs($n), 2, '.', ',');
    }

    private function diffMarker($value): string
    {
        $n = (float) $value;
        if ($n > 0.0) {
            return '⚠️ sobrante';
        }
        if ($n < 0.0) {
            return '⚠️ faltante';
        }

        return '✅';
    }

    private function truncateIfNeeded(string $text): string
    {
        if (strlen($text) <= self::MAX_TEXT_BYTES) {
            return $text;
        }

        return substr($text, 0, self::MAX_TEXT_BYTES - 3).'...';
    }
}
