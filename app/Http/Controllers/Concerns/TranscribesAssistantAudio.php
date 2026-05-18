<?php

namespace App\Http\Controllers\Concerns;

use App\Services\Ai\Assistant\AssistantTranscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Acción compartida por Empresa\AsistenteController y Sucursal\AsistenteController:
 * recibe un blob de audio del navegador, lo transcribe con Whisper y devuelve
 * el texto para que el frontend lo coloque en el cuadro de entrada. No persiste
 * el audio — el usuario revisa el texto y manda el mensaje normal.
 */
trait TranscribesAssistantAudio
{
    public function transcribe(Request $request, AssistantTranscriber $transcriber): JsonResponse
    {
        $tenant = app('tenant');
        $user = $request->user();

        // Rate limit independiente del envío de mensajes: las transcripciones
        // pesan en Whisper aparte (~$0.006/min) y un usuario podría grabar
        // sin enviar. Compartimos límite por hora con el resto del asistente.
        $userKey = 'ai-assistant-transcribe:user:'.$user->id;
        $perHour = (int) config('ai.assistant.rate_limit_per_user_per_hour', 60);
        if (RateLimiter::tooManyAttempts($userKey, $perHour)) {
            return response()->json([
                'message' => 'Has excedido el límite de dictados por hora.',
            ], 429);
        }

        $maxAudioKb = (int) (config('ai.expenses.max_audio_bytes', 10 * 1024 * 1024) / 1024);

        $request->validate([
            'audio' => [
                'required',
                'file',
                // Mismo set tolerante que el flujo de gastos: MediaRecorder en
                // distintos navegadores produce blobs con extensiones inconsistentes;
                // Whisper rechaza por su cuenta los formatos inválidos.
                'mimes:webm,ogg,oga,mp3,mpga,m4a,mp4,wav,flac,aac',
                'max:'.$maxAudioKb,
            ],
        ], [
            'audio.required' => 'Falta el audio.',
            'audio.mimes' => 'Formato de audio no permitido.',
            'audio.max' => 'El audio no puede superar '.round($maxAudioKb / 1024).' MB.',
        ]);

        RateLimiter::hit($userKey, 3600);

        try {
            $text = $transcriber->transcribe($request->file('audio'));
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No pude transcribir el audio. Intenta de nuevo o escríbelo.',
                'detail' => app()->hasDebugModeEnabled() ? $e->getMessage() : null,
            ], 502);
        }

        return response()->json([
            'text' => $text,
        ]);
    }
}
