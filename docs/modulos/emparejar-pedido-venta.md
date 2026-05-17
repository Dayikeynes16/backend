# Emparejar pedido web ↔ venta de báscula

> **Status**: implementado · 2026-05-16
> **Spec**: [`docs/superpowers/specs/2026-05-16-emparejar-pedido-venta-design.md`](../superpowers/specs/2026-05-16-emparejar-pedido-venta-design.md)
> **Plan**: [`docs/superpowers/plans/2026-05-16-emparejar-pedido-venta-plan.md`](../superpowers/plans/2026-05-16-emparejar-pedido-venta-plan.md)

## Por qué existe este módulo

El módulo de [pedidos web](./../superpowers/specs/2026-04-17-pedidos-web-design.md) crea ventas `origin='web'` que el cliente arma desde el menú público y confirma por WhatsApp. En la operación real de carnicería el peso pedido (ej. "2 kg arrachera") siempre difiere del peso real que el carnicero corta (2.15 kg).

En vez de obligar al carnicero a editar el pedido web — rompiendo su flujo establecido en la app de báscula — el pedido web actúa como **comanda/referencia** y se empareja a posteriori con la venta real que crea la báscula. Ambas ventas conviven: el pedido preserva la intención del cliente, la venta de báscula registra la transacción real.

## Flujo operativo

```
Cliente              Carnicero              Cajero/Admin           Sistema
   │                    │                       │                     │
   ├─ Pedido web ─────→ │                       │                     │
   │   (menú público)   │                       │                     │
   │                    │                       │← Pendiente en Workbench
   │                    │                       │
   │                    │                       ├─ Imprime ticket ───→│
   │                    │← Lee ticket           │   (ya existía)
   │                    │
   │                    ├─ Pesa carne ─────────→│ ← Venta de báscula
   │                    │   crea sale por API   │   origin='api' active
   │                    │                       │
   │                    │                       ├─ "Vincular ─────────→│
   │                    │                       │   pedido web"        │
   │                    │                       │                       │ ← OrderLinkService
   │                    │                       │                       │   - copia delivery
   │                    │                       │                       │   - suma fee al total
   │                    │                       │                       │   - web → Fulfilled
   │                    │                       │                       │   - scale.linked_order_id
   │                    │                       │← Toast "Vinculado"
   │                    │                       │
   │                    │                       ├─ Cobra venta ────────→│
   │                    │                       │   báscula → Completed │
   │                    │                       │
```

## Modelo de datos

```sql
ALTER TABLE sales ADD COLUMN linked_order_id BIGINT NULL
    REFERENCES sales(id) ON DELETE SET NULL;
CREATE INDEX sales_linked_order_id_idx ON sales(linked_order_id)
    WHERE linked_order_id IS NOT NULL;
```

- Solo la venta de báscula (la "real") tiene `linked_order_id` apuntando al pedido web.
- El pedido web nunca tiene `linked_order_id` propio.
- `ON DELETE SET NULL`: si por algún motivo se borra el pedido web, la venta real sobrevive.

### Enum SaleStatus

Se agregó el caso `Fulfilled = 'fulfilled'`:

| Estado | Significado |
|--------|-------------|
| `Active` | Venta normal en curso |
| `Pending` | Pendiente (web sin emparejar, o pausada manualmente) |
| `Completed` | Cobrada |
| `Cancelled` | Cancelada |
| **`Fulfilled`** | **Pedido web emparejado con una venta de báscula que la reemplaza como transacción real** |

Transiciones permitidas:
- `Pending → Fulfilled` (al vincular)
- `Fulfilled → Pending` (al desvincular)

## Componentes técnicos

### Service

`app/Services/OrderLinkService.php`:

- `link(Sale $scaleSale, Sale $webOrder)` — orquesta la vinculación en transacción:
  - Copia `customer_id`, `contact_name`, `contact_phone` (solo si faltan en la venta real)
  - Sobrescribe `delivery_*` desde el pedido (es la fuente de verdad)
  - Recalcula `total = items_subtotal + delivery_fee` y `amount_pending`
  - Marca el pedido web como `Fulfilled`
  - Dispatch `SaleUpdated` para ambas ventas (Reverb actualiza Workbench en tiempo real)
