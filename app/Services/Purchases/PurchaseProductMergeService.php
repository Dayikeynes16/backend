<?php

namespace App\Services\Purchases;

/**
 * Fusiona fichas duplicadas de purchase_products en una canónica: reapunta
 * las líneas de compra, normaliza su concept y mueve el dato variable a la
 * nota de la línea, y da de baja (soft-delete) las absorbidas.
 *
 * La regla de normalización (buildNormalizedLine) es pura y unit-testeable.
 */
class PurchaseProductMergeService
{
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
