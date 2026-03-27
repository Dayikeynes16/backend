# Errores API

Todos los errores devuelven JSON con un campo `message` descriptivo.

## Códigos de error

| Código | Causa | Ejemplo |
|--------|-------|---------|
| `401` | API Key ausente | `{"message": "API Key requerida. Envía el header X-Api-Key."}` |
| `401` | API Key inválida o inactiva | `{"message": "API Key inválida o inactiva."}` |
| `401` | Sucursal/tenant inactivos | `{"message": "La sucursal o empresa asociada está inactiva."}` |
| `404` | Recurso no encontrado | `{"message": "No query results for model [Sale] 999."}` |
| `422` | Validación fallida | `{"message": "...", "errors": {"items": [...]}}` |
| `429` | Rate limit (60/min por key) | `{"message": "Rate limit excedido..."}` + header `Retry-After` |

## Errores de validación (422)

### POST /api/v1/sales

- `items` es requerido y debe ser un array con al menos 1 elemento.
- `items.*.product_id` debe ser un entero existente.
- `items.*.quantity` debe ser numérico y mayor a 0.
- `payment_method` debe ser `cash`, `card` o `transfer`.
- Si un `product_id` no existe o está inactivo en la sucursal, se devuelve:

```json
{
    "message": "Productos no válidos.",
    "errors": {
        "items": ["Producto 999 no existe o está inactivo."]
    }
}
```

## Rate Limiting

- 60 peticiones por minuto por API Key.
- El header `Retry-After` indica cuántos segundos esperar.
- El conteo se reinicia cada 60 segundos.
