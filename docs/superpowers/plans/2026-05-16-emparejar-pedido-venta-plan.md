# Plan de Implementación — Emparejar pedido web con venta de báscula

**Fecha**: 2026-05-16
**Spec**: `docs/superpowers/specs/2026-05-16-emparejar-pedido-venta-design.md`
**Estado**: Listo para ejecución

> Cada fase es un bloque desplegable. El feature está funcional end-to-end después de la Fase 7. Fases 8–10 lo pulen (caja, métricas, tests cross-cutting).

---

## Fase 1 — Migración + enum + modelo

Base mínima del modelo de datos.

**Archivos a crear:**
- `database/migrations/2026_05_16_000001_add_linked_order_id_to_sales_table.php`:
  ```php
  $table->foreignId('linked_order_id')->nullable()
        ->constrained('sales')->nullOnDelete();
  $table->index('linked_order_id', 'sales_linked_order_id_idx');
  ```

**Archivos a modificar:**
- `app/Enums/SaleStatus.php`:
  - Agregar `case Fulfilled = 'fulfilled';`
  - `label()`: `self::Fulfilled => 'Cumplida'`
  - `color()`: `self::Fulfilled => 'emerald'`
  - `allowedTransitions()`: `Pending` agrega `Fulfilled`; nuevo `Fulfilled => [Pending]`
- `app/Models/Sale.php`:
  - `$fillable` agrega `'linked_order_id'`
  - Relaciones `linkedOrder()` y `fulfilledBy()` (ver spec §3)
  - Nuevo scope `scopeAccountable($query)` (ver spec §10)

**Tests mínimos:**
- `tests/Feature/Migrations/LinkedOrderSchemaTest.php`: `Schema::hasColumn('sales','linked_order_id')`; índice presente; FK con `nullOnDelete`
- `tests/Unit/SaleStatusTest.php`: cobertura de `Fulfilled` en label/color; transiciones `Pending↔Fulfilled` permitidas, otras prohibidas

**Criterio de hecho:** `sail artisan migrate` aplica sin error; `Sale::factory()->create(['status' => SaleStatus::Fulfilled])` funciona en tinker.

---

## Fase 2 — OrderLinkService + excepciones

Núcleo de la lógica de vinculación.

**Archivos a crear:**
- `app/Exceptions/OrderLink/IneligibleScaleSaleException.php`
- `app/Exceptions/OrderLink/IneligibleWebOrderException.php`
- `app/Exceptions/OrderLink/CrossBranchLinkException.php`
- `app/Exceptions/OrderLink/LockedScaleSaleException.php`
- `app/Services/OrderLinkService.php` (ver spec §4 para implementación completa)

**Archivos a modificar:**
- `bootstrap/app.php` — en `withExceptions(...)`, mapear las 4 excepciones a `back()->with('error', $e->getMessage())` para requests Inertia

**Tests mínimos** (`tests/Unit/OrderLinkServiceTest.php`):
- `link_happy_path_copies_delivery_and_marks_fulfilled`
- `link_sums_delivery_fee_to_total`
- `link_only_copies_customer_when_scale_sale_has_none`
- `link_overwrites_delivery_even_if_scale_sale_had_values`
- `link_throws_when_scale_sale_is_web_origin`
- `link_throws_when_scale_sale_already_linked`
- `link_throws_when_scale_sale_not_active`
- `link_throws_when_web_order_not_pending`
- `link_throws_when_web_order_not_web_origin`
- `link_throws_on_cross_branch`
- `unlink_reverts_to_pending_and_clears_fields`
- `unlink_keeps_customer_assignment`
- `unlink_throws_if_payments_exist`
- `unlink_throws_if_sale_not_active`

**Criterio de hecho:** unit tests pasan; en tinker `app(OrderLinkService::class)->link($scaleSale, $webOrder)` deja ambas ventas con el estado esperado.

---

## Fase 3 — SaleResource extendido + eager-loading

