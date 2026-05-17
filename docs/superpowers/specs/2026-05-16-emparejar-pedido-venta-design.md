# Emparejar pedido web con venta de báscula — diseño

**Fecha**: 2026-05-16
**Autor**: Brainstorming sesión (Sebastián + Claude)
**Estado**: Aprobado para implementación
**Módulo afectado**: Ventas / Workbench / Pedidos web
**Spec relacionado**: `docs/superpowers/specs/2026-04-17-pedidos-web-design.md`

---

## Problema

El spec original de pedidos web (2026-04-17) decidió que el `Sale` con `origin='web'` + `status='pending'` **era la venta final**: el admin la acepta y el cajero la cobra como cualquier venta.

En la práctica esto no encaja con la operación real de una carnicería:

- El cliente pide cantidades aproximadas en el menú web ("2 kg de arrachera", "1 kg de costilla").
- El carnicero corta y pesa en la báscula → el peso real **siempre** difiere del pedido (2.15 kg, 0.92 kg, etc.).
- La app de báscula ya tiene su flujo establecido: el carnicero crea ventas vía `POST /api/v1/sales` con peso real y la venta entra al Workbench como `origin='api'`.

Forzar al carnicero a "editar el pedido web" rompe su flujo. Forzar al cajero a editar manualmente cada item con el peso real lo convierte en intermediario lento. La solución correcta es **mantener los dos flujos separados** y emparejarlos.

## Objetivo

1. El pedido web sirve como **comanda** (lista de compra) que se imprime desde Workbench.
2. El carnicero trabaja igual que siempre en la báscula — no sabe ni necesita saber que hay un pedido web atrás.
3. El cajero/admin hace el **emparejamiento manual** en Workbench cuando ve entrar la venta de báscula que corresponde.
4. El emparejamiento copia datos de cliente y delivery del pedido web a la venta de báscula (la real), suma el costo de envío, y marca el pedido web como `Fulfilled`.
5. Se preservan ambas ventas: pedido = intención del cliente, venta de báscula = transacción real con pesos reales.
6. Reportes y métricas cuentan **solo la venta de báscula** para evitar doble conteo.

## Decisiones tomadas (brainstorming)

| # | Decisión | Alternativas descartadas |
|---|----------|--------------------------|
| 1 | Dos `Sale` vinculadas por `linked_order_id` (FK self-referente en `sales`) | Una sola Sale que muta; tabla `web_orders` separada |
| 2 | Nuevo `SaleStatus::Fulfilled` (terminal) para el pedido web emparejado | Reusar `Completed` (requeriría filtros en cada reporte); reusar `Cancelled` con motivo (semánticamente raro) |
| 3 | Emparejamiento **manual** en Workbench por cajero/admin; sin auto-matching | Auto-match por timestamp/items; obligar QR/folio escaneado |
| 4 | Carnicero no ve pedidos web — la app de báscula no cambia | Extender API de báscula con endpoint "process order" |
| 5 | Copiar `delivery_fee` y datos delivery del pedido web a la venta de báscula al vincular; recalcular `total` | Crear sale_item "Envío" (requiere producto-fantasma); preguntar al cajero S/N (paso extra) |
| 6 | Desvincular permitido mientras la venta de báscula esté `Active` y sin `payments` | Siempre permitido (complica recálculo de pagos); irreversible (sin margen para errores) |
| 7 | Relación 1:1 — un pedido web ↔ una venta de báscula | N:1 (varios tickets para un pedido) — fuera de alcance v1 |
| 8 | Reusar impresión de ticket existente desde Workbench (no nuevo flujo) | Imprimir automático al aceptar; PDF descargable separado |
| 9 | Métricas excluyen ventas `web (Pending/Fulfilled)` para no doble-contar | Sumar ambas con flag de doble conteo |

## Arquitectura

### 1. Cambios al esquema

**Tabla `sales` — nueva columna:**

