<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proveedor por defecto
    |--------------------------------------------------------------------------
    */

    'provider' => env('AI_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI
    |--------------------------------------------------------------------------
    */

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Flujo: registrar gasto con IA
    |--------------------------------------------------------------------------
    */

    'expenses' => [
        // Modelo a usar. GPT-4o soporta visión y JSON estructurado nativo.
        'model' => env('AI_EXPENSES_MODEL', 'gpt-4o'),

        // Máximo de imágenes que el usuario puede mandar en un solo draft.
        // Mismo límite que adjuntos del gasto (ExpenseAttachmentService::MAX_PER_EXPENSE).
        'max_images' => 5,

        // Máximo del texto libre que aporta el usuario.
        'max_input_text_length' => 2000,

        // Temperature: baja para forzar respuestas más deterministas.
        'temperature' => (float) env('AI_EXPENSES_TEMPERATURE', 0.1),

        // Horas que un draft sin confirmar puede vivir antes de que el job
        // de limpieza borre archivos y lo marque como expired.
        'draft_ttl_hours' => (int) env('AI_DRAFT_TTL_HOURS', 24),

        // Fase 2 — transcripción de notas de voz.
        'transcription_model' => env('AI_TRANSCRIPTION_MODEL', 'whisper-1'),
        'transcription_language' => env('AI_TRANSCRIPTION_LANGUAGE', 'es'),
        'max_audio_bytes' => (int) env('AI_MAX_AUDIO_BYTES', 10 * 1024 * 1024), // 10 MB
        'max_audio_seconds' => (int) env('AI_MAX_AUDIO_SECONDS', 90),
    ],

];
