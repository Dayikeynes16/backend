<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Concerns\HandlesAssistantChat;
use App\Http\Controllers\Concerns\SynthesizesAssistantSpeech;
use App\Http\Controllers\Concerns\TranscribesAssistantAudio;
use App\Http\Controllers\Controller;
use App\Services\Ai\Assistant\AssistantOrchestrator;

class AsistenteController extends Controller
{
    use HandlesAssistantChat;
    use SynthesizesAssistantSpeech;
    use TranscribesAssistantAudio;

    public function __construct(private readonly AssistantOrchestrator $orchestrator) {}

    protected function inertiaPage(): string
    {
        return 'Empresa/Asistente';
    }

    protected function indexRouteName(): string
    {
        return 'empresa.asistente';
    }
}
