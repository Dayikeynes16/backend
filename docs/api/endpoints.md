# Endpoints API Pública

**Base URL:** `/api/v1/`
**Autenticación:** header `X-Api-Key: {key}`
**Formato:** JSON · UTF-8

---

## GET /api/v1/branches/me

Información de la sucursal asociada a la API Key.

**Controller:** `Api\BranchController@me`

**Respuesta 200:**

```json
{
    "data": {
        "id": 1,
        "name": "Sucursal Centro",
        "address": "Av. Juárez 123",
        "phone": "993-123-4567",
        "schedule": "Lun-Sáb 7am-8pm",
        "status": "active"
    }
}
```

---

## GET /api/v1/products

Catálogo activo de la sucursal. Paginado (20 por página).

**Controller:** `Api\ProductController@index`

**Query params:**
- `?search=bistec` — búsqueda por nombre (ilike)
- `?unit_type=kg|piece|cut` — filtro por tipo
- `?page=1` — paginación

**Respuesta 200:**

```json
{
    "data": [
        {
            "id": 12,
            "name": "Bistec de res",
            "description": null,
            "unit_type": "cut",
            "price": 180.00,
            "image_url": null,
            "status": "active"
        }
    ],
    "links": { ... },
    "meta": { "total": 24, "per_page": 20, "current_page": 1, ... }
}
```

---

## POST /api/v1/sales

Registra una venta. Dispara evento Reverb al cajero.

**Controller:** `Api\SaleController@store`

**Body:**

```json
{
    "items": [
        { "product_id": 12, "quantity": 1.5 },
        { "product_id": 8,  "quantity": 2 }
    ],
    "payment_method": "cash",
    "origin_name": "Balanza 1",
    "contact_name": "Doña Mary",
    "client_reference": "a1b2c3d4-..."
}
```

- `origin_name`: opcional, máx. 100. Identifica el equipo en la venta; sin él se guarda `"Bascula"`.
- `contact_name`: opcional, máx. 255. Nombre libre para identificar la venta en la cola de la Mesa de Trabajo. **Es una etiqueta, no un cliente:** no crea ni asocia registros en `customers` y `customer_id` sigue en `null`. En blanco se guarda como `null`. Añadido el 2026-08-13; las básculas que no lo mandan no cambian en nada.
- `client_reference`: opcional, máx. 64. **Clave de idempotencia generada por el equipo.**

**Idempotencia por `(branch_id, client_reference)` (desde 2026-08-11):** si ya existe una venta de esa sucursal con ese `client_reference`, el endpoint **devuelve la venta existente sin crear otra**, con la misma forma y el mismo `201` que una creación normal — para quien reintenta, indistinguible de un envío que salió bien a la primera.

Esto hace seguro el reintento del outbox del hub, que reintenta ante cualquier fallo de red o `5xx`. Antes de esto, una venta cuya respuesta se perdía por el camino se creaba **dos veces**. Garantizado en base de datos por un índice único `(branch_id, client_reference)` (migración `2026_08_11_134846_add_client_reference_to_sales_table.php`). Las básculas que no lo envían dejan la columna en `null` y no participan: siguen funcionando igual que antes.

**Quién lo manda hoy:** el hub, la báscula Android y —desde 2026-08-23— la báscula Electron de las Surface, que hasta entonces no lo enviaba y por tanto no estaba protegida. Ahí la referencia identifica **el cobro, no el envío**: se genera al primer intento, se repite mientras el cajero reintenta con el mismo carrito, y se descarta cuando el cobro sale bien o el carrito cambia.

> El reintento **vuelve a emitir `NewExternalSale`**. Llega justamente cuando el primer envío no terminó bien, que es cuando el aviso se pierde; sin repetirlo la venta queda guardada pero invisible en la mesa de trabajo hasta que alguien recargue. Repetirlo es seguro: el cliente deduplica por `id`.

**Lógica:**

1. Si viene `client_reference` y ya existe esa venta en la sucursal, **re-emite `NewExternalSale`**, la devuelve y termina.
2. Valida que todos los `product_id` existan y estén activos en la sucursal.
3. Calcula subtotales: `quantity × price` (para todos los unit_type).
4. Genera folio consecutivo por sucursal: `S-00001`, `S-00002`, etc.
5. Crea `Sale` con `status=active`, `origin=api`.
6. Crea `SaleItem`s con snapshots del producto (nombre, precio, unit_type).
7. Dispara `NewExternalSale` (broadcast vía Reverb) **envuelto en `SafeBroadcast`**.
8. Retorna 201 con la venta creada.

> El paso 7 va envuelto desde 2026-08-23. Los eventos son `ShouldBroadcastNow`, así que la llamada a Reverb ocurre dentro de esta misma petición: si Reverb estaba caído, la excepción convertía en `500` una petición cuya venta **ya estaba confirmada en la base de datos**. Para la báscula eso es indistinguible de un fallo — reintenta, y sin `client_reference` acababa creando una venta gemela.

**Respuesta 201:**

```json
{
    "id": 456,
    "folio": "S-00456",
    "status": "pending",
    "payment_method": "cash",
    "total": 490.00,
    "origin": "api",
    "completed_at": null,
    "created_at": "2026-03-27T14:32:00+00:00",
    "items": [
        {
            "id": 1,
            "product_id": 12,
            "product_name": "Bistec de res",
            "unit_type": "cut",
            "quantity": 1.5,
            "unit_price": 180.00,
            "subtotal": 270.00
        }
    ]
}
```

