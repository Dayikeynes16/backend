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
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $data,
        public readonly ?string $summary = null,
        public readonly array $params = [],
    ) {}

    /**
     * Payload que se devuelve a OpenAI como respuesta de la tool. La IA solo
     * ve `kind` + `summary` + un subconjunto de `data` que el Tool eligió
     * exponer (por defecto todo, pero el Tool puede ocultar campos crudos).
     *
     * @return array<string, mixed>
     */
    public function forModel(): array
    {
        return [
            'kind' => $this->kind,
            'summary' => $this->summary,
            'data' => $this->data,
        ];
    }
}
