# Productos

Catálogo de productos independiente por sucursal. No existe catálogo global de empresa en v1.

## Responsabilidades

- Definir los productos disponibles para venta en cada sucursal.
- Soportar tres tipos de unidad con diferente lógica de precio.

## Tipos de unidad (`unit_type`)

| Tipo | Descripción | Cálculo de subtotal |
|------|-------------|---------------------|
| `kg` | Venta a granel | `cantidad_kg × precio_por_kg` |
| `piece` | Precio fijo por unidad | `cantidad × precio` |
| `cut` | Corte específico (bistec, chuleta, arrachera...) | Igual que `piece`. El nombre del corte está en el campo `name`. |

## Modelo Eloquent (`app/Models/Product.php`)

| Campo | Tipo | Notas |
|-------|------|-------|
| `id` | bigint PK | |
| `tenant_id` | FK → tenants | |
| `branch_id` | FK → branches | Catálogo por sucursal |
| `name` | string | |
| `description` | text nullable | |
| `image_path` | string nullable | No implementado en v1 |
| `price` | decimal(10,2) | Precio por unidad o por kg |
| `unit_type` | string | `kg`, `piece`, `cut` |
| `status` | string | `active` (default) o `inactive` |
| `timestamps` | | |

**Relaciones:** `branch(): BelongsTo`.

**Usa `BelongsToTenant`.**

## Controller (`app/Http/Controllers/Sucursal/ProductoController.php`)

Accesible por admin-sucursal. Filtra siempre por `branch_id` del usuario autenticado.

| Método | Ruta | Descripción |
|--------|------|-------------|
| `index` | `GET /{tenant}/sucursal/productos` | Lista paginada (20/pág) con filtros search y unit_type |
| `create` | `GET /{tenant}/sucursal/productos/create` | |
| `store` | `POST /{tenant}/sucursal/productos` | Asigna tenant_id y branch_id del usuario |
| `edit` | `GET /{tenant}/sucursal/productos/{producto}/edit` | |
| `update` | `PUT /{tenant}/sucursal/productos/{producto}` | |
| `destroy` | `DELETE /{tenant}/sucursal/productos/{producto}` | |

## Validaciones

- `name`: required, string, max 255
- `description`: nullable, string, max 1000
- `price`: required, numeric, min 0.01
- `unit_type`: required, in:kg,piece,cut
- `status` (solo update): required, in:active,inactive

---

## Sale mode + presentaciones (extensión 2026-04-25)

### `sale_mode` y presentaciones

`products.sale_mode ∈ {weight, presentation, both}` define cómo se vende:
- `weight`: solo por peso/cantidad libre (báscula).
- `presentation`: solo por presentaciones de catálogo.
- `both`: el cajero elige al vender — por peso o por presentación.

Tabla `product_presentations`:
- `content` (decimal:3) + `unit ∈ {g, kg, ml, l, pieza}` — el contenido real.
- `price` — precio de la presentación completa.

### Contrato de la línea de venta (`sale_items`)

Filas pre-2026-04-25 tienen solo el contrato legacy (`unit_type` + `quantity` heredados del producto). Filas nuevas añaden:
- `presentation_id` — FK a la presentación vendida (nullable, `ON DELETE SET NULL`).
- `presentation_snapshot` (jsonb) — `{id, name, content, unit, price}` congelado al momento.
- `sale_mode_at_sale ∈ {weight, presentation, piece}`.
- `quantity_unit ∈ {kg, g, piece, unit, …}` — la unidad de `quantity` sin ambigüedad.

### Reglas de escritura (3 controllers)

Implementadas idénticamente en:
- `Sucursal/WorkbenchController::store`
- `Api/SaleController::store` (báscula y kiosko web)
- `Public/OrderController::store` (pedidos web)

**Línea de presentación:**
```
unit_type = quantity_unit = 'unit'
sale_mode_at_sale = 'presentation'
quantity = número de presentaciones (típicamente 1)
unit_price = presentation.price
product_name = "<producto> - <presentación>"
presentation_id + presentation_snapshot llenos
```

**Línea de peso/pieza:**
```
unit_type = quantity_unit = product.unit_type
sale_mode_at_sale = 'weight' | 'piece'
presentation_id = NULL, snapshot = NULL
```

### Reglas de lectura (formatter compartido)

Toda capa de render usa el formatter para coexistir con filas legacy:
- Backend: `app/Services/SaleItemFormatter.php` (`displayName`, `displayQuantity`, `realContent`, `saleMode`).
- Frontend: `resources/js/composables/useSaleItemDisplay.js` (mismo contrato en JS).

**Lógica de fallback:**
1. Si `presentation_snapshot` existe → fuente de verdad.
2. Si no → contrato legacy (`unit_type` + `quantity`). Render idéntico al pre-fix.
3. `realContent()` para reportes: multiplica `quantity × snapshot.content` y normaliza (g↔kg, ml↔l).

### Renders que pasan por el formatter

| Render | Archivo |
|---|---|
| Workbench Sucursal (admin) | `resources/js/Pages/Sucursal/Workbench.vue` |
| Workbench Caja (cajero) | `resources/js/Pages/Caja/Workbench.vue` |
| Modal de detalle de venta | `resources/js/Components/Clientes/SaleDetailModal.vue` |
| Ticket impreso | `resources/js/Components/TicketPrinter.vue` |
| WhatsApp pedido al admin | `App\Services\WhatsappMessageService::buildOrderText` |
| WhatsApp recibo al cliente | `App\Services\WhatsappMessageService::buildCustomerSaleText` |

### Compatibilidad con clientes externos

- `bascula/` (web kiosko) y `bascula-android/` ya envían `presentation_id` en el POST. Sin cambios obligatorios.
- Cualquier campo nuevo en el JSON del response es opcional; clientes que ignoran fields desconocidos (Vue, Gson) no se ven afectados.

### Datos históricos

**No se hace backfill por defecto.** Filas pre-migración mantienen su semántica legacy y se renderizan con el fallback. Si en el futuro se decide backfill aproximado, se hará vía comando `--dry-run` que matchee `product_name LIKE '<X> - <Y>'` con catálogo actual y marque `backfilled=true` en el snapshot.

### Tests

- `tests/Unit/Services/SaleItemFormatterTest.php` — 19 tests cubren los 4 métodos del formatter con filas nuevas y legacy.
- `tests/Feature/PresentationSaleContractTest.php` — 4 tests end-to-end (Workbench presentación, Workbench peso, API v1 presentación, fila legacy).
