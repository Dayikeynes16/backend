<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\Assistant\AssistantTranscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Dictado de la báscula: recibe un audio y devuelve el texto.
 *
 * Existe aparte del endpoint del asistente porque aquél exige sesión web y la
 * báscula se autentica con `X-Api-Key`. La transcripción en sí es la misma:
 * `AssistantTranscriber` (Whisper, español), que no persiste el audio.
 *
 * El límite es **por API key**, no por sucursal: cada báscula tiene la suya, y
 * un equipo con un botón atascado no debe dejar sin dictado al de al lado.
 */
class TranscriptionController extends Controller
{
    public function store(Request $request, AssistantTranscriber $transcriber): JsonResponse
    {
        // `api_key_id` lo inyecta AuthenticateApiKey en el request.
        $limiterKey = 'scale-transcribe:api-key:'.$request->input('api_key_id');
        $perHour = (int) config('ai.scale.transcribe_per_hour', 120);

        if (RateLimiter::tooManyAttempts($limiterKey, $perHour)) {
            $retryAfter = RateLimiter::availableIn($limiterKey);

            return response()->json([
                'message' => 'Has excedido el límite de dictados por hora.',
            ], 429)->header('Retry-After', $retryAfter);
        }

        $maxAudioKb = (int) (config('ai.expenses.max_audio_bytes', 10 * 1024 * 1024) / 1024);

        $request->validate([
            'audio' => [
                'required',
                'file',
                // Mismo set tolerante que el resto de flujos de voz: los
                // grabadores producen extensiones inconsistentes y Whisper
                // rechaza por su cuenta lo que no entiende.
                'mimes:webm,ogg,oga,mp3,mpga,m4a,mp4,wav,flac,aac',
                'max:'.$maxAudioKb,
            ],
        ], [
            'audio.required' => 'Falta el audio.',
            'audio.mimes' => 'Formato de audio no permitido.',
            'audio.max' => 'El audio no puede superar '.round($maxAudioKb / 1024).' MB.',
        ]);

        RateLimiter::hit($limiterKey, 3600);

        try {
            $text = $transcriber->transcribe($request->file('audio'));
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No se pudo transcribir el audio.',
            ], 503);
        }

        return response()->json(['text' => trim($text)]);
    }
}
