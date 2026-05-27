<?php

namespace App\Models;

use App\Enums\PurchaseStatus;
use App\Models\Concerns\RecordsHistory;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id', 'branch_id', 'cash_register_shift_id', 'provider_id',
    'folio', 'invoice_number', 'purchased_at', 'status',
    'subtotal', 'total', 'amount_paid', 'amount_pending',
    'notes',
    'created_by', 'cancelled_by', 'cancelled_at', 'cancel_reason',
])]
class Purchase extends Model
{
    use BelongsToTenant, RecordsHistory, SoftDeletes;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashRegisterShift(): BelongsTo
    {
        return $this->belongsTo(CashRegisterShift::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PurchaseAttachment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ProviderPayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    protected function casts(): array
    {
        return [
            'status' => PurchaseStatus::class,
            'purchased_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'amount_pending' => 'decimal:2',
        ];
    }

    /**
     * Estado de pago DERIVADO (no se almacena): pending / partial (abonada) /
     * paid (pagada) / cancelled, calculado a partir de amount_paid vs total.
     *
     * Única fuente de verdad reutilizada por la serialización de compras y por
     * el detalle de proveedor. El listado de Compras filtra el mismo umbral en
     * SQL (HandlesPurchases::applyIndexFilters), que debe mantenerse alineado.
     */
    public function paymentStatus(): string
    {
        if ($this->status === PurchaseStatus::Cancelled) {
            return 'cancelled';
        }

        $paid = (float) $this->amount_paid;
        $total = (float) $this->total;

        if ($paid <= 0) {
            return 'pending';
        }

        if ($paid >= $total) {
            return 'paid';
        }

        return 'partial';
    }
}
