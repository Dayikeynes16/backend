<?php

namespace App\Services\Purchases;

use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PurchaseFolioGenerator;
use App\Services\PurchasePaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Punto único de creación de compras. Extraído de HandlesPurchases para que la
 * captura manual (trait) y la confirmación de un borrador del asistente pasen por
 * la MISMA lógica: crear la Purchase + sus PurchaseItem (resolviendo/creando el
 * insumo por nombre), sembrar el saldo pendiente y auditar.
 *
 * Reglas de negocio preservadas: total = subtotal (sin impuestos), amount_paid=0
 * y amount_pending = subtotal al crear; el saldo lo mantiene PurchasePaymentService.
 */
final class PurchaseWriter
{
    public function __construct(
        private readonly PurchaseFolioGenerator $folios,
        private readonly PurchasePaymentService $payments,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Crea la compra completa: build + recalcular saldo + auditar.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $extra
     */
    public function create(Tenant $tenant, User $user, array $validated, int $branchId, array $extra = []): Purchase
    {
        $purchase = $this->buildPurchaseWithItems($tenant, $user, $validated, $branchId, $extra);
        $this->payments->recalculate($purchase);
        $this->audit->logCreated($purchase);

        return $purchase->fresh();
    }

    /**
     * Crea la Purchase y sus líneas dentro de una transacción, sin recalcular ni
     * auditar (eso lo hace el caller). Compartido con el trait HandlesPurchases.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $extra
     */
    public function buildPurchaseWithItems(Tenant $tenant, User $user, array $validated, int $branchId, array $extra = []): Purchase
    {
        return DB::transaction(function () use ($tenant, $user, $validated, $branchId, $extra) {
            $subtotal = 0.0;
            foreach ($validated['items'] as $line) {
                $subtotal += (float) $line['quantity'] * (float) $line['unit_price'];
            }
            $subtotal = round($subtotal, 2);

            $purchase = Purchase::create(array_merge([
                'tenant_id' => $tenant->id,
                'branch_id' => $branchId,
                'provider_id' => $validated['provider_id'],
                'folio' => $this->folios->nextFolio($tenant->id),
                'invoice_number' => $validated['invoice_number'] ?? null,
                'purchased_at' => CarbonImmutable::parse($validated['purchased_at']),
                'status' => PurchaseStatus::Received,
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'amount_paid' => 0,
                'amount_pending' => $subtotal,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $user->id,
            ], $extra));

            foreach ($validated['items'] as $line) {
                $lineSubtotal = round((float) $line['quantity'] * (float) $line['unit_price'], 2);
                $product = $this->resolvePurchaseProduct($tenant->id, $line['purchase_product_id'] ?? null, $line['concept'], $line['unit'], $user);
                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'purchase_product_id' => $product->id,
                    'concept' => $product->name,
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'unit_price' => $line['unit_price'],
                    'subtotal' => $lineSubtotal,
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            return $purchase;
        });
    }

    /**
     * Resuelve la línea a un producto de catálogo de compra (insumo): usa el id
     * si vino, si no busca por nombre (case-insensitive) en el tenant y, si no
     * existe, lo crea. El name se usa como snapshot del concepto.
     */
    public function resolvePurchaseProduct(int $tenantId, ?int $id, string $name, string $unit, User $user): PurchaseProduct
    {
        if ($id) {
            $found = PurchaseProduct::where('tenant_id', $tenantId)->whereKey($id)->first();
            if ($found) {
                return $found;
            }
        }

        $name = trim($name);
        $byName = PurchaseProduct::where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
        if ($byName) {
            return $byName;
        }

        return PurchaseProduct::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'unit' => $unit ?: 'kg',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
    }
}
