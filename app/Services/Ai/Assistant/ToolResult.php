<?php

namespace App\Services\Ai\Assistant;

/**
 * Resultado tipado de un Tool. El frontend pinta una "data card" según `kind`
 * usando `data`. El campo `summary` es texto opcional para que el LLM lo
 * reescriba en lenguaje natural — siempre derivado de `data`, nunca inventado.
 */
final class ToolResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $params  parámetros validados ya aplicados (después de reescrituras)
     * @param  array<string, mixed>|null  $modelPayload  si se define, es lo que ve la IA en vez de `data` completo
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $data,
        public readonly ?string $summary = null,
        public readonly array $params = [],
        public readonly ?array $modelPayload = null,
    ) {}

    /**
     * Payload que se devuelve a OpenAI como respuesta de la tool. La IA solo
     * ve `kind` + `summary` + un subconjunto de `data` que el Tool eligió
     * exponer (por defecto todo, pero el Tool puede acotar con `modelPayload`
     * — p.ej. los borradores no re-inyectan toda la propuesta al modelo).
     *
     * @return array<string, mixed>
     */
    public function forModel(): array
    {
        if ($this->modelPayload !== null) {
            return $this->modelPayload;
        }

        return [
            'kind' => $this->kind,
            'summary' => $this->summary,
            'data' => $this->data,
        ];
    }
}