**Validaciones:**
- `items`: required, array, min 1
- `items.*.product_id`: required, integer
- `items.*.quantity`: required, numeric, > 0
- `payment_method`: required, in:cash,card,transfer

---

## GET /api/v1/sales

Historial de ventas de la sucursal.

**Controller:** `Api\SaleController@index`

**Query params:**
- `?date=2026-03-27` — filtro por fecha
- `?status=pending|completed|cancelled` — filtro por estado
- `?page=1` — paginación (20 por página)

**Respuesta 200:** Colección paginada de `SaleResource` con items incluidos.

---

## GET /api/v1/sales/{id}

Estado de una venta específica. Útil para polling desde el kiosco.

**Controller:** `Api\SaleController@show`

**Respuesta 200:**

```json
{
    "data": {
        "id": 456,
        "folio": "S-00456",
        "status": "completed",
        "payment_method": "cash",
        "total": 490.00,
        "origin": "api",
        "completed_at": "2026-03-27T14:35:12+00:00",
        "created_at": "2026-03-27T14:32:00+00:00",
        "items": [ ... ]
    }
}
```

**Nota:** Solo devuelve ventas de la sucursal asociada a la API Key. Retorna 404 si la venta no pertenece a esa sucursal.

## POST /api/v1/transcribe

Dictado del nombre de la venta: recibe un audio y devuelve el texto. Añadido el 2026-08-13.

**Controller:** `Api\TranscriptionController@store`

**Body:** `multipart/form-data` con un campo `audio`.

Formatos aceptados: `webm, ogg, oga, mp3, mpga, m4a, mp4, wav, flac, aac`. Tamaño máximo: `ai.expenses.max_audio_bytes` (10 MB por defecto). La báscula graba en m4a.

**Respuesta 200:**

```json
{ "text": "Doña Mary" }
```

**Errores:** `401` sin API key válida · `422` sin audio, formato no aceptado o demasiado grande · `429` al exceder el límite · `503` si Whisper falla.

**Límite:** `ai.scale.transcribe_per_hour` (120 por defecto, variable `AI_SCALE_TRANSCRIBE_PER_HOUR`), contado **por API key** y no por sucursal: cada báscula tiene la suya, y un equipo con un botón atascado no debe dejar sin dictado al de al lado. Es independiente del límite general de 60 req/min de la Scale API, que se sigue aplicando.

Existe aparte del endpoint de dictado del asistente (`{tenant}/asistente/transcribir`) porque aquél exige sesión web y la báscula se autentica con `X-Api-Key`. La transcripción en sí es la misma: `AssistantTranscriber` (Whisper, español). **No persiste el audio.** No descuenta del presupuesto mensual de IA del tenant.

Detalle del flujo completo: [ventas.md](../modulos/ventas.md#nombre-de-la-venta-desde-la-báscula).

## POST /api/v1/devices/heartbeat

Latido de equipo: la báscula se presenta y reporta versión, batería y red. Añadido el 2026-09-16. **Aditivo:** las básculas que no lo llaman no cambian en nada; ningún endpoint existente se tocó.

**Controller:** `Api\DeviceHeartbeatController@store` · **Validación:** `Api\DeviceHeartbeatRequest` (la misma que usa el hub)

**Body (JSON):**

```json
{
  "device_id": "a1b2c3d4-surface",
  "kind": "scale_windows",
  "name": "Caja 1",
  "app_version": "0.4.1",
  "os": "Windows 11",
  "model": "Surface Go 3",
  "battery": { "level": 63, "charging": false },
  "connection": "cloud",
  "local_ip": "192.168.100.21"
}
```

- `device_id`: **requerido**, `[A-Za-z0-9._-]{1,64}`. Lo genera la app una vez y lo conserva; es la identidad del equipo dentro del tenant. La API key dice la sucursal.
- `kind`: **requerido**, uno de `scale_android`, `scale_windows`, `hub_windows`, `hub_android`.
- `name` (≤ 100), `app_version` (≤ 32), `os` (≤ 100), `model` (≤ 100): opcionales. **Lo que no viene no pisa lo guardado.**
- `battery`: opcional. Objeto `{ level: 0–100, charging: bool }`, o `null` para limpiar (equipo sin batería).
- `connection`: opcional, `cloud` o `hub`: contra qué está vendiendo el equipo.
- `local_ip`: opcional, IP válida.

**Respuesta** `201` la primera vez que se ve ese `device_id`, `200` después:

```json
{
  "data": {
    "device_id": "a1b2c3d4-surface",
    "name": "Caja 1",
    "display_name": "Caja Norte",
    "status": "online",
    "server_time": "2026-09-16T10:12:00-06:00"
  }
}
```

`display_name` es el alias puesto desde la web (o `null`); `status` es el estado derivado (`online`, `battery_low`, `stale`, `silent`).

**Errores:** `401` sin API key válida · `422` `device_id` ausente o inválido, `kind` desconocido, `battery.level` fuera de 0–100 · `429` al exceder los 60 req/min.

**Cadencia esperada del cliente:** al arrancar, cada 5 min, al cruzar el 20 % y el 10 % de batería, y al enchufar o desenchufar. Un equipo dado de baja desde la web que vuelve a latir se reactiva solo. Un `device_id` que reporta desde otra sucursal del mismo tenant se muda de sucursal (no se duplica).

Módulo completo: [equipos.md](../modulos/equipos.md).
