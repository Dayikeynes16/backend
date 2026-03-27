# Cola de Ventas (Caja)

Pantalla principal del cajero. Muestra las ventas pendientes de cobro en tiempo real.

## Responsabilidades

- Mostrar ventas con estado `pending` de la sucursal del cajero.
- Recibir ventas nuevas en tiempo real vía Reverb/Echo (sin recargar página).
- Permitir al cajero cobrar cada venta con un botón.
- Reproducir sonido de notificación al llegar una venta nueva.

**No hace:** no permite crear ventas, modificar ítems, ni cancelar ventas.

## Composable `useSaleQueue` (`resources/js/composables/useSaleQueue.js`)

Encapsula la lógica de la cola de ventas y la suscripción a Reverb.

### API

```js
const { sales, initSales, addSale, removeSale } = useSaleQueue(branchId);
```

| Propiedad/Método | Descripción |
|---|---|
| `sales` | `ref([])` — array reactivo de ventas pendientes |
| `initSales(array)` | Carga ventas iniciales (del servidor) |
| `addSale(sale)` | Agrega una venta al inicio de la cola (evita duplicados) |
| `removeSale(saleId)` | Elimina una venta de la cola (tras cobrar) |

### Suscripción a Reverb

Al montar el componente, se suscribe al canal privado `sucursal.{branchId}` y escucha el evento `NewExternalSale`. Al desmontar, limpia la suscripción.

### Sonido de notificación

Usa la Web Audio API para generar un tono de 880Hz por 0.5 segundos. No requiere archivos de audio externos. Falla silenciosamente si el navegador no lo permite.

## Página `Caja/Queue.vue`

### Datos del servidor (Inertia props)

| Prop | Tipo | Descripción |
|---|---|---|
| `pendingSales` | Array | Ventas pendientes al cargar la página |
| `branchId` | Number | ID de la sucursal del cajero (para Echo) |
| `tenant` | Object | Tenant activo (para generar rutas) |

### Card de venta

Cada venta se muestra como una card con:

- **Header:** folio (ej: S-00001), badge de método de pago (coloreado), tiempo transcurrido (actualiza cada segundo)
- **Body:** lista de ítems con nombre, cantidad × precio, subtotal
- **Footer:** total de la venta + botón "Cobrar"

### Animaciones

- Entrada: fade in + slide down (0.4s)
- Salida: fade out + slide right (0.3s)
- Implementadas con `<TransitionGroup>` de Vue

### Flujo de cobro

1. Cajero presiona "Cobrar"
2. `router.patch` → `caja.sales.complete`
3. Controller: `status=completed`, `user_id=cajero`, `completed_at=now()`
4. On success: `removeSale(id)` — la card desaparece con animación

## Controller (`app/Http/Controllers/Caja/SaleController.php`)

| Método | Ruta | Descripción |
|--------|------|-------------|
| `index` | `GET /{tenant}/caja` | Carga ventas pendientes de la sucursal del cajero |
| `complete` | `PATCH /{tenant}/caja/sales/{sale}/complete` | Marca venta como completada |

### Seguridad de `complete`

- Verifica que `$sale->branch_id === $user->branch_id` (403 si no coincide)
- Verifica que `$sale->status === 'pending'` (rechaza si ya procesada)
- Asigna `user_id` del cajero autenticado