- `unlink(Sale $scaleSale)` — revierte la vinculación. Solo permitido si la venta de báscula sigue `Active` y sin `payments`.

### Excepciones

`app/Exceptions/OrderLink/`:

- `IneligibleScaleSaleException` — venta destino no es elegible (es web, ya vinculada, no Active)
- `IneligibleWebOrderException` — pedido fuente no es elegible (no es web, no Pending)
- `CrossBranchLinkException` — sucursales distintas
- `LockedScaleSaleException` — venta ya tiene pagos

Los controllers las capturan y devuelven `back()->with('error', ...)`.

### Endpoints

```
POST    {tenant}/sucursal/mesa-de-trabajo/ventas/{sale}/vincular-pedido    sucursal.workbench.link-order
DELETE  {tenant}/sucursal/mesa-de-trabajo/ventas/{sale}/vincular-pedido    sucursal.workbench.unlink-order
GET     {tenant}/sucursal/mesa-de-trabajo/pedidos-pendientes               sucursal.workbench.pending-web-orders

POST    {tenant}/caja/ventas/{sale}/vincular-pedido                        caja.link-order
DELETE  {tenant}/caja/ventas/{sale}/vincular-pedido                        caja.unlink-order
GET     {tenant}/caja/pedidos-pendientes                                   caja.pending-web-orders
```

Body de `link-order`: `{order_id: int}`. El endpoint de listado retorna pedidos web `pending` de la sucursal del usuario con preview (folio, hora, cliente, teléfono, delivery, fee, total, items_preview de 3).

### Resource

`app/Http/Resources/SaleResource.php` agrega:

- `linked_order_id: int|null`
- `linked_order: {id, folio, status}` cuando `linkedOrder` está cargada
- `fulfilled_by: {id, folio, status}` cuando `fulfilledBy` está cargada

Los Workbench (Sucursal y Caja) eager-cargan ambas relaciones, así el broadcast `SaleUpdated` lleva el contexto completo sin re-fetch.

### Frontend

`resources/js/Components/Workbench/LinkOrderModal.vue`:

- Prop `routePrefix`: `'sucursal'` o `'caja'` (decide qué rutas usar)
- Fetch de pedidos pendientes vía `axios.get(route('...pending-web-orders'))`
- Lista con radio buttons, preview de cada pedido (folio, hora relativa, cliente, teléfono, badge delivery/pickup, items_preview)
- Preview del desglose: `productos $X + envío $Y = total $Z`
- Submit vía `router.post()` (Inertia)

Integración en `resources/js/Pages/Sucursal/Workbench.vue` y `Caja/Workbench.vue`:

- Badges en card: 🔗 `WEB-XXX` (vinculada) / ✓ Cumplido · `API-YYY` (fulfilled)
- Botones en header del detalle: "🔗 Vincular pedido web" y "Desvincular pedido"
- Renderizado del modal + ConfirmDialog de desvinculación

### Scope accountable

`Sale::accountable()` excluye ventas `origin='web' AND status IN ('pending', 'fulfilled')` para que no doble-cuenten contra la venta de báscula que las reemplaza:

```php
public function scopeAccountable(Builder $query): Builder
{
    return $query->where(function (Builder $q) {
        $q->where('origin', '!=', 'web')
            ->orWhereNotIn('status', [
                SaleStatus::Pending->value,
                SaleStatus::Fulfilled->value,
            ]);
    });
}
```

Para queries que bypasean Eloquent (servicios de métricas), el mismo filtro está espejado en `AbstractMetrics::excludeUnaccountableWebOrders(Builder, table='')`.

Aplicado en:

| Lugar | Razón |
|-------|-------|
| `CollectionMetrics::summary` (`totalPending`) | Sumar `amount_pending` incluyendo web pending infla la deuda |
| `CollectionMetrics::aging` | Antigüedad de cuentas por cobrar — web no es cobranza real |
| `CollectionMetrics::receivablesTable` | Tabla de saldos por cliente |
| `CustomerMetrics::summary` (`buyingQuery`, `withBalance`, `fiados`) | Clientes que compran / con saldo |
| `CustomerMetrics::topCustomers` | Top compradores |
| `CustomerMetrics::withBalance` (método) | Saldos por cliente |
| `CustomerMetrics::aging` | Antigüedad por cliente |
| `SaleHistoryController::index` | Historial diario de ventas |

