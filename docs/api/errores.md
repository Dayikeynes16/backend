# Errores de la API de básculas

Códigos y mensajes que devuelve `/api/v1/*`, la superficie que consumen las básculas. Todos los errores llegan como JSON con un campo `message` en español, pensado para mostrarse tal cual en la pantalla del equipo.

> La API del hub (`/api/v1/hub/*`) tiene sus propios códigos, documentados en [hub.md](hub.md). La API local del hub, en `carniceria-hub/docs/api-local.md`.

## Resumen

| Código | Causa | Qué debe hacer la báscula |
|--------|-------|---------------------------|
| `401` | API Key ausente, inválida, expirada, o sucursal/empresa inactivas | Pedir reconfiguración. No reintentar |
| `422` | Validación fallida, o productos inexistentes | No reintentar: el envío es inválido tal cual |
| `429` | Rate limit superado | Esperar los segundos de `Retry-After` |
| `503` | La transcripción no estuvo disponible | Reintentar más tarde, o escribir a mano |
| `5xx` | Fallo del servidor | Reintentar con espera |

> Regla práctica para quien implemente un cliente: **`4xx` no se reintenta, `5xx` sí.** Es la misma que aplica el outbox del hub.

## 401 — autenticación

`AuthenticateApiKey` devuelve cuatro mensajes distintos, y conviene distinguirlos porque llevan a acciones distintas:

| Mensaje | Significa |
|---------|-----------|
| `API Key requerida. Envía el header X-Api-Key.` | Falta el header |
| `API Key inválida o inactiva.` | No corresponde a ninguna key viva, o fue revocada |
| `API Key expirada. Genera una nueva desde el panel de administración.` | Existía y venció |
| `La sucursal o empresa asociada está inactiva.` | La key es válida, pero su sucursal o su empresa están dadas de baja |

Los tres últimos exigen intervención en el panel: reintentar no cambia nada.

## 422 — validación

### POST /api/v1/sales

Reglas reales del endpoint:

| Campo | Regla |
|-------|-------|
| `items` | requerido, array, al menos 1 |
| `items.*.product_id` | requerido, entero |
| `items.*.quantity` | requerido, numérico, mayor que 0 |
| `items.*.presentation_id` | opcional, entero |
| `payment_method` | requerido: `cash`, `card` o `transfer` |
| `origin_name` | opcional, máx. 100 |
| `contact_name` | opcional, máx. 255 |
| `client_reference` | opcional, máx. 64 |

Formato estándar de Laravel:

```json
{
    "message": "The items field is required.",
    "errors": { "items": ["The items field is required."] }
}
```

**Productos que no existen** se validan aparte, después de las reglas, y devuelven un mensaje propio con la lista de los culpables:

```json
{
    "message": "Productos no válidos.",
    "errors": {
        "items": ["Producto 999 no existe o está inactivo."]
    }
}
```

Ocurre también cuando el producto existe pero pertenece a otra sucursal, o está desactivado. Suele significar que **el catálogo de la báscula está viejo**: conviene refrescarlo antes de reintentar.

## 429 — límites

Dos límites distintos, con contadores separados:

| Límite | Alcance | Header |
|--------|---------|--------|
| 60 peticiones por minuto | Por API Key, todo `/api/v1/*` | `Retry-After` |
| 120 dictados por hora | Por API Key, solo `POST /transcribe` (`AI_SCALE_TRANSCRIBE_PER_HOUR`) | `Retry-After` |

```json
{ "message": "Rate limit excedido. Intenta de nuevo en 42 segundos." }
{ "message": "Has excedido el límite de dictados por hora." }
```

**Respeta siempre `Retry-After`.** Un cliente que reintenta de inmediato se mantiene bloqueado y consume el cupo del resto de las básculas de la sucursal.

## 503 — transcripción no disponible

```json
{ "message": "No se pudo transcribir el audio." }
```

`POST /api/v1/transcribe` no pudo hablar con el proveedor de voz, o falta la clave configurada. Es el único `503` de esta API. La venta **no** depende de esto: el nombre siempre se puede escribir a mano.

Errores de validación del audio (`422`): formato no permitido (`webm`, `ogg`, `oga`, `mp3`, `mpga`, `m4a`, `mp4`, `wav`, `flac`, `aac`) o tamaño por encima del máximo configurado, 10 MB por defecto.

## 404

```json
{ "message": "No query results for model [Sale] 999." }
```

En `GET /api/v1/sales/{id}` cuando esa venta no existe **o no es de la sucursal de la key** — desde fuera son indistinguibles, a propósito.

## Lo que no es un error

`POST /api/v1/sales` con un `client_reference` que ya existe devuelve **`201` con la venta existente**, no un conflicto. Es idempotencia deliberada: para quien reintenta, indistinguible de un envío que salió bien a la primera. Ver [endpoints.md](endpoints.md).
