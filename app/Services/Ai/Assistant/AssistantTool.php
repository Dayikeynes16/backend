<?php

namespace App\Services\Ai\Assistant;

use App\Models\User;

/**
 * Contrato de una herramienta del asistente. La IA jamás ejecuta nada
 * directo: solicita un Tool por nombre, y este orquesta autorización +
 * validación + ejecución en PHP.
 *
 * Ver docs/arquitectura/ia-asistente.md §"Arquitectura" y §"Principios inviolables".
 */
interface AssistantTool
{
    public function name(): string;

    /**
     * Descripción para OpenAI: cuándo conviene llamar a esta tool.
     * Debe ser corta y específica. La IA elige tools con base en esto.
     */
    public function description(): string;

    /**
     * JSON Schema de los parámetros. Debe declarar `additionalProperties: false`
     * y enumerar TODOS los campos esperados.
     *
     * @return array<string, mixed>
     */
    public function jsonSchema(): array;

    /**
     * `true` si la tool no muta estado (lectura). Las read-only se ejecutan
     * sin confirmación; las write devuelven un draft.
     */
    public function readOnly(): bool;

    /**
     * Roles que pueden invocar esta tool. El registro filtra antes de
     * exponerla al modelo, y el orquestador re-valida antes de ejecutar.
     *
     * @return list<string>
     */
    public function rolesAllowed(): array;

    /**
     * Autoriza al usuario para esta llamada concreta. Se ejecuta ANTES de
     * `validate()` y `execute()`. Si devuelve false, la tool se rechaza con
     * `denied` (no se ejecuta nada).
     *
     * @param  array<string, mixed>  $params
     */
    public function authorize(User $user, array $params): bool;

    /**
     * Valida y normaliza parámetros. Lanza ValidationException si algo está
     * mal. Devuelve el array final que recibirá `execute()`. Aquí es donde
     * los scopes (branch_id) se sobreescriben para admin-sucursal.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function validate(User $user, array $params): array;

    /**
     * Ejecuta la tool con parámetros ya validados.
     *
     * @param  array<string, mixed>  $params
     */
    public function execute(User $user, array $params): ToolResult;
}
