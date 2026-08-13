# Ventas

Las ventas se originan exclusivamente desde apps externas vía API. El cajero solo cobra ventas pendientes.

## Responsabilidades

- Registrar ventas con sus ítems y snapshots de productos.
- Mantener el flujo de estados: `pending` → `completed` / `cancelled`.
- Generar folios consecutivos por sucursal.
- Notificar al cajero en tiempo real cuando llega una venta nueva.

**No hace:** el cajero no crea ventas. No hay carrito ni selección de productos en el frontend.

## Modelo Sale (`app/Models/Sale.php`)

| Campo | Tipo | Notas |
|-------|------|-------|
| `id` | bigint PK | |
| `tenant_id` | FK → tenants | |
| `branch_id` | FK → branches | |
| `user_id` | FK nullable → users | Cajero que cobró (null hasta cobrar) |
| `folio` | string | Consecutivo por sucursal, formato `S-00001` |
| `payment_method` | string | `cash`, `card`, `transfer` |
| `total` | decimal(12,2) | Suma de subtotales de los ítems **+ `delivery_fee`**. Todo recálculo pasa por `App\Support\SaleTotals::forSale()`: hasta 2026-08-11 dos servicios lo calculaban sin el envío y asignar cliente o editar una línea borraba ese importe |
| `origin` | string | Siempre `api` en v1 |
| `status` | string | `pending`, `completed`, `cancelled` |
| `completed_at` | timestamp nullable | Se llena cuando el cajero cobra |
| `timestamps` | | |

**Unique:** `(branch_id, folio)`.

**Relaciones:** `branch()`, `user()`, `items(): HasMany`.

**Usa `BelongsToTenant`.**

## Modelo SaleItem (`app/Models/SaleItem.php`)

| Campo | Tipo | Notas |
|-------|------|-------|
| `id` | bigint PK | |
| `sale_id` | FK → sales | Cascade on delete |
| `product_id` | FK nullable → products | Null on delete (el snapshot preserva los datos) |
| `product_name` | string | Snapshot del nombre al momento de la venta |
| `unit_type` | string | Snapshot del tipo de unidad |
| `quantity` | decimal(10,3) | Kg o cantidad de piezas |
| `unit_price` | decimal(10,2) | Snapshot del precio unitario |
| `subtotal` | decimal(12,2) | `quantity × unit_price` |

Los snapshots (`product_name`, `unit_type`, `unit_price`) aseguran que cambios futuros en el catálogo no alteren el historial.

## Flujo de una venta

```
1. App externa → POST /api/v1/sales
2. Middleware AuthenticateApiKey valida la key
3. SaleController@store:
   a. Valida payload
   b. Verifica que productos existen y están activos
   c. Calcula subtotales por ítem
   d. Genera folio consecutivo (con lock para concurrencia)
   e. Crea Sale (status=pending) + SaleItems (con snapshots)
   f. Dispara NewExternalSale (Reverb)
4. Cajero recibe la venta en tiempo real
5. Cajero presiona "Cobrar" → PATCH (Fase 6)
```

## Generación de folio

```php
$lastFolio = Sale::where('branch_id', $branchId)->lockForUpdate()->max('id');
$folio = 'S-' . str_pad(($lastFolio ?? 0) + 1, 5, '0', STR_PAD_LEFT);
```

Se usa `lockForUpdate()` para evitar folios duplicados en peticiones concurrentes.

## Teléfono y cliente

Capturar un teléfono en una venta **resuelve o crea el cliente** de la sucursal y lo asocia (`ResolveCustomerByPhone`). Antes el número quedaba suelto en `sales.contact_phone` sin cliente.

La asignación es automática salvo que cambie el total de la venta o la deje cobrada: en ese caso el endpoint responde `requires_confirmation` con el impacto calculado y no toca nada hasta que el usuario decide.

Detalle completo —normalización, comandos de mantenimiento, contrato del endpoint y permisos— en [Teléfonos y resolución de clientes](clientes-telefonos.md).

## Nombre de la venta desde la báscula

Cuando se despachan varias ventas a la vez, la cola de la Mesa de Trabajo no las distingue: todas dicen `S-01042 · Báscula 2 · $480`. Desde el 2026-08-13 la báscula puede ponerle un nombre —**"A nombre de"**— escribiéndolo o dictándolo, y ese nombre aparece junto al folio.

**Es una etiqueta, no un cliente.** Se guarda en `sales.contact_name`, no crea ni asocia registros en `customers`, y `customer_id` sigue en `null`. La razón es que la identidad de un cliente en este sistema es **el teléfono** (ver [clientes-telefonos](clientes-telefonos.md)), y un nombre dictado no tiene esa propiedad: "Juan" son cinco personas, y un dictado imperfecto asociaría la venta a la equivocada arrastrando precios preferenciales y deuda.

Si una venta necesita cliente de verdad, el flujo de siempre sigue disponible: el cajero captura el teléfono en la Mesa de Trabajo y el sistema resuelve o crea el cliente.

| Pieza | Dónde |
|---|---|
| Campo en el payload | `POST /api/v1/sales`, `contact_name` opcional ([endpoints](../api/endpoints.md#post-apiv1sales)) |
| Dictado | `POST /api/v1/transcribe` (Whisper, español) |
| Visible en | Tarjeta de la cola (Caja y Sucursal) y detalle de ambas |

El dictado va **siempre contra la nube**, aunque la venta viaje al hub: el hub no expone ese endpoint y la báscula guarda las credenciales de nube igualmente para el respaldo. Consecuencias buscadas: **`carniceria-hub` no necesitó ni una línea** de cambio, y el dictado sobrevive a que el hub se caiga. Sin credenciales de nube el micrófono no aparece y el campo se escribe a mano — la venta nunca se bloquea por el nombre.

El badge de la cola es **violeta**, no azul: el azul con el icono de persona ya significa "cliente asignado" en el chip de WhatsApp, y usar el mismo color para algo que no es un cliente entrenaría a confundirlos.

Spec: [2026-08-13-nombre-en-venta-de-bascula-design.md](../superpowers/specs/2026-08-13-nombre-en-venta-de-bascula-design.md).

## Evento NewExternalSale

Ver `docs/arquitectura/reverb-websockets.md`.
