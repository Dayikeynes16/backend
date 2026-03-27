# Sucursales (Branches)

Unidad operativa dentro de una empresa. Cada sucursal tiene su propio catálogo de productos, cajeros y API Key.

## Responsabilidades

- Agrupar productos, ventas y usuarios por punto de venta físico.
- Definir horarios y datos de contacto de la sucursal.

## Modelo Eloquent (`app/Models/Branch.php`)

| Campo | Tipo | Notas |
|-------|------|-------|
| `id` | bigint PK | |
| `tenant_id` | FK → tenants | Cascade on delete |
| `name` | string | |
| `address` | string nullable | |
| `phone` | string nullable | |
| `schedule` | string nullable | Ej: "Lun-Sáb 7am-8pm" |
| `status` | string | `active` (default) o `inactive` |
| `timestamps` | | |

**Relaciones:** `users`, `products`, `sales`, `apiKey` (HasOne activa), `cashRegisterShifts`.

**Usa `BelongsToTenant`** — filtrado automático por tenant.

## Controller (`app/Http/Controllers/Empresa/SucursalController.php`)

Accesible por admin-empresa. Rutas bajo `/{tenant}/empresa/sucursales`.

| Método | Ruta | Descripción |
|--------|------|-------------|
| `index` | `GET /{tenant}/empresa/sucursales` | Lista con búsqueda y conteo de usuarios |
| `create` | `GET /{tenant}/empresa/sucursales/create` | Formulario |
| `store` | `POST /{tenant}/empresa/sucursales` | Crea sucursal con tenant_id del contexto |
| `edit` | `GET /{tenant}/empresa/sucursales/{sucursal}/edit` | |
| `update` | `PUT /{tenant}/empresa/sucursales/{sucursal}` | |
| `destroy` | `DELETE /{tenant}/empresa/sucursales/{sucursal}` | Cascade elimina productos, ventas, etc. |

## Validaciones

- `name`: required, string, max 255
- `address`: nullable, string, max 500
- `phone`: nullable, string, max 20
- `schedule`: nullable, string, max 255
- `status` (solo update): required, in:active,inactive
