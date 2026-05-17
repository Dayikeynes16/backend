<?php

namespace App\Models;

use App\Enums\AiDraftStatus;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'user_id', 'status',
    'input_text', 'audio_path', 'audio_transcription',
    'ai_provider', 'ai_model', 'prompt_tokens', 'completion_tokens',
    'cost_cents', 'latency_ms',
    'raw_response', 'parsed_proposal', 'error_message',
    'expense_category_id', 'consumed_at',
])]
class AiCategoryDraft extends Model
{
    use BelongsToTenant;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    protected function casts(): array
    {
        return [
            'status' => AiDraftStatus::class,
            'raw_response' => 'array',
            'parsed_proposal' => 'array',
            'consumed_at' => 'datetime',
        ];
    }
}
