<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiCategoryDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class CategoryDraftController extends Controller
{
    public function __construct(
        private readonly AiCategoryDraftService $service,
    ) {}

    /**
     * POST /{tenant}/empresa/gastos/categorias/ia/borrador
     *
     * Recibe texto y/o audio. Llama a Whisper (si hay audio) y a GPT-4o,
     * devuelve una propuesta editable que el admin-empresa confirma vía
     * ExpenseCategoryController::storeFromAiDraft.
     */
    public function store(Request $request): JsonResponse
    {
        $tenant = app('tenant');
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
                'message' => 'Aporta un texto o una nota de voz describiendo la categoría.',
            ], 422);
        }

        try {
            $draft = $this->service->createDraft($tenant, $user, $text, $audio);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No pude analizar tu solicitud. Intenta de nuevo o crea la categoría manualmente.',
                'detail' => app()->hasDebugModeEnabled() ? $e->getMessage() : null,
            ], 502);
        }

        return response()->json([
            'draft_id' => $draft->id,
            'status' => $draft->status->value,
            'proposal' => $draft->parsed_proposal,
            'audio_transcription' => $draft->audio_transcription,
        ]);
    }
}
