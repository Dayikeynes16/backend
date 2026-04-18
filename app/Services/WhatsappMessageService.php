<?php

namespace App\Services;

use App\Models\Sale;

class WhatsappMessageService
{
    private const MAX_TEXT_BYTES = 3500;

    public function buildOrderText(Sale $sale): string
    {
        $sale->loadMissing('items', 'branch', 'tenant');

        $lines = [];
        $lines[] = '*Nuevo pedido — '.$sale->folio.'*';
        $lines[] = $sale->tenant?->name ?? '';
        $lines[] = '';

        $lines[] = '*Cliente:* '.($sale->contact_name ?? '—');
        $lines[] = '*Teléfono:* '.($sale->contact_phone ?? '—');
        $lines[] = '';

        $lines[] = '*Productos:*';
        foreach ($sale->items as $item) {
            $qty = $this->formatQty((float) $item->quantity, $item->unit_type);
            $lines[] = "• {$qty} × {$item->product_name} — $".number_format((float) $item->subtotal, 2);
            if (! empty($item->notes)) {
                $lines[] = '   _Nota: '.trim($item->notes).'_';
            }
        }

        $lines[] = '';

        if ($sale->delivery_type === 'delivery') {
            $lines[] = '*Entrega a domicilio*';
            if (! empty($sale->delivery_address)) {
                $lines[] = 'Dirección: '.$sale->delivery_address;
            }
            if ($sale->delivery_lat !== null && $sale->delivery_lng !== null) {
                $mapsUrl = sprintf(
                    'https://maps.google.com/?q=%s,%s',
                    number_format((float) $sale->delivery_lat, 7, '.', ''),
                    number_format((float) $sale->delivery_lng, 7, '.', ''),
                );
                $lines[] = '📍 Ubicación exacta: '.$mapsUrl;
            }
            if ($sale->delivery_distance_km !== null) {
                $lines[] = 'Distancia: '.number_format((float) $sale->delivery_distance_km, 1).' km';
            }
            if ($sale->delivery_fee !== null) {
                $lines[] = 'Costo de envío: $'.number_format((float) $sale->delivery_fee, 2);
            }
        } else {
            $lines[] = '*Pasará por su pedido*';
        }

        $lines[] = '';
        $lines[] = '*Método de pago:* '.$this->formatPaymentMethod($sale->payment_method);
        $lines[] = '*Total: $'.number_format((float) $sale->total, 2).'*';

        if (! empty($sale->cart_note)) {
            $lines[] = '';
            $lines[] = '*Notas del pedido:* '.$sale->cart_note;
        }

        $text = implode("\n", array_filter($lines, fn ($l) => $l !== null));

        return $this->truncateIfNeeded($text, $sale);
    }

    public function buildUrl(string $phoneE164, string $text): string
    {
        $digits = PhoneNormalizer::digits($phoneE164);

        return 'https://wa.me/'.$digits.'?text='.rawurlencode($text);
    }

    private function formatQty(float $qty, ?string $unitType): string
    {
        return match ($unitType) {
            'kg' => number_format($qty, 3).' kg',
            'piece' => (int) $qty.' pz',
            'cut' => number_format($qty, 2).' pz',
            default => (string) $qty,
        };
    }

    private function formatPaymentMethod(?string $method): string
    {
        return match ($method) {
            'cash' => 'Efectivo',
            'card' => 'Tarjeta',
            'transfer' => 'Transferencia',
            default => $method ?? 'Por confirmar',
        };
    }

    private function truncateIfNeeded(string $text, Sale $sale): string
    {
        if (strlen($text) <= self::MAX_TEXT_BYTES) {
            return $text;
        }

        // Rebuild with shortened per-item notes, always keeping folio + total.
        $head = '*Nuevo pedido — '.$sale->folio."*\nCliente: ".($sale->contact_name ?? '—');
        $tail = '*Total: $'.number_format((float) $sale->total, 2).'*';

        $budget = self::MAX_TEXT_BYTES - strlen($head) - strlen($tail) - 100;
        $itemsText = '';
        foreach ($sale->items as $item) {
            $line = "• {$item->quantity} × {$item->product_name}\n";
            if (strlen($itemsText) + strlen($line) > $budget) {
                $itemsText .= "• ... (pedido truncado, ver detalles en sistema)\n";
                break;
            }
            $itemsText .= $line;
        }

        return $head."\n\n".$itemsText."\n".$tail;
    }
}
