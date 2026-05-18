<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'session_id', 'tenant_id', 'user_id', 'role', 'content',
    'tool_name', 'tool_params', 'tool_result', 'tool_status',
    'ai_model', 'prompt_tokens', 'completion_tokens', 'cached_tokens',
    'cost_cents', 'latency_ms',
    'error_code', 'error_message',
])]
class AiAssistantMessage extends Model
{
    use BelongsToTenant;

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiAssistantSession::class, 'session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'tool_params' => 'array',
            'tool_result' => 'array',
        ];
    }
}
