# Endpoints API de básculas (`/api/v1/*`)

Superficie que consumen las básculas: `bascula` (tablets Surface) y `bascula-android`. Ambas hablan directo con la nube; las básculas emparejadas a un hub usan además la API local del hub, que es un contrato distinto.

**Base URL:** `/api/v1/`
**Autenticación:** header `X-Api-Key: {key}`
**Formato:** JSON · UTF-8
**Rate limit:** 60 req/min por key

---

> ## ⚠️ Esta API no admite cambios incompatibles
>
> **Hay básculas en producción que no se auto-actualizan.** Un cambio incompatible aquí no falla en las pruebas: falla en el mostrador, dejando equipos sin poder vender hasta que alguien vaya físicamente a actualizarlos.
>
> **Está prohibido:** quitar un campo de una respuesta, renombrarlo, cambiar su tipo o su formato, volver requerido un parámetro que era opcional, cambiar un código de estado, o retirar un endpoint.
>
> **Todo cambio debe ser aditivo:** campos nuevos siempre opcionales, con un valor por defecto para el cliente que no los mande.
>
> **Precedente a seguir** — al añadir el nombre de venta (2026-08-13), `origin_name` se definió opcional precisamente con esta garantía: *las básculas viejas no lo mandan y todo sigue funcionando igual*. Ver `docs/superpowers/plans/2026-08-13-nombre-en-venta-de-bascula.md`.
>
> Si un cambio no puede ser aditivo, la salida no es romper el contrato: es versionar la superficie (`/api/v2/`) y mantener v1 viva mientras queden equipos usándola.

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

## GET /api/v1/categories

Categorías activas de la sucursal, para agrupar el catálogo en la interfaz de la báscula. Sin paginación: se devuelven todas, ordenadas por nombre.

**Controller:** `Api\CategoryController@index`

**Respuesta 200:**

```json
{
    "data": [
        { "id": 3, "name": "Cerdo" },
        { "id": 1, "name": "Res" },
        { "id": 2, "name": "Pollo" }
    ]
}
```

Solo devuelve `id` y `name`, y únicamente las categorías con `status = 'active'` de la sucursal a la que pertenece la API Key.

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

**Lógica:**

1. Si viene `client_reference` y ya existe esa venta en la sucursal, la devuelve y termina.
2. Valida que todos los `product_id` existan y estén activos en la sucursal.
3. Calcula subtotales: `quantity × price` (para todos los unit_type).
4. Genera folio consecutivo por sucursal: `S-00001`, `S-00002`, etc.
5. Crea `Sale` con `status=pending`, `origin=api`.
6. Crea `SaleItem`s con snapshots del producto (nombre, precio, unit_type).
7. Dispara `NewExternalSale` (broadcast vía Reverb).
8. Retorna 201 con la venta creada.

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

