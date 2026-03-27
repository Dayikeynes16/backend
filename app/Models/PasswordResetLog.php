<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['admin_id', 'target_user_id', 'method', 'admin_role', 'ip_address'])]
class PasswordResetLog extends Model
{
    public $timestamps = false;

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
