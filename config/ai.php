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
    | ElevenLabs (TTS para asistente)
    |--------------------------------------------------------------------------
    |
    | Provee voz al asistente conversacional. Se llama desde el frontend con
    | el ID de un mensaje del asistente; el backend valida ownership, sintetiza
    | con ElevenLabs y devuelve audio MP3 binary que el navegador reproduce.
    */
    'elevenlabs' => [
        'api_key' => env('ELEVENLABS_API_KEY'),
        'base_url' => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io/v1'),
        'timeout' => (int) env('ELEVENLABS_TIMEOUT', 30),

        // Voice ID por defecto. Lo puedes cambiar por tenant a futuro.
        'voice_id' => env('ELEVENLABS_VOICE_ID', 'nTkjq09AuYgsNR8E4sDe'),
        'model' => env('ELEVENLABS_MODEL', 'eleven_turbo_v2_5'),

        // Formato del audio devuelto. mp3_44100_128 es universal en navegadores.
        'output_format' => env('ELEVENLABS_OUTPUT_FORMAT', 'mp3_44100_128'),

        // Límite duro de caracteres por síntesis para acotar costos. Si una
        // respuesta del asistente supera esto, se trunca antes de mandar.
        'max_chars' => (int) env('ELEVENLABS_MAX_CHARS', 1200),
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
        // TTS de las respuestas (OpenAI). Voz activa en la UI desde 2026-07-07.
        'tts_model' => env('AI_ASSISTANT_TTS_MODEL', 'gpt-4o-mini-tts'),
        'tts_voice' => env('AI_ASSISTANT_TTS_VOICE', 'nova'),
        'tts_instructions' => env('AI_ASSISTANT_TTS_INSTRUCTIONS', 'Habla en español mexicano, con tono cálido, natural y breve, como un asistente del negocio.'),
        'tts_max_chars' => 1200,

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

    /*
    |--------------------------------------------------------------------------
    | Asistente conversacional (F0 + F1)
    |--------------------------------------------------------------------------
    |
    | Ver docs/arquitectura/ia-asistente.md para el contexto completo. El
    | modelo barato (gpt-4o-mini) actúa como router de Tools; gpt-4o queda
    | reservado para turnos con visión más adelante.
    */
    'assistant' => [
        'model' => env('AI_ASSISTANT_MODEL', 'gpt-4o-mini'),
        'model_vision' => env('AI_ASSISTANT_MODEL_VISION', 'gpt-4o'),
        'temperature' => (float) env('AI_ASSISTANT_TEMPERATURE', 0),

        // TTS de las respuestas (OpenAI). Voz activa en la UI desde 2026-07-07.
        'tts_model' => env('AI_ASSISTANT_TTS_MODEL', 'gpt-4o-mini-tts'),
        'tts_voice' => env('AI_ASSISTANT_TTS_VOICE', 'nova'),
        'tts_instructions' => env('AI_ASSISTANT_TTS_INSTRUCTIONS', 'Habla en español mexicano, con tono cálido, natural y breve, como un asistente del negocio.'),
        'tts_max_chars' => 1200,

        'max_input_text_length' => 2000,
        'max_history_turns' => 8,
        'max_tool_iterations' => 5,

        // TTL de los borradores creados por el asistente (assistant_drafts).
        // Vencido esto, el job ai:expire-drafts borra archivos y marca expired.
        'draft_ttl_hours' => (int) env('AI_ASSISTANT_DRAFT_TTL_HOURS', 6),

        // Rate limits. Quien rebasa recibe 429 y un mensaje amable.
        'rate_limit_per_user_per_hour' => (int) env('AI_ASSISTANT_RATE_USER_HOUR', 60),
        'rate_limit_per_tenant_per_day' => (int) env('AI_ASSISTANT_RATE_TENANT_DAY', 1000),

        // Presupuesto mensual de IA por tenant (USD cents) cuando el tenant
        // no tiene un valor explícito en `ai_monthly_budget_cents`.
        'default_monthly_budget_cents' => (int) env('AI_ASSISTANT_DEFAULT_BUDGET_CENTS', 5000),

        // Precios aproximados de gpt-4o-mini (USD por 1M tokens). Se usan sólo
        // para estimar el costo de cada mensaje; el cobro real lo hace OpenAI.
        // Si cambia la lista de precios oficial, actualizar aquí.
        'prices' => [
            'gpt-4o-mini' => ['prompt' => 0.15, 'completion' => 0.60, 'cached' => 0.075],
            'gpt-4o' => ['prompt' => 2.50, 'completion' => 10.00, 'cached' => 1.25],
        ],
    ],

];
