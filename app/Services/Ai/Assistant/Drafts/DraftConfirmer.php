<?php

namespace App\Services\Ai\Assistant\Drafts;

use App\Enums\AssistantDraftType;
use App\Models\AssistantDraft;
use App\Models\User;

/**
 * Confirma un borrador de un tipo concreto: aporta las reglas de re-validación
 * del payload editado y ejecuta la escritura reutilizando la lógica de dominio
 * existente. Se invoca DENTRO de la transacción que ya bloqueó el borrador.
 */
interface DraftConfirmer
{
    public function type(): AssistantDraftType;

    /**
     * Autoriza a este usuario a confirmar ESTE borrador, más allá del rol de la
     * ruta (p.ej. toggles de sucursal). Se comprueba antes de validar/ejecutar.
     */
    public function authorize(User $user, AssistantDraft $draft): bool;

    /**
     * Reglas para re-validar el payload editado en el momento de confirmar.
     * Se re-valida TODO server-side; nunca se confía en el payload guardado.
     *
     * @return array<string, mixed>
     */
    public function rules(User $user): array;

    /**
     * @return array<string, string>
     */
    public function messages(): array;

    /**
     * @param  AssistantDraft  $draft  ya bloqueado (lockForUpdate) y verificado como Ready
     * @param  array<string, mixed>  $validated
     */
    public function confirm(AssistantDraft $draft, User $user, array $validated): DraftConfirmationResult;
}