Necesario para que el frontend reciba `linked_order_id` y los objetos relacionados en el broadcast y en la carga inicial.

**Archivos a modificar:**
- `app/Http/Resources/SaleResource.php` — agregar `linked_order_id`, `linked_order`, `fulfilled_by` (ver spec §7)
- `app/Http/Controllers/Sucursal/WorkbenchController.php::index()` — agregar `linkedOrder` y `fulfilledBy` al `with([...])` de la query
- `app/Http/Controllers/Caja/WorkbenchController.php::index()` — mismo cambio
- `app/Events/SaleUpdated.php` — verificar que `broadcastWith()` cargue las relaciones antes de serializar, o eager-load en el constructor

**Tests mínimos:**
- `tests/Unit/SaleResourceTest.php` — agrega: venta con `linked_order_id` cargado serializa `linked_order: {id, folio}`; venta sin link → `null`; pedido web cumplido serializa `fulfilled_by`

**Criterio de hecho:** payload JSON de cualquier venta del Workbench incluye `linked_order_id` y la relación cuando aplique.

---

## Fase 4 — Endpoint listado de pedidos pendientes

Datos para el modal de vinculación.

**Archivos a modificar:**
- `app/Http/Controllers/Sucursal/WorkbenchController.php` — agregar método `pendingWebOrders()`:
  ```php
  public function pendingWebOrders(): JsonResponse
  {
      $branchId = auth()->user()->branch_id;
      $orders = Sale::with('items')
          ->where('branch_id', $branchId)
          ->where('origin', 'web')
          ->where('status', SaleStatus::Pending)
          ->orderByDesc('created_at')
          ->limit(50)
          ->get()
          ->map(fn ($s) => [
              'id'              => $s->id,
              'folio'           => $s->folio,
              'created_at'      => $s->created_at,
              'contact_name'    => $s->contact_name,
              'contact_phone'   => $s->contact_phone,
              'delivery_type'   => $s->delivery_type,
              'delivery_address'=> $s->delivery_address,
              'delivery_fee'    => $s->delivery_fee,
              'total'           => $s->total,
              'items_count'     => $s->items->count(),
              'items_preview'   => $s->items->take(3)->map(fn ($i) => [
                  'product_name' => $i->product_name,
                  'quantity'     => $i->quantity,
                  'unit_type'    => $i->product?->unit_type,
              ])->values(),
          ]);

      return response()->json(['orders' => $orders]);
  }
  ```
- `routes/web.php` — dentro del grupo `sucursal.`:
  ```php
  Route::get('mesa-de-trabajo/pedidos-pendientes',
      [WorkbenchController::class, 'pendingWebOrders'])
      ->name('workbench.pending-web-orders');
  ```
- Espejo en `app/Http/Controllers/Caja/WorkbenchController.php` + ruta análoga bajo el grupo `caja.`

**Tests mínimos** (`tests/Feature/Sucursal/PendingWebOrdersTest.php`):
- `lists_only_pending_web_orders_of_branch`
- `excludes_other_branches_same_tenant`
- `excludes_other_tenants`
- `excludes_fulfilled_and_cancelled`
- `includes_items_preview_max_3`

**Criterio de hecho:** `GET /sucursal/mesa-de-trabajo/pedidos-pendientes` devuelve JSON con la lista filtrada.

---

## Fase 5 — Endpoints linkOrder/unlinkOrder

**Archivos a modificar:**
- `app/Http/Controllers/Sucursal/WorkbenchController.php`:
  ```php
  public function linkOrder(Request $request, Sale $sale, OrderLinkService $service)
  {
      $validated = $request->validate([
          'order_id' => ['required', 'integer', 'exists:sales,id'],
      ]);

      $webOrder = Sale::findOrFail($validated['order_id']);
      $service->link($sale, $webOrder);

      return back()->with('success', "Vinculado al pedido {$webOrder->folio}");
  }

  public function unlinkOrder(Sale $sale, OrderLinkService $service)
  {
      $service->unlink($sale);
      return back()->with('success', 'Pedido desvinculado');
  }
  ```
