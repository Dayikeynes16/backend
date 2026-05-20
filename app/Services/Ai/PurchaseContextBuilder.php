<?php

namespace App\Services\Ai;

use App\Enums\PaymentMethod;
use App\Enums\ProviderType;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Provider;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Construye el contexto enviado a la IA al capturar una compra.
 *
 * Reglas:
 * - Solo proveedores activos del tenant actual.
 * - Solo productos activos del tenant (con cost_price si tiene — la IA puede
 *   validar que el precio del ticket sea coherente).
 * - Para admin-sucursal: branches solo la suya.
 * - NUNCA enviar: compras previas, pagos, montos históricos, RFC, datos fiscales.
 */
class PurchaseContextBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(Tenant $tenant, User $user): array
    {
        return [
            'tenant' => ['slug' => $tenant->slug, 'name' => $tenant->name],
            'usuario' => [
                'rol' => $user->getRoleNames()->first(),
                'sucursal_id' => $user->branch_id,
            ],
            'fecha_actual' => now()->format('Y-m-d'),
            'timezone' => config('app.timezone'),
            'sucursales' => $this->visibleBranches($tenant, $user)
                ->map(fn (Branch $b) => ['id' => $b->id, 'nombre' => $b->name])
                ->values()->all(),
            'metodos_pago' => collect([PaymentMethod::Cash, PaymentMethod::Card, PaymentMethod::Transfer])
                ->map(fn (PaymentMethod $m) => ['value' => $m->value, 'label' => $m->label()])
                ->all(),
            'proveedores' => Provider::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->limit(80)
                ->get(['id', 'name', 'type', 'rfc'])
                ->map(fn (Provider $p) => [
                    'id' => $p->id,
                    'nombre' => $p->name,
                    'tipo' => $p->type instanceof ProviderType ? $p->type->value : $p->type,
                    'rfc' => $p->rfc,
                ])
                ->values()
                ->all(),
            'productos' => Product::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->limit(120)
                ->get(['id', 'name', 'unit_type', 'cost_price'])
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'nombre' => $p->name,
                    'unidad' => $p->unit_type,
                    'costo_actual' => $p->cost_price !== null ? (float) $p->cost_price : null,
                ])
                ->values()
                ->all(),
            'reglas' => [
                'Usa SIEMPRE un proveedor.id que exista en proveedores. Si no encuentras match razonable, devuelve proveedor.id=null y propon uno nuevo en sugerencia_nuevo_proveedor.',
                'Para cada línea, si el concepto coincide con un producto del catálogo (por nombre o similar), incluye su product_id. Si no hay match, product_id=null pero el concepto se conserva como texto.',
                'NUNCA inventes ids. Solo usa los que aparecen en proveedores y productos.',
                'Cantidades positivas con hasta 3 decimales. Unidad en kg, g, l, ml, pieza, caja, bulto o cabeza.',
                'Precio unitario en pesos mexicanos, hasta 4 decimales para precios por kilo finos.',
                'subtotal = quantity * unit_price; total = suma de subtotales. Si la factura muestra un total que no cuadra, marca la diferencia en alertas.',
                'Para admin-sucursal el sistema fuerza la sucursal; tú puedes omitir branch_id en la propuesta.',
                'Fechas: usa fecha_actual + timezone. Si la factura no tiene fecha, usa fecha_actual.',
                'Confianza "alta" requiere total, proveedor y al menos una línea claras. Si el monto no se lee, baja a media/baja.',
                'No incluyas datos sensibles (RFC del cliente, direcciones) en notas — solo lo relevante a la compra.',
            ],
        ];
    }

    private function visibleBranches(Tenant $tenant, User $user): Collection
    {
        $query = Branch::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->orderBy('name');

        if ($user->hasRole('admin-sucursal') && ! $user->hasRole('superadmin')) {
            $query->where('id', $user->branch_id);
        }

        return $query->get(['id', 'name']);
    }
}
