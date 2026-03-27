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
| `total` | decimal(12,2) | Suma de subtotales de los ítems |
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

## Evento NewExternalSale

Ver `docs/arquitectura/reverb-websockets.md`.