```sql
ALTER TABLE sales ADD COLUMN linked_order_id bigint NULL
    REFERENCES sales(id) ON DELETE SET NULL;
CREATE INDEX sales_linked_order_id_idx ON sales(linked_order_id)
    WHERE linked_order_id IS NOT NULL;
```

- Solo la venta de báscula (la "real") tiene `linked_order_id` apuntando al pedido web.
- El pedido web nunca tiene `linked_order_id` propio (queda `NULL`).
- `ON DELETE SET NULL` para no romper la venta real si por algún motivo se borra el pedido web (no debería pasar, los pedidos web no se borran, pero defensa en profundidad).
- Índice parcial para queries rápidas tipo "dame todas las ventas que cumplen pedidos web".

### 2. Cambios al enum `SaleStatus`

`app/Enums/SaleStatus.php`:

```php
case Fulfilled = 'fulfilled';  // nuevo
```

Label/color:
- `label()`: `'Cumplida'`
- `color()`: `'emerald'` (verde distinto del `Completed` que es `'green'`, para diferenciar visualmente cobrada vs cumplida-vía-emparejamiento)

Transiciones:
- `Pending::allowedTransitions()` agrega `Fulfilled` → `[Active, Cancelled, Fulfilled]`
- `Fulfilled::allowedTransitions()` retorna `[Pending]` (solo cuando se desvincula vuelve a Pending; no hay otras transiciones desde Fulfilled)

### 3. Fillable y casts

`app/Models/Sale.php`:

```php
protected $fillable = [
    // ... existentes
    'linked_order_id',
];

// Relaciones
public function linkedOrder(): BelongsTo
{
    return $this->belongsTo(Sale::class, 'linked_order_id');
}

public function fulfilledBy(): HasOne
{
    return $this->hasOne(Sale::class, 'linked_order_id');
}
```

`linkedOrder()` = "el pedido web que esta venta cumple" (desde la venta de báscula).
`fulfilledBy()` = "la venta de báscula que cumplió este pedido" (desde el pedido web).

### 4. Servicio `OrderLinkService`

`app/Services/OrderLinkService.php` — orquesta vincular/desvincular en transacción.

```php
public function link(Sale $scaleSale, Sale $webOrder): void
{
    // Validaciones
    $this->assertSameTenantAndBranch($scaleSale, $webOrder);
    $this->assertScaleSaleEligible($scaleSale);   // origin != 'web', status == Active, !linked_order_id
    $this->assertWebOrderEligible($webOrder);     // origin == 'web', status == Pending

    DB::transaction(function () use ($scaleSale, $webOrder) {
        // 1. Link
        $scaleSale->linked_order_id = $webOrder->id;

        // 2. Copiar datos del cliente si la venta de báscula no los tiene
        $scaleSale->customer_id    ??= $webOrder->customer_id;
        $scaleSale->contact_name   ??= $webOrder->contact_name;
        $scaleSale->contact_phone  ??= $webOrder->contact_phone;

        // 3. Copiar datos de delivery (sobrescribe — el pedido web es la fuente de verdad)
        $scaleSale->delivery_type        = $webOrder->delivery_type;
        $scaleSale->delivery_address     = $webOrder->delivery_address;
        $scaleSale->delivery_lat         = $webOrder->delivery_lat;
        $scaleSale->delivery_lng         = $webOrder->delivery_lng;
        $scaleSale->delivery_distance_km = $webOrder->delivery_distance_km;
        $scaleSale->delivery_fee         = $webOrder->delivery_fee;

        // 4. Recalcular total sumando delivery_fee
        $itemsSubtotal       = $scaleSale->items()->sum('subtotal');
        $scaleSale->total    = $itemsSubtotal + ($webOrder->delivery_fee ?? 0);
        $scaleSale->amount_pending = $scaleSale->total - $scaleSale->amount_paid;
        $scaleSale->save();

        // 5. Marcar pedido web como Fulfilled
        $webOrder->status = SaleStatus::Fulfilled;
        $webOrder->save();

        // 6. Broadcast ambas (Workbench refresca)
        SaleUpdated::dispatch($scaleSale->fresh());
        SaleUpdated::dispatch($webOrder->fresh());
    });
}

public function unlink(Sale $scaleSale): void
{
    $this->assertScaleSaleLinked($scaleSale);             // tiene linked_order_id
    $this->assertScaleSaleStillEditable($scaleSale);      // status == Active y payments()->count() == 0

    DB::transaction(function () use ($scaleSale) {
        $webOrder = $scaleSale->linkedOrder;

        // 1. Limpiar campos copiados
        $scaleSale->linked_order_id      = null;
        $scaleSale->delivery_type        = null;
        $scaleSale->delivery_address     = null;
        $scaleSale->delivery_lat         = null;
        $scaleSale->delivery_lng         = null;
        $scaleSale->delivery_distance_km = null;
        $scaleSale->delivery_fee         = null;
        // NO limpiar contact_name/contact_phone/customer_id — pudieron haberse confirmado por el cajero

        // 2. Recalcular total sin delivery
        $scaleSale->total = $scaleSale->items()->sum('subtotal');
        $scaleSale->amount_pending = $scaleSale->total - $scaleSale->amount_paid;
        $scaleSale->save();

        // 3. Volver pedido web a Pending
        $webOrder->status = SaleStatus::Pending;
        $webOrder->save();

        // 4. Broadcast
        SaleUpdated::dispatch($scaleSale->fresh());
        SaleUpdated::dispatch($webOrder->fresh());
    });
}
```

