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
    "payment_method": "cash"
}
```

**Lógica:**

1. Valida que todos los `product_id` existan y estén activos en la sucursal.
2. Calcula subtotales: `quantity × price` (para todos los unit_type).
3. Genera folio consecutivo por sucursal: `S-00001`, `S-00002`, etc.
4. Crea `Sale` con `status=pending`, `origin=api`.
5. Crea `SaleItem`s con snapshots del producto (nombre, precio, unit_type).
6. Dispara `NewExternalSale` (broadcast vía Reverb).
7. Retorna 201 con la venta creada.

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
