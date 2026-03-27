# Empresas (Tenants)

Unidad de tenant del sistema. Cada empresa representa una carnicería o cadena de carnicerías.

## Responsabilidades

- Almacenar datos fiscales y de contacto de la empresa.
- Proveer el slug que forma parte de todas las URLs tenant-scoped.
- Servir como FK para el aislamiento de datos.

**No hace:** no tiene lógica de negocio propia. No gestiona facturación ni suscripciones en v1.

## Modelo Eloquent (`app/Models/Tenant.php`)

| Campo | Tipo | Notas |
|-------|------|-------|
| `id` | bigint PK | |
| `name` | string | Nombre de la empresa |
| `slug` | string unique | Base de la URL (`app.com/{slug}/...`) |
| `rfc` | string nullable | RFC fiscal mexicano |
| `logo_path` | string nullable | Ruta al logo (no implementado en v1) |
| `address` | string nullable | |
| `phone` | string nullable | |
| `status` | string | `active` (default) o `inactive` |
| `timestamps` | | |

**Relaciones:**
- `branches(): HasMany` → Branch
- `users(): HasMany` → User

**No usa `BelongsToTenant`** — es el tenant mismo.

## Controller (`app/Http/Controllers/Admin/EmpresaController.php`)

Solo accesible por superadmin.

| Método | Ruta | Descripción |
|--------|------|-------------|
| `index` | `GET /admin/empresas` | Lista paginada con búsqueda por nombre (ilike) |
| `create` | `GET /admin/empresas/create` | Formulario de creación |
| `store` | `POST /admin/empresas` | Crea empresa. Valida slug único + alpha_dash |
| `edit` | `GET /admin/empresas/{empresa}/edit` | Formulario de edición |
| `update` | `PUT /admin/empresas/{empresa}` | Actualiza empresa + estado |
| `destroy` | `DELETE /admin/empresas/{empresa}` | Elimina empresa (cascade en BD) |

## Validaciones

- `name`: required, string, max 255
- `slug`: required, string, max 255, unique, alpha_dash
- `rfc`: nullable, string, max 13
- `address`: nullable, string, max 500
- `phone`: nullable, string, max 20
- `status` (solo update): required, in:active,inactive