**Excepciones específicas** (en `app/Exceptions/OrderLink/`):
- `IneligibleScaleSaleException` — la venta de báscula no califica (es web, ya vinculada, no Active)
- `IneligibleWebOrderException` — el pedido web no califica (no es web, no Pending)
- `CrossBranchLinkException` — sucursales distintas
- `LockedScaleSaleException` — venta ya cobrada, no se puede desvincular

### 5. Endpoints

Bajo el grupo existente `sucursal/mesa-de-trabajo/ventas/{sale}`:

```php
// En routes/web.php, dentro del grupo 'sucursal.':
Route::post(
    'mesa-de-trabajo/ventas/{sale}/vincular-pedido',
    [WorkbenchController::class, 'linkOrder']
)->name('workbench.link-order');

Route::delete(
    'mesa-de-trabajo/ventas/{sale}/vincular-pedido',
    [WorkbenchController::class, 'unlinkOrder']
)->name('workbench.unlink-order');

// Listado de pedidos web pendientes vinculables (para llenar el modal)
Route::get(
    'mesa-de-trabajo/pedidos-pendientes',
    [WorkbenchController::class, 'pendingWebOrders']
)->name('workbench.pending-web-orders');
```

**`WorkbenchController::linkOrder(Request, Sale $sale)`**:
- Valida `{order_id: required|integer|exists:sales,id}`
- Resuelve `$webOrder = Sale::findOrFail($request->order_id)` (con global scope tenant ya aplicado)
- Llama `OrderLinkService::link($sale, $webOrder)`
- Respuesta Inertia: `back()->with('success', "Vinculado al pedido {$webOrder->folio}")`. El broadcast actualiza el Workbench.

**`WorkbenchController::unlinkOrder(Sale $sale)`**:
- Llama `OrderLinkService::unlink($sale)`
- Respuesta Inertia: `back()->with('success', 'Pedido desvinculado')`

**`WorkbenchController::pendingWebOrders()`**:
- Devuelve JSON con pedidos `origin='web' AND status='pending'` de la sucursal del usuario actual
- Ordenados `created_at DESC`
- Limit 50
- Cada item incluye: `id`, `folio`, `created_at`, `contact_name`, `contact_phone`, `delivery_type`, `delivery_address`, `delivery_fee`, `total`, `items_count`, `items_preview` (3 primeros)
- Usado por el modal de vinculación

### 6. Validación de elegibilidad

Helpers privados en `OrderLinkService`:

```php
private function assertSameTenantAndBranch(Sale $a, Sale $b): void
{
    if ($a->tenant_id !== $b->tenant_id || $a->branch_id !== $b->branch_id) {
        throw new CrossBranchLinkException();
    }
}

private function assertScaleSaleEligible(Sale $sale): void
{
    if ($sale->origin === 'web') {
        throw new IneligibleScaleSaleException('La venta a vincular no puede ser un pedido web');
    }
    if ($sale->status !== SaleStatus::Active) {
        throw new IneligibleScaleSaleException('La venta debe estar activa');
    }
    if ($sale->linked_order_id !== null) {
        throw new IneligibleScaleSaleException('La venta ya está vinculada a otro pedido');
    }
}

private function assertWebOrderEligible(Sale $order): void
{
    if ($order->origin !== 'web') {
        throw new IneligibleWebOrderException('El pedido a vincular debe ser web');
    }
    if ($order->status !== SaleStatus::Pending) {
        throw new IneligibleWebOrderException('El pedido debe estar pendiente');
    }
}

private function assertScaleSaleStillEditable(Sale $sale): void
{
    if ($sale->status !== SaleStatus::Active) {
        throw new LockedScaleSaleException('Solo se desvincula mientras la venta esté activa');
    }
    if ($sale->payments()->exists()) {
        throw new LockedScaleSaleException('No se puede desvincular: ya hay pagos registrados');
    }
}
```

Handler global de excepciones traduce a `back()->with('error', $exception->getMessage())` para flashear el toast.

### 7. SaleResource extendido

`app/Http/Resources/SaleResource.php` agrega:

```php
'linked_order_id' => $this->linked_order_id,
'linked_order'    => $this->whenLoaded('linkedOrder', fn () => [
    'id'    => $this->linkedOrder->id,
    'folio' => $this->linkedOrder->folio,
]),
'fulfilled_by'    => $this->whenLoaded('fulfilledBy', fn () => $this->fulfilledBy ? [
    'id'    => $this->fulfilledBy->id,
    'folio' => $this->fulfilledBy->folio,
] : null),
```

Eager-load en `WorkbenchController::index()`:

```php
$sales = Sale::with(['items', 'payments', 'customer', 'linkedOrder', 'fulfilledBy'])
    ->where('branch_id', $branch->id)
    ->whereIn('status', [SaleStatus::Active, SaleStatus::Pending])
    ->get();
```

### 8. Frontend — Workbench (`resources/js/Pages/Sucursal/Workbench.vue`)

Cambios concretos al componente existente:

**Badges en la tarjeta de la venta:**

```vue
<!-- Venta de báscula vinculada -->
<span v-if="sale.linked_order_id"
      class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 ring-1 ring-inset ring-emerald-600/30">
  ✓ Cumple {{ sale.linked_order.folio }}
</span>

<!-- Pedido web ya cumplido (en vista histórica/filtro) -->
<span v-if="sale.origin === 'web' && sale.status === 'fulfilled'"
      class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 ring-1 ring-inset ring-emerald-600/30">
  Cumplido por {{ sale.fulfilled_by?.folio ?? 'venta' }}
</span>
```

**Botones de acción en el panel de detalle (cuando `selected` es venta de báscula):**

```vue
<!-- Mostrar solo si NO es web, está Active y no tiene link -->
<button v-if="canLinkOrder(selected)"
        @click="openLinkOrderModal"
        class="rounded-md bg-orange-600 px-3 py-2 text-sm font-semibold text-white hover:bg-orange-700">
  🔗 Vincular pedido web
</button>

<!-- Mostrar si está vinculada y aún editable -->
<button v-if="canUnlinkOrder(selected)"
        @click="confirmUnlink"
        class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
  Desvincular pedido
</button>
```

Computed helpers:

```js
const canLinkOrder = (sale) =>
  sale.origin !== 'web' &&
  sale.status === 'active' &&
  !sale.linked_order_id &&
  pendingWebOrdersCount.value > 0;

const canUnlinkOrder = (sale) =>
  sale.linked_order_id &&
  sale.status === 'active' &&
  (sale.payments?.length ?? 0) === 0;
```