**Naturalmente seguros (no requieren el filtro):**

- `SalesMetrics::*` — default `status=Completed`, los pedidos web nunca llegan ahí
- `CashierMetrics::*` — filtra por `completed_at` o `user_id`, ninguno aplica a web orders
- `MarginMetrics::*` — solo `Completed`
- `CancellationMetrics::*` — un pedido web rechazado SÍ debe contar como cancelación
- `ShiftTotalsCalculator` — join con `payments` o filtro por `user_id`
- Dashboard `pendingCount` — admin SÍ quiere ver pedidos web pendientes

## Reglas de negocio

| Regla | Implementación |
|-------|----------------|
| El pedido web no se cobra | Su `status=Fulfilled` y no aparece en queries `accountable()` |
| Una sola venta de báscula por pedido web (1:1) | `linked_order_id` único de hecho (un scale sale solo apunta a uno) |
| Solo admin/cajero empareja | Endpoints bajo middleware `role:admin-sucursal|superadmin` y `role:cajero|superadmin` |
| Branch enforcement | `WorkbenchController` valida `$sale->branch_id === $user->branch_id` antes del service |
| Tenant enforcement | `BelongsToTenant` scope global en `Sale` + validación explícita en service |
| Delivery fee se suma al cobrar | Al vincular, `delivery_fee` del pedido se copia y se suma al `total` de la venta real |
| Desvincular requiere venta sin pagos | `OrderLinkService::assertScaleSaleStillEditable()` valida `payments()->exists() === false` |
| Web cancelado SÍ cuenta | El scope `accountable()` no excluye web+cancelled — son rechazos reales que aparecen en métricas de cancelaciones |

## Testing

- **Unit:**
  - `tests/Unit/Enums/SaleStatusTest.php` — caso `Fulfilled` y transiciones
  - `tests/Unit/Services/OrderLinkServiceTest.php` — happy paths y todas las excepciones
  - `tests/Unit/Http/Resources/SaleResourceTest.php` — serialización con/sin relaciones
  - `tests/Unit/SaleScopeAccountableTest.php` — matriz 12 combinaciones origin × status

- **Feature:**
  - `tests/Feature/Migrations/LinkedOrderSchemaTest.php` — schema, FK, índice, cascade SET NULL
  - `tests/Feature/Sucursal/LinkOrderTest.php` — endpoint POST, validación, broadcast, cross-branch, cajero espejo
  - `tests/Feature/Sucursal/UnlinkOrderTest.php` — endpoint DELETE, bloqueo por pagos, cross-branch
  - `tests/Feature/Sucursal/PendingWebOrdersTest.php` — listado, filtros tenant/branch/status, items_preview
  - `tests/Feature/Sucursal/EndToEndLinkFlowTest.php` — recorrido completo + métricas no doble-cuentan + rechazo

Suite total: **71 tests, 177 aserciones**.

## Rollout

1. **Migración** (`2026_05_16_120000_add_linked_order_id_to_sales_table`) — nullable, zero downtime.
2. **Activar en staging** con tenant `el-toro`:
   - Verificar que aparecen badges en Workbench tras crear un pedido web demo
   - Crear venta de báscula con la demo API key (`csa_demo_test_key_for_development_only_1234`)
   - Vincular manualmente, validar desglose con envío
   - Cobrar la venta, validar que el corte de caja del día cuadra al centavo
3. **Habilitar para más tenants** una vez validado el flujo operativo con el primero.

## Fuera de alcance (v2+)

- Auto-matching por similitud temporal/items
- N:1 (varios tickets para un mismo pedido)
- Notificación al cliente cuando se marca `Fulfilled`
- Vista del pedido web en la app de báscula (el carnicero sigue trabajando sin verlo)
- Diff visual "pediste 2.0 kg, te dieron 2.15 kg" en el ticket
- Re-linking (cambiar el emparejamiento sin desvincular primero)
