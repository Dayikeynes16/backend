# Autenticación por API Key

Toda la API pública (`/api/v1/*`) se autentica mediante una API Key enviada en el header `X-Api-Key`. No usa sesiones ni tokens JWT.

## Responsabilidades

- Autenticar peticiones de apps externas (kioscos, TPV, móvil).
- Resolver el branch y tenant asociados a la key.
- Aplicar rate limiting por key.
- Registrar cuándo se usó la key por última vez.

**No hace:** no gestiona roles de usuario (la API no tiene concepto de usuario autenticado).

## Middleware `AuthenticateApiKey` (`app/Http/Middleware/AuthenticateApiKey.php`)

### Flujo

1. Lee el header `X-Api-Key`.
2. Calcula `hash('sha256', $rawKey)`.
3. Busca en `api_keys` por `key_hash` (bypasa TenantScope con `withoutGlobalScopes()`).
4. Verifica que la key, la sucursal y el tenant estén activos.
5. Aplica rate limiting (60 req/min por key).
6. Inyecta `tenant` en el contenedor y `branch_id` / `tenant_id` en el request.
7. Actualiza `last_used_at` silenciosamente (`updateQuietly`).

### Seguridad

- **La key nunca se almacena en texto plano.** Solo se guarda el hash SHA-256.
- La key se muestra al usuario **una sola vez** al generarla.
- El prefijo del hash (8 chars) se muestra en el panel para identificación visual.

### Rate Limiting

- 60 peticiones por minuto por API Key.
- Usa `RateLimiter` de Laravel con key `api-key:{id}`.
- Responde `429` con header `Retry-After` cuando se excede.

### Respuestas de error

| Código | Causa |
|--------|-------|
| `401` | Header `X-Api-Key` ausente |
| `401` | Key no encontrada o inactiva |
| `401` | Sucursal o tenant inactivos |
| `429` | Rate limit excedido |

## Registro

En `bootstrap/app.php`:

```php
'auth.apikey' => AuthenticateApiKey::class,
```

Aplicado en `routes/api.php`:

```php
Route::prefix('v1')->middleware('auth.apikey')->group(...)
```