**Modal nuevo: `LinkOrderModal.vue` (`resources/js/Components/Workbench/LinkOrderModal.vue`):**

- Header: "Vincular venta {scaleSale.folio} con pedido web"
- Lista scrolleable de pedidos pendientes (fetch a `/sucursal/mesa-de-trabajo/pedidos-pendientes`)
- Cada item: folio, hora (relativa), cliente, teléfono (clickeable `tel:`), delivery/pickup, total, preview de items (3 + "y N más")
- Selección por radio o tap
- Footer: preview del desglose final:
  ```
  Productos:  $850.00
  Envío:       $70.00
  ──────────
  Total:      $920.00
  ```
- Botón "Confirmar vinculación" → `POST workbench.link-order` con `{order_id}`

**Persistencia del selected:** después de vincular, el `selected.id` sigue siendo la venta de báscula (es la real). El Reverb actualiza el state via `SaleUpdated`.

### 9. Caja (cajero)

`resources/js/Pages/Caja/Workbench.vue` — mismos badges y botón "Vincular" (los cajeros también pueden emparejar). Reusa el `LinkOrderModal`.

### 10. Métricas y reportes

Las queries de métricas existentes en `app/Services/Metrics/` deben **excluir** `status IN ('pending','fulfilled') AND origin='web'` para no doble-contar.

Patrón de filtro recomendado:

```php
$query->where(function ($q) {
    $q->where('origin', '!=', 'web')
      ->orWhereNotIn('status', [SaleStatus::Pending->value, SaleStatus::Fulfilled->value]);
});
```

O más limpio: scope reutilizable en `Sale`:

```php
public function scopeAccountable($query)
{
    return $query->where(function ($q) {
        $q->where('origin', '!=', 'web')
          ->orWhereNotIn('status', [
              SaleStatus::Pending->value,
              SaleStatus::Fulfilled->value,
          ]);
    });
}
```

Aplicar en: `DailySummaryService`, `ShiftTotalsCalculator`, `RecalculateClosedShifts`, todos los `Metrics/*`.

**Métrica nueva opcional (no bloqueante):** `WebOrdersFulfilledToday` — count de pedidos `Fulfilled` con `updated_at` en el día actual. Útil para dashboard de sucursal pero no es parte del scope crítico.

### 11. Historial de ventas (`SaleHistoryController`)

Mostrar pedidos `Fulfilled` igual que `Completed` en el listado por defecto, con label diferenciado. Permitir filtro por status incluyendo el nuevo valor.

## Seguridad

- **Tenant scope:** `Sale` ya usa `BelongsToTenant`. Al buscar `$webOrder` por id se respeta el scope global → imposible vincular pedidos de otro tenant.
- **Branch enforcement:** `assertSameTenantAndBranch` valida explícitamente además del scope, para que ni un admin-empresa con acceso multi-sucursal pueda vincular cruzado.
- **Race condition:** dos cajeros vinculando la misma venta de báscula al mismo pedido web simultáneamente → el segundo falla porque `linked_order_id` ya no es null. La transacción protege la atomicidad.
- **Validación enum:** Laravel valida el body `{order_id}` con `exists:sales,id` — siempre dentro del scope tenant.
- **Roles:** los endpoints viven bajo `role:admin-sucursal|superadmin` (igual que el resto del Workbench). Los cajeros (`role:cajero`) acceden vía el grupo `caja.` con sus propias rutas espejo.

## Testing

