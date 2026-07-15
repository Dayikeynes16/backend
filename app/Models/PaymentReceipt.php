<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'payment_id', 'customer_payment_id', 'uploaded_by', 'original_name', 'path', 'mime_type', 'size_bytes'])]
class PaymentReceipt extends Model
{
    use BelongsToTenant;

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function customerPayment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
