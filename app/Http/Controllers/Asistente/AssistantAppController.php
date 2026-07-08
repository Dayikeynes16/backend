<?php

namespace App\Http\Controllers\Asistente;

use App\Http\Controllers\Concerns\HandlesAssistantChat;
use App\Http\Controllers\Concerns\SynthesizesAssistantSpeech;
use App\Http\Controllers\Concerns\TranscribesAssistantAudio;
use App\Http\Controllers\Controller;
use App\Models\AiAssistantSession;
use App\Services\Ai\Assistant\AssistantOrchestrator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Response;

/**
 * Mini-app del asistente (/{tenant}/asistente): experiencia móvil a pantalla
 * completa compartida por admin-empresa y admin-sucursal (D1: sin cajero).
 * El alcance por rol/sucursal lo resuelven las tools y los confirmers
 * server-side, exactamente igual que en las páginas clásicas.
 */
class AssistantAppController extends Controller
{
    use HandlesAssistantChat {
        HandlesAssistantChat::index as private renderChatIndex;
    }
    use SynthesizesAssistantSpeech;
    use TranscribesAssistantAudio;

    public function __construct(private readonly AssistantOrchestrator $orchestrator) {}

    /**
     * La mini-app garantiza una sesión activa: el modo simple (F4) envía
     * prompts con un solo tap y no debe fallar con "crea una sesión primero".
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();

        if (! AiAssistantSession::query()->where('user_id', $user->id)->exists()) {
            AiAssistantSession::create([
                'tenant_id' => app('tenant')->id,
                'user_id' => $user->id,
                'title' => null,
                'message_count' => 0,
            ]);
        }

        return $this->renderChatIndex($request);
    }

    protected function inertiaPage(): string
    {
        return 'Asistente/App';
    }

    protected function indexRouteName(): string
    {
        return 'asistente.index';
    }
}
