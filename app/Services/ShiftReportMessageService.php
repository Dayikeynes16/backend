<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Models\CashRegisterShift;
use App\Models\Sale;

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

        $lines = [];

        $lines[] = '*CORTE DE CAJA*';
        if ($shift->tenant && $shift->branch) {
            $lines[] = '_'.$shift->tenant->name.' — '.$shift->branch->name.'_';
        } elseif ($shift->branch) {
            $lines[] = '_'.$shift->branch->name.'_';
        }
        $lines[] = '';

        $lines[] = 'Fecha: '.($shift->closed_at?->format('d/m/Y H:i') ?? '—');
        $lines[] = 'Cajero: '.($shift->user?->name ?? '—');
        $lines[] = 'Turno: '.($shift->opened_at?->format('d/m/Y H:i') ?? '—').' -> '.($shift->closed_at?->format('d/m/Y H:i') ?? '—');
        $lines[] = '';

        $lines[] = '━━━━━━━━━━━━━━━━━━';
        $lines[] = '*VENTAS*';
        $lines[] = '• Total vendido: '.$this->money($shift->total_sales);
        $lines[] = '• N.° de ventas: '.(int) $shift->sale_count;
        if ($cancelledCount > 0) {
            $lines[] = '• Canceladas: '.$cancelledCount.' ('.$this->money($cancelledAmount).')';
        }
        $lines[] = '';

        $lines[] = '*MÉTODOS DE PAGO*';
        $lines[] = '• Efectivo: '.$this->money($shift->total_cash);
        $lines[] = '• Tarjeta: '.$this->money($shift->total_card);
        $lines[] = '• Transferencia: '.$this->money($shift->total_transfer);
        $lines[] = '';

        $lines[] = '*EFECTIVO*';
        $lines[] = '• Fondo inicial: '.$this->money($shift->opening_amount);
        if ($withdrawalsTotal > 0) {
            $lines[] = '• Retiros: '.$this->money($withdrawalsTotal);
        }
        $lines[] = '• Esperado: '.$this->money($shift->expected_amount);
        if ($shift->declared_amount !== null) {
            $lines[] = '• Declarado: '.$this->money($shift->declared_amount);
            $lines[] = '• *Diferencia: '.$this->signedMoney($shift->difference).'* '.$this->diffMarker($shift->difference);
        } else {
            $lines[] = '• Declarado: no aplica';
        }
        $lines[] = '';

        $totalDiff = (float) $shift->difference + (float) $shift->difference_card + (float) $shift->difference_transfer;

        $lines[] = '*RESUMEN*';
        if ($this->allDifferencesZero($shift)) {
            $lines[] = 'Sin diferencias ✅';
        } else {
            $lines[] = '• Efectivo: '.$this->diffOrNoAplica($shift->declared_amount, $shift->difference);
            $lines[] = '• Tarjeta: '.$this->diffOrNoAplica($shift->declared_card, $shift->difference_card);
            $lines[] = '• Transferencia: '.$this->diffOrNoAplica($shift->declared_transfer, $shift->difference_transfer);
            $lines[] = '• Total: '.$this->signedMoney($totalDiff);
        }

        if (! empty($shift->notes)) {
            $lines[] = '';
            $lines[] = '_Notas: '.trim($shift->notes).'_';
        }

        $lines[] = '';
        $lines[] = '━━━━━━━━━━━━━━━━━━';
        $lines[] = 'Reporte generado desde Carniceria SaaS';

        $text = implode("\n", $lines);

        return $this->truncateIfNeeded($text);
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
        if ($n === 0.0) {
            return '✅';
        }
        if ($n > 0) {
            return '⚠️ sobrante';
        }

        return '⚠️ faltante';
    }

    private function diffOrNoAplica($declared, $difference): string
    {
        if ($declared === null) {
            return 'no aplica';
        }

        return $this->signedMoney($difference);
    }

    private function allDifferencesZero(CashRegisterShift $shift): bool
    {
        return (float) $shift->difference === 0.0
            && (float) $shift->difference_card === 0.0
            && (float) $shift->difference_transfer === 0.0;
    }

    private function truncateIfNeeded(string $text): string
    {
        if (strlen($text) <= self::MAX_TEXT_BYTES) {
            return $text;
        }

        return substr($text, 0, self::MAX_TEXT_BYTES - 3).'...';
    }
}
