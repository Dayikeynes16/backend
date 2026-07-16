<?php

namespace App\Services\Purchases;

use App\Enums\AuditEvent;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Fusiona fichas duplicadas de purchase_products en una canónica: reapunta
 * las líneas de compra, normaliza su concept y mueve el dato variable a la
 * nota de la línea, y da de baja (soft-delete) las absorbidas.
 *
 * La regla de normalización (buildNormalizedLine) es pura y unit-testeable.
 */
class PurchaseProductMergeService
{
    public function __construct(private AuditLogger $auditor) {}

    /**
     * Reapunta las líneas de las fichas absorbidas al canónico, normaliza su
     * texto y da de baja las absorbidas. Todo en una transacción.
     *
     * @param  array<int, int>  $absorbedIds
     * @return array{absorbed_count: int, relinked_items_count: int}
     */
    public function merge(PurchaseProduct $canonical, array $absorbedIds): array
    {
        return DB::transaction(function () use ($canonical, $absorbedIds) {
            $canonical = PurchaseProduct::whereKey($canonical->id)->lockForUpdate()->firstOrFail();

            $absorbed = PurchaseProduct::whereIn('id', $absorbedIds)
                ->where('id', '!=', $canonical->id)
                ->lockForUpdate()
                ->get();

            // Los nombres ORIGINALES se capturan antes de renombrar para que la
            // auditoría quede legible (ver rename más abajo).
            $originalNames = $absorbed->pluck('name')->all();

            $relinked = 0;
            foreach ($absorbed as $product) {
                $items = PurchaseItem::where('purchase_product_id', $product->id)->lockForUpdate()->get();
                foreach ($items as $item) {
                    $normalized = $this->buildNormalizedLine($canonical->name, $item->concept, $item->notes);
                    $item->update([
                        'purchase_product_id' => $canonical->id,
                        'concept' => $normalized['concept'],
                        'notes' => $normalized['notes'],
                    ]);
                    $relinked++;
                }

                // Libera el nombre antes del soft-delete: el índice único
                // (tenant_id, name) incluye filas borradas, así que re-capturar
                // una compra con este nombre (p.ej. un número de res que se
                // repite) rompería con QueryException si no lo renombramos.
                $product->update([
                    'name' => mb_substr($product->name, 0, 140).' (fusionado #'.$product->id.')',
                ]);
                $product->delete();
            }

            if ($absorbed->isNotEmpty()) {
                $this->auditor->log($canonical, AuditEvent::Merged, [
                    'absorbed' => $originalNames,
                    'items_relinked' => $relinked,
                ]);
            }

            return ['absorbed_count' => $absorbed->count(), 'relinked_items_count' => $relinked];
        });
    }

    /**
     * Calcula el impacto sin ejecutar nada.
     *
     * @param  array<int, int>  $absorbedIds
     * @return array{absorbed_count: int, items_count: int, unit_mismatch: bool}
     */
    public function preview(PurchaseProduct $canonical, array $absorbedIds): array
    {
        $absorbed = PurchaseProduct::whereIn('id', $absorbedIds)
            ->where('id', '!=', $canonical->id)
            ->get();

        $itemsCount = PurchaseItem::whereIn('purchase_product_id', $absorbed->pluck('id'))->count();
        $units = $absorbed->pluck('unit')->push($canonical->unit)->unique();

        return [
            'absorbed_count' => $absorbed->count(),
            'items_count' => $itemsCount,
            'unit_mismatch' => $units->count() > 1,
        ];
    }

    /**
     * Calcula el concept normalizado y la nota resultante de una línea al
     * reapuntarla a la ficha canónica (spec §3.1).
     *
     * @return array{concept: string, notes: ?string}
     */
    public function buildNormalizedLine(string $canonicalName, string $oldConcept, ?string $oldNotes): array
    {
        $canonical = trim($canonicalName);
        $old = trim($oldConcept);
        $lowerCanon = mb_strtolower($canonical);
        $lowerOld = mb_strtolower($old);

        if ($lowerOld === $lowerCanon) {
            $rest = '';
        } elseif (
            str_starts_with($lowerOld, $lowerCanon)
            && preg_match('/^[^\p{L}\p{N}]/u', mb_substr($old, mb_strlen($canonical))) === 1
        ) {
            // El texto tras el nombre canónico empieza con un separador → es un sufijo.
            $tail = mb_substr($old, mb_strlen($canonical));
            $rest = trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', $tail));
        } else {
            // No es prefijo limpio (o es un nombre distinto) → preservar completo.
            $rest = $old;
        }

        $notes = $oldNotes;
        if ($rest !== '') {
            if ($oldNotes === null || trim($oldNotes) === '') {
                $notes = $rest;
            } else {
                $notes = mb_substr($rest.' · '.$oldNotes, 0, 500);
            }
        }

        return ['concept' => $canonical, 'notes' => $notes];
    }
}