- `routes/web.php` — agregar:
  ```php
  Route::post('mesa-de-trabajo/ventas/{sale}/vincular-pedido',
      [WorkbenchController::class, 'linkOrder'])->name('workbench.link-order');
  Route::delete('mesa-de-trabajo/ventas/{sale}/vincular-pedido',
      [WorkbenchController::class, 'unlinkOrder'])->name('workbench.unlink-order');
  ```
- Espejo en `Caja/WorkbenchController.php` y `routes/web.php` (grupo `caja.`)

**Tests mínimos** (`tests/Feature/Sucursal/LinkOrderTest.php`):
- `admin_can_link_scale_sale_to_pending_web_order`
- `cashier_can_link_via_caja_route`
- `link_broadcasts_sale_updated_for_both`
- `link_returns_back_with_error_message_when_ineligible`
- `cross_tenant_link_attempt_returns_404` (ModelNotFound por tenant scope)

(`tests/Feature/Sucursal/UnlinkOrderTest.php`):
- `admin_can_unlink_when_sale_active_and_no_payments`
- `unlink_blocked_when_payment_exists`

**Criterio de hecho:** POST/DELETE funcionan con permisos correctos; `back()->with()` flashea toast en frontend.

---

## Fase 6 — Modal LinkOrderModal.vue

Pieza visual del emparejamiento.

**Archivos a crear:**
- `resources/js/Components/Workbench/LinkOrderModal.vue`
  - Props: `open: Boolean`, `scaleSale: Object`
  - Emits: `close`, `linked`
  - Mount/open → `axios.get(route('sucursal.workbench.pending-web-orders'))` → guarda `orders`
  - Estado local: `selectedOrderId`, `loading`, `submitting`
  - Lista de pedidos con radio buttons
  - Preview del desglose calculado: `scaleSale.items_subtotal + selectedOrder.delivery_fee`
  - Submit: `router.post(route('sucursal.workbench.link-order', scaleSale.id), { order_id: selectedOrderId }, { onSuccess: () => emit('linked') })`

**Tests:**
- Smoke manual: abrir modal, ver lista, seleccionar pedido, confirmar → vinculación exitosa, toast aparece, Workbench refresca

**Criterio de hecho:** El modal abre con la lista, permite seleccionar, y al confirmar dispara la vinculación.

---

## Fase 7 — Integración en `Sucursal/Workbench.vue`

**Archivos a modificar:**
- `resources/js/Pages/Sucursal/Workbench.vue`:
  - Importar `LinkOrderModal`
  - Refs `showLinkModal: ref(false)`
  - Computed `canLinkOrder(sale)` y `canUnlinkOrder(sale)` (ver spec §8)
  - En la tarjeta de cada venta (alrededor de línea 310, junto a los badges actuales): agregar badge "Cumple {folio}" cuando `sale.linked_order_id` y badge "Cumplido por {folio}" cuando `sale.origin === 'web' && sale.status === 'fulfilled'`
  - En el panel de detalle de venta seleccionada: agregar botones "Vincular pedido web" y "Desvincular pedido" (ver spec §8)
  - Renderizar `<LinkOrderModal :open="showLinkModal" :scale-sale="selected" @close="showLinkModal=false" @linked="onLinked" />`

**Criterio de hecho:** En el Workbench se pueden vincular y desvincular pedidos web a ventas de báscula con un flujo de 2 clics.

---

## Fase 8 — Espejo en `Caja/Workbench.vue`

Mismos cambios que Fase 7 pero en el Workbench del cajero. Reusar `LinkOrderModal` (cambiar el route helper a `caja.workbench.link-order`).

**Archivos a modificar:**
- `resources/js/Pages/Caja/Workbench.vue` — replicar badges, botones y modal
- `LinkOrderModal.vue` — aceptar prop `routePrefix: 'sucursal' | 'caja'` para usar el route helper correcto

