<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Concerns\HandlesAssistantChat;
use App\Http\Controllers\Concerns\SynthesizesAssistantSpeech;
use App\Http\Controllers\Concerns\TranscribesAssistantAudio;
use App\Http\Controllers\Controller;
use App\Services\Ai\Assistant\AssistantOrchestrator;

/**
 * Asistente para admin-sucursal. La lógica es idéntica al de Empresa; lo que
 * limita lo que puede consultar es el `branch_id` que cada Tool reescribe en
 * `AbstractAssistantTool::resolveBranch()` cuando el usuario tiene rol
 * admin-sucursal. Aquí sólo cambia el layout/página de Inertia.
 */
class AsistenteController extends Controller
{
    use HandlesAssistantChat;
    use SynthesizesAssistantSpeech;
    use TranscribesAssistantAudio;

    public function __construct(private readonly AssistantOrchestrator $orchestrator) {}

    protected function inertiaPage(): string
    {
        return 'Sucursal/Asistente';
    }

    protected function indexRouteName(): string
    {
        return 'sucursal.asistente';
    }
}
