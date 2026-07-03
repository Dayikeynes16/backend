<?php

namespace App\Models;

use App\Enums\AiDraftStatus;
use App\Enums\AssistantDraftType;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Borrador general originado por el asistente conversacional. Persiste una
 * propuesta estructurada que el usuario confirma (o cancela) desde la UI.
 * Nunca es el registro final: el registro real vive en `result_type/result_id`
 * una vez confirmado.
 */
#[Fillable([
    'tenant_id', 'branch_id', 'user_id', 'session_id', 'message_id',
    'type', 'status',
    'payload', 'original_input', 'attachment_paths',
    'ai_provider', 'ai_model', 'prompt_tokens', 'completion_tokens',
    'cost_cents', 'latency_ms', 'raw_response', 'error_message',
    'result_type', 'result_id', 'confirmed_at', 'expires_at',
])]
class AssistantDraft extends Model
{
    use BelongsToTenant;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiAssistantSession::class, 'session_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AiAssistantMessage::class, 'message_id');
    }

    public function result(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPending(): bool
    {
        return $this->status === AiDraftStatus::Pending;
    }

    public function isReady(): bool
    {
        return $this->status === AiDraftStatus::Ready;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    protected function casts(): array
    {
        return [
            'type' => AssistantDraftType::class,
            'status' => AiDraftStatus::class,
            'payload' => 'array',
            'original_input' => 'array',
            'attachment_paths' => 'array',
            'raw_response' => 'array',
            'confirmed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
