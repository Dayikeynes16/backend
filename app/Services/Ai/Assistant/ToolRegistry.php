<?php

namespace App\Services\Ai\Assistant;

use App\Models\User;
use RuntimeException;

/**
 * Whitelist de tools disponibles. Construir desde el container con todos
 * los tools registrados; el orquestador pregunta `forUser()` para obtener
 * sólo las permitidas para el rol del usuario.
 *
 * No se exponen tools al modelo que el usuario no pueda invocar — si la IA
 * no sabe que existe, no puede pedirla.
 */
final class ToolRegistry
{
    /**
     * @param  list<AssistantTool>  $tools
     */
    public function __construct(private readonly array $tools) {}

    /**
     * @return list<AssistantTool>
     */
    public function forUser(User $user): array
    {
        return array_values(array_filter(
            $this->tools,
            fn (AssistantTool $tool) => $this->userMatchesRole($user, $tool),
        ));
    }

    public function get(string $name): AssistantTool
    {
        foreach ($this->tools as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }
        throw new RuntimeException("Tool desconocida: {$name}");
    }

    /**
     * Serializa la lista de tools en el formato que espera OpenAI
     * (`type: function`).
     *
     * @param  list<AssistantTool>  $tools
     * @return list<array<string, mixed>>
     */
    public static function toOpenAiSchema(array $tools): array
    {
        return array_map(fn (AssistantTool $t) => [
            'type' => 'function',
            'function' => [
                'name' => $t->name(),
                'description' => $t->description(),
                'parameters' => $t->jsonSchema(),
            ],
        ], $tools);
    }

    private function userMatchesRole(User $user, AssistantTool $tool): bool
    {
        foreach ($tool->rolesAllowed() as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }
}