**Feature** (`tests/Feature/Sucursal/`):
- `LinkOrderTest::link_copies_delivery_and_marks_fulfilled`
- `LinkOrderTest::link_sums_delivery_fee_to_total`
- `LinkOrderTest::link_copies_customer_when_scale_sale_has_none`
- `LinkOrderTest::link_rejects_web_sale_as_scale_sale`
- `LinkOrderTest::link_rejects_already_linked_scale_sale`
- `LinkOrderTest::link_rejects_non_pending_web_order`
- `LinkOrderTest::link_rejects_cross_branch`
- `LinkOrderTest::link_rejects_cross_tenant`
- `LinkOrderTest::link_broadcasts_sale_updated_for_both`
- `UnlinkOrderTest::unlink_reverts_web_order_to_pending`
- `UnlinkOrderTest::unlink_clears_delivery_and_recalculates_total`
- `UnlinkOrderTest::unlink_keeps_customer_assignment`
- `UnlinkOrderTest::unlink_rejects_if_payments_exist`
- `UnlinkOrderTest::unlink_rejects_if_sale_not_active`
- `PendingWebOrdersTest::lists_only_pending_web_orders_of_branch`
- `PendingWebOrdersTest::respects_tenant_isolation`

**Unit** (`tests/Unit/`):
- `SaleStatusTest::fulfilled_transitions` — `Pending → Fulfilled`, `Fulfilled → Pending` permitidos
- `OrderLinkServiceTest::*` — happy paths + cada excepción

**Feature de métricas existentes** — añadir aserciones de que `accountable()` excluye `web+pending` y `web+fulfilled`.

## Rollout

1. Migración `linked_order_id` (nullable, zero downtime)
2. Enum `SaleStatus::Fulfilled` + transiciones
3. Fillable + relaciones en `Sale`
4. `OrderLinkService` + excepciones
5. `SaleResource` extendido + eager-load en Workbench
6. Endpoints `WorkbenchController::linkOrder/unlinkOrder/pendingWebOrders`
7. `LinkOrderModal.vue` + cambios en `Sucursal/Workbench.vue`
8. Mismos cambios espejo en `Caja/Workbench.vue`
9. Scope `accountable()` y refactor de métricas/turnos para usarlo
10. Tests feature + unit
11. Activar en staging con tenant piloto; smoke con un pedido web real

Cada paso es independientemente desplegable. El feature funciona end-to-end después del paso 7 (paso 8 lo extiende al cajero; paso 9 corrige métricas).

## Fuera de alcance (v2+)

- **Auto-matching** por similitud temporal/items
- **N:1** (varios tickets para un mismo pedido)
- **Notificación al cliente** cuando se marca Fulfilled (WhatsApp / SMS / push)
- **Vista del pedido web en la app de báscula** (el carnicero sigue trabajando ciego respecto al pedido)
- **Tracking del cumplimiento** (tiempo entre Pending → Fulfilled, métricas operativas)
- **Diff visual** "pediste 2.0 kg, te dieron 2.15 kg" en el ticket impreso o en confirmación al cliente
- **Re-linking** (cambiar el emparejamiento sin desvincular primero)

## Checklist del diseño

- [x] Pedido web sigue creándose como `Sale` (sin tabla nueva)
- [x] Carnicero/báscula sin cambios — API actual intacta
- [x] Cajero/admin hace el emparejamiento manual en Workbench
- [x] Nuevo estado `Fulfilled` (terminal, semántica clara)
- [x] `linked_order_id` self-FK con `ON DELETE SET NULL`
- [x] Desvincular permitido mientras esté `Active` y sin `payments`
- [x] Delivery fee se suma al total de la venta de báscula al vincular
- [x] Datos de cliente y contacto se copian solo si la venta de báscula no los tenía
- [x] Datos de delivery se sobrescriben (pedido web es la fuente de verdad para delivery)
- [x] Broadcast `SaleUpdated` para ambas ventas al vincular/desvincular
- [x] Validación cross-branch y cross-tenant explícita
- [x] Scope `accountable()` para que métricas excluyan pedidos web no cobrados
- [x] Reusa la impresión de tickets existente desde Workbench (sin nuevo flujo)
- [x] Reutiliza `SaleStatus`, `SaleResource`, broadcast, Workbench — cambios aditivos
- [x] Race condition cubierta por transacción + unicidad implícita de `linked_order_id`