**Criterio de hecho:** El cajero también puede emparejar desde su Workbench.

---

## Fase 9 — Scope `accountable()` aplicado a métricas

**Archivos a modificar (revisar y agregar `->accountable()` donde corresponda):**
- `app/Services/DailySummaryService.php`
- `app/Services/ShiftTotalsCalculator.php`
- `app/Services/RecalculateClosedShifts.php`
- `app/Services/Metrics/` (todos los archivos)
- Cualquier consulta directa a `Sale` en `SaleHistoryController`, `DashboardController`, controladores de reportes

Cada query que sume `total`, cuente ventas o calcule promedios debe filtrar con `accountable()` para excluir `web+pending` y `web+fulfilled` (que no representan transacción cobrada).

**Tests mínimos:**
- Actualizar tests existentes de métricas para incluir un pedido web `Pending` y otro `Fulfilled` en el setup, y verificar que NO se cuentan
- `tests/Unit/SaleScopeAccountableTest.php` — cubre la matriz origin/status

**Criterio de hecho:** ningún reporte cuenta dos veces; pedido `Fulfilled` ya no aparece en totales del turno.

---

## Fase 10 — Tests cross-cutting + rollout

**Archivos a crear:**
- `tests/Feature/Sucursal/EndToEndLinkFlowTest.php`:
  1. Cliente crea pedido web → `Sale web+pending`
  2. Carnicero crea venta vía API → `Sale api+active`
  3. Admin vincula → `web→fulfilled`, `api.linked_order_id=web.id`, total incluye delivery_fee
  4. Cajero cobra la venta de báscula → `Completed`
  5. Métricas del día reportan 1 venta (no 2), total correcto

**Activación gradual:**
- Smoke en staging con tenant piloto (`el-toro`)
- Crear pedido web real, imprimir desde Workbench, crear venta de báscula con la demo API key, vincular, cobrar
- Validar que las métricas del corte de caja cuentan correctamente
- Iterar antes de habilitar para otros tenants

**Criterio de hecho:** CI verde; smoke E2E en staging OK; corte de caja del día con pedido web emparejado cuadra al centavo.

---

## Orden y dependencias

```
Fase 1 (Migración + enum + modelo)
   ↓
Fase 2 (OrderLinkService + excepciones)
   ↓
Fase 3 (SaleResource extendido)
   ↓
Fase 4 (Endpoint pedidos pendientes)  ┐
   ↓                                  │
Fase 5 (Endpoints link/unlink)        │
   ↓                                  │
Fase 6 (LinkOrderModal.vue)           │  Fase 9 (Scope accountable + métricas)
   ↓                                  │     puede correr en paralelo desde Fase 3
Fase 7 (Sucursal/Workbench.vue)       │
   ↓                                  │
Fase 8 (Caja/Workbench.vue)           │
   ↓                                  │
Fase 10 (E2E + rollout) ──────────────┘
```

Después de la Fase 7 el feature funciona end-to-end para admin-sucursal. Las Fases 8–10 lo extienden y aseguran calidad operativa.

---

## Archivos críticos para la implementación

- `app/Enums/SaleStatus.php` — agregar `Fulfilled` con transiciones
- `app/Services/OrderLinkService.php` (nuevo — núcleo del flujo)
- `app/Http/Controllers/Sucursal/WorkbenchController.php` — métodos `linkOrder`, `unlinkOrder`, `pendingWebOrders`
- `app/Http/Resources/SaleResource.php` — exponer `linked_order_id` + relaciones
- `app/Models/Sale.php` — relaciones + scope `accountable()`
- `resources/js/Components/Workbench/LinkOrderModal.vue` (nuevo)
- `resources/js/Pages/Sucursal/Workbench.vue` — integración del modal y botones
- `database/migrations/2026_05_16_000001_add_linked_order_id_to_sales_table.php` (nuevo)
