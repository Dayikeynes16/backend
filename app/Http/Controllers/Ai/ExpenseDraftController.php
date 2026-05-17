<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiExpenseDraftService;
use App\Services\ExpenseAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ExpenseDraftController extends Controller
{
    public function __construct(
        private readonly AiExpenseDraftService $service,
    ) {}

    /**
     * Crea un borrador de gasto a partir de texto y/o imágenes.
     * Llama a OpenAI sincrónicamente y devuelve la propuesta lista para el form.
     * NO crea un gasto — eso ocurre al confirmar en GastoController@store.
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
            'attachments' => 'nullable|array|max:'.config('ai.expenses.max_images', 5),
            'attachments.*' => [
                'file',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.(ExpenseAttachmentService::MAX_BYTES / 1024),
            ],
            'audio' => [
                'nullable',
                'file',
                // Whisper acepta webm/ogg/mp4/m4a/mp3/wav/mpga/oga. Usamos
                // mimes (por extensión) en lugar de mimetypes para tolerar
                // los blobs sin extensión coherente que produce MediaRecorder
                // en distintos navegadores. Whisper rechaza por su cuenta
                // cualquier formato inválido.
                'mimes:webm,ogg,oga,mp3,mpga,m4a,mp4,wav,flac,aac',
                'max:'.$maxAudioKb,
            ],
        ], [
            'attachments.max' => 'Máximo '.config('ai.expenses.max_images', 5).' imágenes por análisis.',
            'attachments.*.mimes' => 'Sólo imágenes (jpg, png, webp).',
            'attachments.*.mimetypes' => 'Tipo de imagen no permitido.',
            'attachments.*.max' => 'Cada imagen no puede superar 5 MB.',
            'audio.mimes' => 'Formato de audio no permitido.',
            'audio.max' => 'El audio no puede superar '.round($maxAudioKb / 1024).' MB.',
        ]);

        $text = $validated['input_text'] ?? null;
        $files = $request->file('attachments') ?? [];
        $audio = $request->file('audio');

        if (trim((string) $text) === '' && $files === [] && $audio === null) {
            return response()->json([
                'message' => 'Aporta al menos un texto, una imagen o un audio para analizar.',
            ], 422);
        }

        try {
            $draft = $this->service->createDraft($tenant, $user, $text, $files, $audio);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No pude analizar el gasto. Intenta de nuevo o captúralo manualmente.',
                'detail' => app()->hasDebugModeEnabled() ? $e->getMessage() : null,
            ], 502);
        }

        return response()->json([
            'draft_id' => $draft->id,
            'status' => $draft->status->value,
            'proposal' => $draft->parsed_proposal,
            'attachments' => collect($draft->attachment_paths ?? [])
                ->map(fn ($a, $idx) => [
                    'index' => $idx,
                    'original_name' => $a['original_name'] ?? null,
                    'mime_type' => $a['mime_type'] ?? null,
                    'size_bytes' => $a['size_bytes'] ?? null,
                ])->values(),
            'audio_transcription' => $draft->audio_transcription,
        ]);
    }
}
