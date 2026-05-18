<?php

namespace App\Http\Controllers\Concerns;

use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use App\Services\Ai\Assistant\AssistantSpeechSynthesizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Acción compartida por Empresa\AsistenteController y Sucursal\AsistenteController:
 * recibe un mensaje del asistente del usuario actual, sintetiza su contenido
 * con ElevenLabs y devuelve audio MP3 binary que el navegador reproduce.
 *
 * El frontend MANDA EL ID DEL MENSAJE, no texto libre — eso evita que alguien
 * pueda hacer al backend gastar la API key sintetizando texto arbitrario.
 */
trait SynthesizesAssistantSpeech
{
    public function speak(Request $request, AiAssistantSession $session, AiAssistantMessage $message, AssistantSpeechSynthesizer $synthesizer): Response
    {
        $tenant = app('tenant');
        $user = $request->user();

        if ($session->user_id !== $user->id || $session->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Sesión no encontrada.'], 404);
        }

        if ($message->session_id !== $session->id) {
            return response()->json(['message' => 'Mensaje no pertenece a la sesión.'], 404);
        }

        if ($message->role !== 'assistant' || trim((string) $message->content) === '') {
            return response()->json(['message' => 'Sólo se pueden reproducir respuestas del asistente con contenido.'], 422);
        }

        // Rate limit por usuario para acotar el costo de ElevenLabs.
        $userKey = 'ai-assistant-speak:user:'.$user->id;
        $perHour = (int) config('ai.assistant.rate_limit_per_user_per_hour', 60);
        if (RateLimiter::tooManyAttempts($userKey, $perHour)) {
            return response()->json([
                'message' => 'Has excedido el límite de reproducciones por hora.',
            ], 429);
        }

        RateLimiter::hit($userKey, 3600);

        try {
            $audioBytes = $synthesizer->synthesize($message->content);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No pude generar la voz. Intenta de nuevo.',
                'detail' => app()->hasDebugModeEnabled() ? $e->getMessage() : null,
            ], 502);
        }

        return new StreamedResponse(function () use ($audioBytes) {
            echo $audioBytes;
        }, 200, [
            'Content-Type' => 'audio/mpeg',
            'Content-Length' => (string) strlen($audioBytes),
            'Cache-Control' => 'private, max-age=600',
        ]);
    }
}
