<?php

namespace App\Services\Ai\Assistant\Drafts;

use App\Enums\AiDraftStatus;
use App\Enums\AssistantDraftType;
use App\Models\AssistantDraft;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Persistencia compartida de los borradores del asistente: creación en estado
 * pending, almacenamiento de archivos en disco privado, transiciones de estado
 * y purga de archivos. No sabe nada de dominio (gasto/compra/…) — eso vive en
 * cada tool de preparación y en cada confirmador.
 */
class AssistantDraftService
{
    /**
     * Crea el borrador en estado `pending` y guarda los archivos adjuntos del
     * turno en `tenants/{t}/assistant_drafts/{id}/`. La extracción/propuesta se
     * agrega después con {@see markReady()}.
     *
     * @param  array<int, UploadedFile>  $images
     * @param  array<string, mixed>  $originalInput
     */
    public function create(
        AssistantDraftType $type,
        Tenant $tenant,
        User $user,
        ToolContext $context,
        array $originalInput = [],
        array $images = [],
    ): AssistantDraft {
        $draft = AssistantDraft::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'session_id' => $context->session->id,
            'message_id' => $context->userMessage->id,
            'type' => $type->value,
            'status' => AiDraftStatus::Pending->value,
            'original_input' => $originalInput ?: null,
            'expires_at' => now()->addHours($this->ttlHours()),
        ]);

        $stored = $this->storeImages($tenant, $draft, $images);
        if ($stored !== []) {
            $draft->update(['attachment_paths' => $stored]);
        }

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $telemetry  ai_provider/ai_model/prompt_tokens/completion_tokens/latency_ms/raw_response
     */
    public function markReady(AssistantDraft $draft, array $payload, array $telemetry = []): void
    {
        $draft->update(array_merge([
            'status' => AiDraftStatus::Ready->value,
            'payload' => $payload,
        ], array_intersect_key($telemetry, array_flip([
            'ai_provider', 'ai_model', 'prompt_tokens', 'completion_tokens', 'latency_ms', 'raw_response',
        ]))));
    }

    public function markFailed(AssistantDraft $draft, string $error): void
    {
        $draft->update([
            'status' => AiDraftStatus::Failed->value,
            'error_message' => mb_substr($error, 0, 1000),
        ]);
    }

    /**
     * Marca el borrador como consumido y lo enlaza al registro real creado.
     */
    public function markConsumed(AssistantDraft $draft, Model $result): void
    {
        $draft->update([
            'status' => AiDraftStatus::Consumed->value,
            'result_type' => $result->getMorphClass(),
            'result_id' => $result->getKey(),
            'confirmed_at' => now(),
        ]);
    }

    public function markCancelled(AssistantDraft $draft): void
    {
        $this->purgeFiles($draft);
        $draft->update(['status' => AiDraftStatus::Cancelled->value]);
    }

    /**
     * Borra los archivos físicos del borrador del disco privado.
     */
    public function purgeFiles(AssistantDraft $draft): void
    {
        $storage = Storage::disk($this->disk());
        foreach ($draft->attachment_paths ?? [] as $entry) {
            if (isset($entry['path'])) {
                $storage->delete($entry['path']);
            }
        }
    }

    public function ttlHours(): int
    {
        return (int) config('ai.assistant.draft_ttl_hours', 6);
    }

    public function disk(): string
    {
        return (string) config('expenses.disk', 'local');
    }

    /**
     * @param  array<int, UploadedFile>  $images
     * @return array<int, array<string, mixed>>
     */
    private function storeImages(Tenant $tenant, AssistantDraft $draft, array $images): array
    {
        $disk = $this->disk();
        $directory = "tenants/{$tenant->id}/assistant_drafts/{$draft->id}";
        $stored = [];

        foreach ($images as $image) {
            if (! $image instanceof UploadedFile) {
                continue;
            }
            $ext = strtolower((string) $image->getClientOriginalExtension()) ?: 'bin';
            $path = $image->storeAs($directory, (string) Str::uuid().'.'.$ext, [
                'disk' => $disk,
                'visibility' => 'private',
            ]);
            if (! $path) {
                continue;
            }
            $stored[] = [
                'path' => $path,
                'original_name' => $image->getClientOriginalName(),
                'mime_type' => $image->getMimeType(),
                'size_bytes' => $image->getSize(),
            ];
        }

        return $stored;
    }
}
