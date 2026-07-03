<?php

namespace App\Services\Ai\Assistant\Drafts;

use App\Models\User;
use App\Services\Ai\Assistant\AbstractAssistantTool;
use App\Services\Ai\Assistant\ToolResult;
use LogicException;

/**
 * Base de las tools de escritura del asistente. Son de PREPARACIÓN: dejan un
 * borrador listo, nunca ejecutan la escritura definitiva.
 *
 * `execute()` está deshabilitado a propósito — el orquestador debe llamar
 * `prepareDraft()` (vía {@see PreparesDraft}). Si algo llama `execute()` es un
 * bug de integración, no un camino silencioso a una escritura.
 */
abstract class AbstractPrepareDraftTool extends AbstractAssistantTool implements PreparesDraft
{
    public function readOnly(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function execute(User $user, array $params): ToolResult
    {
        throw new LogicException(static::class.' prepara borradores; usa prepareDraft().');
    }
}
