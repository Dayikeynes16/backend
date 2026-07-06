<?php

namespace App\Http\Controllers\Asistente;

use App\Http\Controllers\Concerns\HandlesAssistantChat;
use App\Http\Controllers\Concerns\TranscribesAssistantAudio;
use App\Http\Controllers\Controller;
use App\Services\Ai\Assistant\AssistantOrchestrator;

/**
 * Mini-app del asistente (/{tenant}/asistente): experiencia móvil a pantalla
 * completa compartida por admin-empresa y admin-sucursal (D1: sin cajero).
 * El alcance por rol/sucursal lo resuelven las tools y los confirmers
 * server-side, exactamente igual que en las páginas clásicas.
 */
class AssistantAppController extends Controller
{
    use HandlesAssistantChat;
    use TranscribesAssistantAudio;

    public function __construct(private readonly AssistantOrchestrator $orchestrator) {}

    protected function inertiaPage(): string
    {
        return 'Asistente/App';
    }

    protected function indexRouteName(): string
    {
        return 'asistente.index';
    }
}
