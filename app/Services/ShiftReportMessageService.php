<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Models\CashRegisterShift;
use App\Models\Sale;

/**
 * Construye el texto del reporte de cierre de turno que se envía por WhatsApp
 * al dueño. Pensado para leerse rápido en el teléfono: arriba el veredicto del
 * arqueo, luego "lo que se vendió" vs "lo que entró al cajón", y al final el
 * arqueo de efectivo con la cuenta explícita (fondo + cobrado − retiros).
 */
class ShiftReportMessageService
{
    private const MAX_TEXT_BYTES = 3500;

    public function buildShiftCloseText(CashRegisterShift $shift): string
    {
        $shift->loadMissing(['branch:id,tenant_id,name', 'user:id,name', 'withdrawals', 'tenant:id,name']);

        $cancelled = $this->cancelledSalesFor($shift);
        $cancelledCount = $cancelled->count();
        $cancelledAmount = (float) $cancelled->sum('total');

        $withdrawalsTotal = (float) $shift->withdrawals->sum('amount');
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

        // Veredicto del arqueo — lo primero que se ve.
        $lines[] = $this->verdictLine($shift);
        $lines[] = '';

        $lines[] = 'Cierre: '.($shift->closed_at?->format('d/m/Y H:i') ?? '—');
        $lines[] = 'Cajero: '.($shift->user?->name ?? '—');
        $lines[] = 'Turno: '.$this->shiftRange($shift);
        $lines[] = '';
        $lines[] = '━━━━━━━━━━━━━━━━━━';
        $lines[] = '';

        // ── Ventas del turno (lo que se vendió) ────────────────────
        $count = (int) $shift->sales_generated_count;
        $lines[] = '🛒 *VENTAS DEL TURNO*  _lo que se vendió_';
        $lines[] = '• '.$count.' '.($count === 1 ? 'venta' : 'ventas').' · *'.$this->money($shift->sales_generated_amount).'*';
        if ($cancelledCount > 0) {
            $lines[] = '• Canceladas: '.$cancelledCount.' ('.$this->money($cancelledAmount).') — no cuentan en el total';
        }
        $lines[] = '';

        // ── Dinero cobrado (lo que entró al cajón) ─────────────────
        $lines[] = '💰 *DINERO COBRADO EN EL TURNO*  _lo que entró al cajón_';
        $lines[] = '• Efectivo: '.$this->money($shift->total_cash);
        $lines[] = '• Tarjeta: '.$this->money($shift->total_card);
        $lines[] = '• Transferencia: '.$this->money($shift->total_transfer);
        $lines[] = '• *Total cobrado: '.$this->money($totalCollected).'*';
        if ($fromPrevious > 0.0) {
            $lines[] = '   ↳ De ventas del turno: '.$this->money($fromToday);
            $lines[] = '   ↳ Abonos a fiados anteriores: '.$this->money($fromPrevious);
        }
        $lines[] = '';

        // ── Arqueo de efectivo (con la cuenta explícita) ───────────
        $lines[] = '🧾 *ARQUEO DE EFECTIVO*';
        $lines[] = '• Fondo inicial: '.$this->money($shift->opening_amount);
        $lines[] = '• + Efectivo cobrado: '.$this->money($shift->total_cash);
        if ($withdrawalsTotal > 0.0) {
            $lines[] = '• − Retiros: '.$this->money($withdrawalsTotal);
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

        // ── Descuadres en otros métodos (solo si los hay) ─────────
        foreach ([
            ['tarjeta', $shift->declared_card, $shift->total_card, $shift->difference_card],
            ['transferencia', $shift->declared_transfer, $shift->total_transfer, $shift->difference_transfer],
        ] as [$name, $declared, $registered, $diff]) {
            if ($declared !== null && (float) $diff !== 0.0) {
                $lines[] = '';
                $lines[] = '⚠️ *Descuadre en '.$name.':* declarado '.$this->money($declared).' vs registrado '.$this->money($registered).' ('.$this->signedMoney($diff).')';
            }
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

    /**
     * Línea de veredicto que va arriba del todo: si la caja cuadró o si hay
     * faltante/sobrante de efectivo (lo que el dueño quiere ver de inmediato).
     */
    private function verdictLine(CashRegisterShift $shift): string
    {
        if ($shift->declared_amount === null) {
            return '📋 _Cierre sin conteo de efectivo declarado._';
        }

        $diff = (float) $shift->difference;
        $diffCard = (float) $shift->difference_card;
        $diffTransfer = (float) $shift->difference_transfer;

        if ($diff === 0.0 && $diffCard === 0.0 && $diffTransfer === 0.0) {
            return '✅ *Caja cuadrada* — sin diferencias.';
        }
        if ($diff < 0.0) {
            return '⚠️ *Faltante de '.$this->money(abs($diff)).' en efectivo.*';
        }
        if ($diff > 0.0) {
            return '⚠️ *Sobrante de '.$this->money($diff).' en efectivo.*';
        }

        return '⚠️ *El efectivo cuadra; hay un descuadre en otro método (ver abajo).*';
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
