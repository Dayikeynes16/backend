<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'user_id', 'title', 'message_count', 'last_message_at'])]
class AiAssistantSession extends Model
{
    use BelongsToTenant;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiAssistantMessage::class, 'session_id');
    }

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }
}
