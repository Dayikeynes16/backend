<?php

namespace App\Services\Ai\Assistant\Drafts;

use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use Illuminate\Http\UploadedFile;

/**
 * Contexto de ejecución de un turno del asistente que las tools de preparación
 * necesitan pero que NO viene del modelo: la sesión, el mensaje del usuario que
 * originó la llamada, y los archivos adjuntos del turno (p.ej. un recibo).
 *
 * Las read-tools no lo reciben; sólo las {@see PreparesDraft}.
 */
final class ToolContext
{
    /**
     * @param  array<int, UploadedFile>  $attachments
     */
    public function __construct(
        public readonly AiAssistantSession $session,
        public readonly AiAssistantMessage $userMessage,
        public readonly array $attachments = [],
    ) {}

    /**
     * @return array<int, UploadedFile>
     */
    public function images(): array
    {
        return array_values(array_filter(
            $this->attachments,
            fn (UploadedFile $f) => str_starts_with((string) $f->getMimeType(), 'image/'),
        ));
    }
}
