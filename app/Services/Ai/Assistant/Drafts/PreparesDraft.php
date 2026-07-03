<?php

namespace App\Services\Ai\Assistant\Drafts;

use App\Models\User;
use App\Services\Ai\Assistant\AssistantTool;
use App\Services\Ai\Assistant\ToolResult;

/**
 * Marca una tool que PREPARA un borrador en lugar de ejecutar una lectura.
 *
 * Regla de seguridad: una PreparesDraft nunca crea el registro final. Su
 * `prepareDraft()` persiste una fila `assistant_drafts` (status=ready) y el
 * usuario confirma después con un botón de la UI (2ª petición HTTP). El
 * orquestador llama `prepareDraft()` en vez de `execute()` para estas tools.
 */
interface PreparesDraft extends AssistantTool
{
    /**
     * @param  array<string, mixed>  $params  parámetros ya validados por validate()
     */
    public function prepareDraft(User $user, array $params, ToolContext $context): ToolResult;
}
