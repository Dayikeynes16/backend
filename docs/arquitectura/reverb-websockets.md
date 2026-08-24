# Reverb y WebSockets

Laravel Reverb da los WebSockets del sistema. Es lo que hace que la venta aparezca sola en la pantalla del cajero en cuanto sale de la báscula, y lo que evita que dos personas editen la misma venta a la vez.

## Responsabilidades

- Avisar al cajero en el momento en que llega una venta nueva o cambia una existente.
- Difundir los bloqueos de venta para que la concurrencia sea visible en todas las pantallas.
- Notificar a un usuario cuando le asignan un pendiente de la agenda.
- Aislar por sucursal: nadie recibe eventos de una sucursal que no es la suya.

**No hace:** no persiste mensajes — quien no está conectado no los recupera. No maneja reconexiones (Echo lo hace solo). No sustituye a la API: el evento avisa, los datos definitivos se leen por HTTP.

## Decisiones

| Decisión | Por qué |
|----------|---------|
| **`ShouldBroadcastNow`, sin colas** | El evento sale en la misma petición. Un cajero no puede esperar a que un worker despache; y esta aplicación no corre colas en producción para esto. |
| **Canales privados siempre** | Los datos de venta son del negocio. Cada suscripción se autoriza contra el usuario. |
| **Un canal por sucursal** | Es el límite natural de aislamiento: una sucursal no ve el movimiento de otra. |
| **Autorización con `web` y `sanctum`** | El mismo canal lo consumen la web (sesión Inertia) y el hub de escritorio (token Sanctum). |

## Canales

Los tres se declaran en `routes/channels.php`.

| Canal | Quién puede suscribirse | Para qué |
|-------|-------------------------|----------|
| `sucursal.{branchId}` | Usuarios cuyo `branch_id` coincide | Todo el movimiento de ventas de esa sucursal |
| `agenda.user.{userId}` | El propio usuario | Pendientes que le asignan |
| `App.Models.User.{id}` | El propio usuario | Canal estándar de notificaciones de Laravel |

```php
// El guard por defecto de la ruta decide: 'web' en la app Inertia,
// 'sanctum' en el hub (la ruta /api/v1/hub/realtime/auth corre tras
// auth:sanctum, que fija sanctum como guard por defecto).
Broadcast::channel('sucursal.{branchId}', function ($user, $branchId) {
    return $user->branch_id === (int) $branchId;
});
```

Para el hub hay dos endpoints propios: `GET /api/v1/hub/realtime/config` le entrega los parámetros de conexión, y `POST /api/v1/hub/realtime/auth` autoriza la suscripción con el token Sanctum. Ver [api/hub.md](../api/hub.md).

## Los cinco eventos

Todos implementan `ShouldBroadcastNow` y viven en `app/Events/`.

### `NewExternalSale` → `sucursal.{branchId}`

Una venta **nueva** creada desde fuera de la web. Es el evento que da sentido al sistema: la báscula registra la venta y aparece en la cola del cajero sin recargar.

**Carga:** `{ sale: SaleResource }` (con los items cargados).
**Lo dispara:** `Api\SaleController` (venta de báscula) y `Public\OrderController` (pedido del menú QR).

### `SaleUpdated` → `sucursal.{branchId}`

Una venta **existente** cambió: se cobró, se editaron sus items, se canceló, se reabrió, se le asignó un cliente, o un cobro global FIFO la tocó.

**Carga:** `{ sale: SaleResource }`.
**Lo dispara:** trece sitios, tanto de la web como de la API del hub — mesa de trabajo, pagos, edición de items, solicitudes de cancelación, `CustomerGlobalPaymentService`, `OrderLinkService` y `AssignCustomerToSale`.

> Es el evento de mayor tráfico. Si añades una operación que modifica una venta, **debe emitirlo**, o las demás pantallas se quedan con datos viejos.

### `SaleLocked` → `sucursal.{branchId}`

Alguien empezó a editar una venta. El bloqueo dura 5 minutos y se renueva con latidos.

**Carga:** `{ sale_id, locked_by, locked_by_name }` — el nombre viaja para que la otra pantalla pueda decir *quién* la tiene, no solo que está ocupada.
**Lo dispara:** `Sucursal\SaleLockController` y `Api\Hub\SaleController`.

### `SaleUnlocked` → `sucursal.{branchId}`

Se liberó el bloqueo.

**Carga:** `{ sale_id }`.
**Lo dispara:** los mismos dos controladores.

### `AgendaItemAssigned` → `agenda.user.{userId}`

A un usuario le asignaron un pendiente. **Es el único evento que no va al canal de sucursal**: se dirige a una persona, no a un puesto de trabajo.

**Carga:** `{ id, title, type, ... }` del pendiente.
**Lo dispara:** `Agenda\AgendaController`.

## Cómo escucha el frontend

Las suscripciones están repartidas entre composables y páginas:

| Quién escucha | Evento | Qué hace al recibirlo |
|---------------|--------|------------------------|
| `useSaleQueue` | `NewExternalSale` | Inserta la venta en la cola sin recargar |
| `useSaleLock` | `SaleLocked` · `SaleUnlocked` | Marca la venta como ocupada o libre |
| `Pages/Sucursal/Workbench.vue` | `SaleUpdated` | `router.reload({ only: ['sales'] })` |
| `Pages/Caja/Workbench.vue` | `SaleUpdated` | idem |

Los dos composables están documentados en [frontend/cola-ventas.md](../frontend/cola-ventas.md).

```js
// useSaleQueue
window.Echo.private(`sucursal.${branchId}`)
    .listen('NewExternalSale', (e) => { /* inserta en la cola */ });

// Workbench.vue — recarga parcial, conservando el scroll
saleUpdateChannel.listen('SaleUpdated', () =>
    router.reload({ only: ['sales'], preserveScroll: true }));
```

> `SaleUpdated` no se consume desde un composable, sino desde las dos mesas de trabajo, y no trae los datos al estado: dispara una recarga parcial de Inertia. La carga del evento existe, pero hoy no se usa en el frontend.

El cliente se construye en `resources/js/bootstrap.js` con las variables `VITE_REVERB_*`. **Esas se compilan dentro del bundle:** si cambias una, hay que reiniciar Vite o el valor viejo sigue vivo.

## Configuración

```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=123456
REVERB_APP_KEY=local
REVERB_APP_SECRET=local
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

En desarrollo los valores de `APP_ID`/`KEY`/`SECRET` pueden ser cualquiera, pero las cuatro `VITE_*` deben espejear a las de arriba. Puerto `8080`, expuesto en `compose.yaml`.

## Levantarlo

```bash
./vendor/bin/sail artisan reverb:start
```

> **Es un proceso aparte que hay que arrancar a mano**; `composer run dev` no lo incluye. Sin él, la cola del cajero se queda vacía y **no aparece ningún error que lo explique**. Es el tropiezo más común de quien llega nuevo al proyecto.

## Riesgos y límites

- **Nada se persiste.** Un cajero que estaba desconectado no recupera los eventos perdidos; su pantalla se pone al día al recargar, leyendo por HTTP.
- **Una operación que cambia una venta y no emite `SaleUpdated`** deja al resto de las pantallas con datos obsoletos, sin ningún síntoma visible hasta que alguien recarga.
- **El bloqueo de venta no es una garantía transaccional**, es un aviso cooperativo de 5 minutos: reduce colisiones, no las hace imposibles.

## Tests

`tests/Feature/` cubre el disparo de los eventos con `Event::fake()` en los flujos de venta, cobro y bloqueo.
