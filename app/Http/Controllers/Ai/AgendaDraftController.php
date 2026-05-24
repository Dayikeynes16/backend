<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiAgendaDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class AgendaDraftController extends Controller
{
    public function __construct(
        private readonly AiAgendaDraftService $service,
    ) {}

    /**
     * Genera una PROPUESTA de ítem de agenda a partir de texto y/o audio.
     * Llama a OpenAI sincrónicamente y devuelve la propuesta lista para
     * pre-rellenar el AgendaItemModal. NO persiste nada — el ítem se crea al
     * confirmar el usuario en `AgendaController@store`.
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();

        $maxAudioKb = (int) (config('ai.expenses.max_audio_bytes', 10 * 1024 * 1024) / 1024);

        $validated = $request->validate([
            'input_text' => [
                'nullable', 'string',
                'max:'.config('ai.expenses.max_input_text_length', 2000),
            ],
            'audio' => [
                'nullable',
                'file',
                // Mismo set tolerante que gastos/compras: usamos mimes (por
                // extensión) para tolerar los blobs sin extensión coherente que
                // produce MediaRecorder en distintos navegadores. Whisper
                // rechaza por su cuenta cualquier formato inválido.
                'mimes:webm,ogg,oga,mp3,mpga,m4a,mp4,wav,flac,aac',
                'max:'.$maxAudioKb,
            ],
        ], [
            'audio.mimes' => 'Formato de audio no permitido.',
            'audio.max' => 'El audio no puede superar '.round($maxAudioKb / 1024).' MB.',
        ]);

        $text = $validated['input_text'] ?? null;
        $audio = $request->file('audio');

        if (trim((string) $text) === '' && $audio === null) {
            return response()->json([
                'message' => 'Dicta o escribe algo para que la IA arme el recordatorio.',
            ], 422);
        }

        try {
            $result = $this->service->draft($user, $text, $audio);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No pude armar el recordatorio. Intenta de nuevo o créalo manualmente.',
                'detail' => app()->hasDebugModeEnabled() ? $e->getMessage() : null,
            ], 502);
        }

        return response()->json([
            'proposal' => $result['proposal'],
            'transcription' => $result['transcription'],
        ]);
    }
}
