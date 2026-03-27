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
