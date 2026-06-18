<?php

namespace App\Services;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Único punto que escribe el historial de cambios (audit_logs) y calcula los
 * diffs campo por campo (incl. líneas de compra). Inmutable: solo inserta.
 */
class AuditLogger
{
    public function log(Model $auditable, AuditEvent $event, ?array $changes = null, ?int $userId = null): void
    {
        AuditLog::create([
            'tenant_id' => $auditable->tenant_id,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getKey(),
            'user_id' => $userId ?? Auth::id(),
            'event' => $event->value,
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }

    public function logCreated(Model $m): void
    {
        $this->log($m, AuditEvent::Created);
    }

    public function logCancelled(Model $m, string $reason): void
    {
        $this->log($m, AuditEvent::Cancelled, ['reason' => $reason]);
    }

    public function logPaymentAdded(Model $m, float $amount, string $method, ?int $userId = null): void
    {
        $this->log($m, AuditEvent::PaymentAdded, ['amount' => round($amount, 2), 'method' => $method], $userId);
    }

    public function logPaymentCancelled(Model $m, float $amount, string $method, string $reason, ?int $userId = null): void
    {
        $this->log($m, AuditEvent::PaymentCancelled, ['amount' => round($amount, 2), 'method' => $method, 'reason' => $reason], $userId);
    }

    /**
     * Registra una edición solo si hubo cambios reales entre dos snapshots.
     *
     * @param  array{fields: array<string, mixed>, items: array<int, array<string, mixed>>}  $before
     * @param  array{fields: array<string, mixed>, items: array<int, array<string, mixed>>}  $after
     */
    public function logUpdatedIfChanged(Model $m, array $before, array $after): void
    {
        $changes = $this->diff($before, $after);
        if ($changes !== []) {
            $this->log($m, AuditEvent::Updated, $changes);
        }
    }

    /**
     * @return array{fields: array<string, mixed>, items: array<int, array<string, mixed>>}
     */
    public function purchaseSnapshot(Purchase $p): array
    {
        return [
            'fields' => [
                'provider' => $p->provider?->name,
                'invoice_number' => $p->invoice_number,
                'purchased_at' => $p->purchased_at?->toDateString(),
                'total' => (float) $p->total,
                'notes' => $p->notes,
            ],
            'items' => $p->items->map(fn ($i) => [
                'concept' => $i->concept,
                'quantity' => (float) $i->quantity,
                'unit' => $i->unit,
                'unit_price' => (float) $i->unit_price,
            ])->values()->all(),
        ];
    }

    /**
     * @return array{fields: array<string, mixed>, items: array<int, array<string, mixed>>}
     */
    public function expenseSnapshot(Expense $e): array
    {
        return [
            'fields' => [
                'concept' => $e->concept,
                'amount' => (float) $e->amount,
                'subcategory' => $e->subcategory?->name,
                'payment_method' => $e->payment_method?->value,
                'expense_at' => $e->expense_at?->toDateString(),
                'description' => $e->description,
                'branch' => $e->branch?->name,
            ],
            'items' => [],
        ];
    }

    /**
     * Snapshot legible de un producto de compra. Los valores se guardan ya
     * formateados (label de categoría, estado en español) para que el
     * historial se muestre sin mapeos adicionales en el frontend.
     *
     * @return array{fields: array<string, mixed>, items: array<int, array<string, mixed>>}
     */
    public function purchaseProductSnapshot(PurchaseProduct $p): array
    {
        return [
            'fields' => [
                'name' => $p->name,
                'unit' => $p->unit,
                'category' => $p->category?->name,
                'status' => $p->status === 'active' ? 'Activo' : 'Inactivo',
            ],
            'items' => [],
        ];
    }

    /**
     * @param  array{fields: array<string, mixed>, items: array<int, array<string, mixed>>}  $before
     * @param  array{fields: array<string, mixed>, items: array<int, array<string, mixed>>}  $after
     * @return array<string, mixed>
     */
    public function diff(array $before, array $after): array
    {
        $changes = [];

        $fieldDiff = [];
        foreach ($after['fields'] as $key => $newVal) {
            $oldVal = $before['fields'][$key] ?? null;
            if ($this->normalize($oldVal) !== $this->normalize($newVal)) {
                $fieldDiff[$key] = [$oldVal, $newVal];
            }
        }
        if ($fieldDiff !== []) {
            $changes['fields'] = $fieldDiff;
        }

        $itemDiff = $this->diffItems($before['items'] ?? [], $after['items'] ?? []);
        if ($itemDiff !== []) {
            $changes['items'] = $itemDiff;
        }

        return $changes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $before
     * @param  array<int, array<string, mixed>>  $after
     * @return array<string, mixed>
     */
    private function diffItems(array $before, array $after): array
    {
        $key = fn (array $i) => mb_strtolower(trim((string) $i['concept']));

        $beforeByKey = [];
        foreach ($before as $i) {
            $beforeByKey[$key($i)] = $i;
        }
        $afterByKey = [];
        foreach ($after as $i) {
            $afterByKey[$key($i)] = $i;
        }

        $added = $removed = $changed = [];

        foreach ($afterByKey as $k => $i) {
            if (! isset($beforeByKey[$k])) {
                $added[] = $i;

                continue;
            }
            $b = $beforeByKey[$k];
            if ((float) $b['quantity'] !== (float) $i['quantity']
                || (float) $b['unit_price'] !== (float) $i['unit_price']
                || $b['unit'] !== $i['unit']) {
                $changed[] = [
                    'concept' => $i['concept'],
                    'from' => ['quantity' => (float) $b['quantity'], 'unit_price' => (float) $b['unit_price']],
                    'to' => ['quantity' => (float) $i['quantity'], 'unit_price' => (float) $i['unit_price']],
                ];
            }
        }
        foreach ($beforeByKey as $k => $i) {
            if (! isset($afterByKey[$k])) {
                $removed[] = $i;
            }
        }

        $out = [];
        if ($added !== []) {
            $out['added'] = $added;
        }
        if ($removed !== []) {
            $out['removed'] = $removed;
        }
        if ($changed !== []) {
            $out['changed'] = $changed;
        }

        return $out;
    }

    private function normalize(mixed $v): mixed
    {
        if (is_int($v) || is_float($v)) {
            return (string) round((float) $v, 2);
        }
        if ($v === null) {
            return null;
        }

        return (string) $v;
    }
}
