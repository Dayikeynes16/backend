# Cola de ventas y bloqueo de venta

Los dos composables que sostienen la mesa de trabajo: uno hace que la venta de la báscula aparezca sola, el otro evita que dos personas cobren la misma venta a la vez.

## Responsabilidades

- Recibir ventas nuevas por WebSocket e insertarlas en la lista sin recargar.
- Avisar con un sonido cuando llega una venta.
- Marcar qué venta está siendo editada y por quién, en todas las pantallas de la sucursal.

**No hace:** no consulta la API por su cuenta — las ventas iniciales llegan como props de Inertia. No garantiza exclusión mutua: el bloqueo es cooperativo (ver [Límites](#límites)).

## `useSaleQueue` (`resources/js/composables/useSaleQueue.js`)

```js
const { sales, initSales, addSale, removeSale } = useSaleQueue(branchId);
```

| Miembro | Qué hace |
|---------|----------|
| `sales` | `ref([])` con las ventas de la cola, cada una con `arrived_at` |
| `initSales(array)` | Carga las que vinieron del servidor, tomando `created_at` como `arrived_at` |
| `addSale(sale)` | La pone al principio y suena el aviso. **Ignora duplicados por `id`** |
| `removeSale(id)` | La saca de la lista, tras cobrarla |

**Suscripción.** Al montar se suscribe a `sucursal.{branchId}` y escucha **solo `NewExternalSale`**. Al desmontar deja de escuchar y abandona el canal.

> `SaleUpdated` **no** pasa por este composable. Lo escuchan directamente `Pages/Sucursal/Workbench.vue` y `Pages/Caja/Workbench.vue`, y en vez de tocar el estado disparan `router.reload({ only: ['sales'], preserveScroll: true })`.

**El sonido** se genera con la Web Audio API — un tono de 880 Hz que decae en medio segundo. No hay archivo de audio, y si el navegador lo bloquea falla en silencio, sin romper la llegada de la venta.

## `useSaleLock` (`resources/js/composables/useSaleLock.js`)

```js
const { lockSale, unlockSale, isLockedByOther, lockedByName, lockedSales } =
    useSaleLock(branchId, userId, lockRoute, unlockRoute, heartbeatRoute);
```

Las tres rutas se pasan como plantillas con el marcador `__SALE__`, que el composable sustituye por el id. Así el mismo composable sirve a la mesa de sucursal y a la de caja, que tienen rutas distintas.

| Miembro | Qué hace |
|---------|----------|
| `lockSale(id)` | Pide el bloqueo y arranca los latidos |
| `unlockSale()` | Libera el bloqueo actual y para los latidos |
| `isLockedByOther(id)` | Si otra persona la tiene tomada |
| `lockedByName(id)` | El nombre de quien la tiene, para poder decirlo en pantalla |
| `lockedSales` | `ref({})` con el estado de bloqueo por venta |

**Cómo funciona el bloqueo**

- El servidor lo concede por **5 minutos** (`SaleLockController`); si ya está tomado por otro, responde con el conflicto y el composable lo refleja.
- Mientras se edita, un **latido cada 60 segundos** lo renueva.
- Al desmontar el componente se libera.
- Si la persona cierra la pestaña, un manejador de `beforeunload` manda la liberación con el token CSRF, para no dejar la venta trabada cinco minutos.

**Suscripción.** Escucha `SaleLocked` y `SaleUnlocked` en `sucursal.{branchId}`, así que el bloqueo es visible en todas las pantallas de la sucursal, no solo en la que lo pidió. `SaleLocked` trae `locked_by_name` precisamente para poder mostrar *quién*.

## Dónde se usan

| Pantalla | `useSaleQueue` | `useSaleLock` |
|----------|:--------------:|:-------------:|
| `Pages/Caja/Workbench.vue` | ✅ | ✅ |
| `Pages/Sucursal/Workbench.vue` | ✅ | ✅ |
| `Pages/Caja/Queue.vue` | ✅ | — |

> `Caja/Queue.vue` ya no tiene ruta que la alcance: es la generación anterior de la mesa de caja. Ver [pantallas-cajero.md](pantallas-cajero.md#código-sin-ruta).

## Límites

- **El bloqueo es cooperativo, no transaccional.** Reduce las colisiones; no las hace imposibles. La consistencia real la garantizan los servicios de dominio dentro de su transacción.
- **Nada se recupera al reconectar.** Los eventos no se persisten: una pestaña que estuvo desconectada se pone al día al recargar.
- **Un cambio de venta que no emita `SaleUpdated`** deja las demás pantallas con datos viejos y sin ningún síntoma visible.

## Ver también

- [arquitectura/reverb-websockets.md](../arquitectura/reverb-websockets.md) — los cinco eventos y los tres canales.
- [pantallas-cajero.md](pantallas-cajero.md) — las pantallas que consumen esto.
- [modulos/ventas.md](../modulos/ventas.md) — el ciclo de vida de la venta.
