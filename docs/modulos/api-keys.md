# API Keys

Una API Key por sucursal permite a apps externas (kioscos, TPV, móvil) interactuar con la API pública.

## Responsabilidades

- Generar keys seguras con prefijo `csa_` + 40 chars aleatorios.
- Almacenar solo el hash SHA-256 en BD.
- Mostrar la key completa una sola vez al generarla.
- Permitir revocar keys (soft delete via status).

**No hace:** no rota keys automáticamente. No soporta múltiples keys activas por sucursal (aunque la BD lo permite).

## Modelo Eloquent (`app/Models/ApiKey.php`)

| Campo | Tipo | Notas |
|-------|------|-------|
| `id` | bigint PK | |
| `tenant_id` | FK → tenants | |
| `branch_id` | FK → branches | |
| `name` | string | Etiqueta descriptiva (ej: "Kiosco principal") |
| `key_hash` | string(64) unique | SHA-256 de la key real. Hidden en serialización. |
| `last_used_at` | timestamp nullable | Actualizado en cada request válido |
| `status` | string | `active` (default) o `inactive` |
| `timestamps` | | |

**Usa `BelongsToTenant`.** Relación `branch(): BelongsTo`.

## Controller (`app/Http/Controllers/Sucursal/ApiKeyController.php`)

Accesible por admin-sucursal en `/{tenant}/sucursal/api-keys`.

| Método | Ruta | Descripción |
|--------|------|-------------|
| `index` | `GET /{tenant}/sucursal/api-keys` | Lista keys con prefijo (8 chars del hash), estado, último uso |
| `store` | `POST /{tenant}/sucursal/api-keys` | Genera key, guarda hash, redirige con key en flash session |
| `destroy` | `DELETE /{tenant}/sucursal/api-keys/{api_key}` | Marca como `inactive` (soft revoke) |

## Generación de key

```
Formato: csa_{40 chars aleatorios}
Ejemplo: csa_aB3kL9mN2pQ4rS6tU8vW0xY1zA5bC7dE9fG3h
```

1. Se genera con `Str::random(40)` prefijado con `csa_`.
2. Se calcula `hash('sha256', $rawKey)` y se guarda en `key_hash`.
3. La key completa se pasa al frontend via `session('newKey')`.
4. El frontend la muestra con opción de copiar al portapapeles.
5. Al navegar fuera, la key desaparece para siempre.

## Validaciones

- `name`: required, string, max 255

## Seguridad

- La key raw nunca se persiste en BD ni en logs.
- El hash SHA-256 es irreversible — no se puede recuperar la key original.
- Al revocar, las apps que usen la key recibirán 401 inmediatamente.
