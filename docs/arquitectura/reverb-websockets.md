# Reverb y WebSockets

Laravel Reverb da los WebSockets del sistema. Es lo que hace que la venta aparezca sola en la pantalla del cajero en cuanto sale de la báscula, y lo que evita que dos personas editen la misma venta a la vez.

## Responsabilidades

- Avisar al cajero en el momento en que llega una venta nueva o cambia una existente.
- Difundir los bloqueos de venta para que la concurrencia sea visible en todas las pantallas.
- Avisar de los cambios de turno que mueven el esperado en caja.
- Entregar al instante los avisos personales que además quedan guardados (ver [modulos/avisos.md](../modulos/avisos.md)).
- Aislar por sucursal: nadie recibe eventos de una sucursal que no es la suya.

**No hace:** no persiste mensajes — quien no está conectado no los recupera; para lo que no se puede perder está la tabla `notifications`. No sustituye a la API: el evento avisa, los datos definitivos se leen por HTTP.

## Decisiones

| Decisión | Por qué |
|----------|---------|
| **`ShouldBroadcastNow`, sin colas** | El evento sale en la misma petición. Un cajero no puede esperar a que un worker despache; y esta aplicación no corre colas en producción para esto. |
| **Todo dispatch va envuelto** | Consecuencia de lo anterior: la llamada a Reverb es síncrona, así que un Reverb caído convertía en 500 una petición cuyo trabajo ya estaba confirmado. Ver `App\Support\SafeBroadcast`. |
| **Los eventos son señales, no datos** | El payload lleva identificadores, no cifras ni relaciones. Quien recibe lee por HTTP con sus propios permisos. Así un canal compartido no filtra nada y el evento no cuesta consultas. |
| **Canales privados siempre** | Los datos de venta son del negocio. Cada suscripción se autoriza contra el usuario. |
| **Un canal por sucursal** | Es el límite natural de aislamiento: una sucursal no ve el movimiento de otra. |
| **Autorización con `web` y `sanctum`** | El mismo canal lo consumen la web (sesión Inertia) y el hub de escritorio (token Sanctum). |
| **WebSocket principal, sondeo de respaldo** | El socket se cae. Sin red de seguridad, la pantalla se queda quieta y en silencio, y nadie distingue eso de "no ha pasado nada". Ver [Resiliencia](#resiliencia-el-socket-se-cae). |

## Canales

Los dos se declaran en `routes/channels.php`.

| Canal | Quién puede suscribirse | Para qué |
|-------|-------------------------|----------|
| `sucursal.{branchId}` | Usuarios cuyo `branch_id` coincide | Todo el movimiento de ventas y turnos de esa sucursal |
| `App.Models.User.{id}` | El propio usuario | Avisos personales (notificaciones de Laravel). Lo escuchan la isla de la web y la del hub |

```php
// El guard por defecto de la ruta decide: 'web' en la app Inertia,
// 'sanctum' en el hub (la ruta /api/v1/hub/realtime/auth corre tras
// auth:sanctum, que fija sanctum como guard por defecto).
Broadcast::channel('sucursal.{branchId}', function ($user, $branchId) {
    return $user->branch_id === (int) $branchId;
});
```

> **El aislamiento entre empresas se apoya en que `branches.id` es único global.** El canal no lleva `tenant_id`: dos empresas no pueden colisionar porque no comparten ids de sucursal. Es correcto, pero es una invariante implícita — si algún día las sucursales pasan a numerarse por empresa, este canal se rompe en silencio.

> El canal `agenda.user.{userId}` se retiró el 2026-09-24 junto con el evento `AgendaItemAssigned`, que nadie escuchaba: la asignación de tareas es ahora un aviso por `App.Models.User.{id}`.

> **El admin-empresa no tiene `branch_id`**, así que no puede suscribirse a ningún canal de sucursal. Cualquier tiempo real para el nivel empresa exigiría un canal nuevo (`empresa.{tenantId}`), que hoy no existe a propósito.

Para el hub hay dos endpoints propios: `GET /api/v1/hub/realtime/config` le entrega los parámetros de conexión, y `POST /api/v1/hub/realtime/auth` autoriza la suscripción con el token Sanctum. Ver [api/hub.md](../api/hub.md).

## Los seis eventos

Todos implementan `ShouldBroadcastNow` y viven en `app/Events/`.

### `NewExternalSale` → `sucursal.{branchId}`

Una venta **nueva** creada desde fuera de la web. Es el evento que da sentido al sistema: la báscula registra la venta y aparece en la cola del cajero sin recargar.

**Carga:** `{ sale: SaleResource }` (con los items cargados). **Es el único que lleva el objeto completo**, porque `useSaleQueue` lo pinta directamente sin esperar a la recarga.
**Lo dispara:** `Api\SaleController` (venta de báscula) y `Public\OrderController` (pedido del menú QR).

> Se emite **también en la rama idempotente**: cuando una báscula reintenta con el mismo `client_reference`, el reintento llega justamente porque el primer envío no terminó bien, que es cuando el aviso se perdió. Sin repetirlo, la venta quedaba guardada pero invisible en la mesa hasta que alguien recargara.

### `SaleUpdated` → `sucursal.{branchId}`

Una venta **existente** cambió: se cobró, se editaron sus items, se canceló, se reabrió, se pidió cancelarla, o se le asignó un cliente.

**Carga:** `{ sale_id, folio, status }`. Nada más: ningún consumidor ha leído nunca el objeto completo, y cargar cuatro relaciones para tirarlas costaba consultas en el evento de mayor tráfico del sistema.
**Lo dispara:** la mesa de trabajo (sucursal y caja), pagos, edición de items, solicitudes y resoluciones de cancelación, `OrderLinkService` y `AssignCustomerToSale`, más sus equivalentes en la API del hub.

> Si añades una operación que modifica una venta, **debe emitirlo**, o las demás pantallas se quedan con datos viejos.

### `SaleLocked` / `SaleUnlocked` → `sucursal.{branchId}`

Alguien empezó o terminó de editar una venta. El bloqueo dura 5 minutos y se renueva con latidos.

**Carga:** `{ sale_id, locked_by, locked_by_name }` y `{ sale_id }`. El nombre viaja para que la otra pantalla pueda decir *quién* la tiene, no sólo que está ocupada.
**Los dispara:** `Sucursal\SaleLockController` y `Api\Hub\SaleController`.

### `CustomerGlobalPaymentChanged` → `sucursal.{branchId}`

Un cobro global FIFO cambió el saldo de varias ventas de un cliente de una vez, al aplicarse (`applied`) o al cancelarse (`reverted`).

**Carga:** `{ customer_payment_id, folio, customer_id, action, amount_applied, sales_affected_count, sale_ids }`.
**Lo dispara:** `CustomerGlobalPaymentService::broadcastPaymentChange()`, llamado desde la web, la API del hub y el asistente IA.

> Sustituye a una ráfaga de `SaleUpdated`, uno por venta saldada. Cada uno provocaba una recarga completa de la mesa: diez ventas eran diez recargas seguidas en todas las pantallas de la sucursal.

### `ShiftUpdated` → `sucursal.{branchId}`

El turno de caja se abrió, se cerró, o entró o salió un retiro.

**Carga:** `{ shift_id, user_id, reason }` — **sin cifras a propósito**. Viaja por un canal que comparten todos los usuarios de la sucursal; quien lo recibe pide su propio turno por HTTP, y ese endpoint sólo devuelve el del usuario autenticado.
**Lo dispara:** `ShiftService` (`open`, `close`, `addWithdrawal`, `removeWithdrawal`).

> Lo que mueve el esperado en caja minuto a minuto son los cobros, y ésos ya emiten `SaleUpdated`. El panel de turno escucha los dos; no hacía falta un evento por cobro.

### Notificaciones (`BroadcastNotificationCreated`) → `App.Models.User.{id}`

Las notificaciones con canal `broadcast` viajan solas por este canal: cancelaciones, equipos y, desde 2026-09-24, los recordatorios y asignaciones de la agenda. Ver [modulos/avisos.md](../modulos/avisos.md).

> **Salen síncronas a propósito.** `BroadcastNotificationCreated` es `ShouldBroadcast` (encolado) y aquí no hay colas, así que cada notificación fija `toBroadcast()->onConnection('sync')`. Hasta el 2026-09-24 no lo hacía y ningún aviso llegaba en vivo. Como con `SafeBroadcast`, el envío síncrono puede lanzar: cada `notify()` lleva su guardia por destinatario. Cada una declara además `broadcastType()`, para que el `type` en vivo sea el mismo que el guardado.

## Resiliencia: el socket se cae

Reverb no reenvía nada de lo que ocurrió mientras no había conexión, así que el WebSocket por sí solo no puede ser la única fuente de verdad de una pantalla.

El patrón nació en el hub y hoy es el mismo en las dos superficies:

| | Con socket vivo | Sin socket |
|---|---|---|
| Mesa de trabajo (web y hub) | sondeo cada 20 s | sondeo cada 4 s |
| Panel de turno (hub) | sondeo cada 45 s | sondeo cada 12 s |

Tres reglas que lo acompañan:

1. **Al recuperarse el socket se refresca de inmediato**, porque lo perdido no vuelve solo.
2. **Las recargas se agrupan 300 ms.** Una sola venta genera `NewExternalSale`, `SaleLocked` y `SaleUpdated` casi seguidos; sin agrupar, cada uno lanzaba su propia petición y su propio repintado.
3. **El estado se ve.** Un chip dice "En vivo", "Reconectando…" o cada cuánto se está consultando. Sin él, un socket caído es indistinguible de una sucursal tranquila.

En la web esto vive en `resources/js/lib/realtimeState.js` (traducción del estado de pusher), `resources/js/lib/branchChannel.js` (registro del canal) y el composable `useBranchRealtime`. En el hub, en `src/renderer/lib/realtimeState.js` y `composables/useRealtime.js`.

### El canal es compartido

En la mesa de trabajo, tres consumidores escuchan el mismo canal a la vez: `useSaleQueue`, `useSaleLock` y la propia página. En el hub son dos pantallas (Mesa de Trabajo y Turno).

`Echo.leave()` abandona el canal **entero**, no los listeners de quien llama. Por eso ambos lados llevan cuenta de sus consumidores y sólo abandonan cuando se va el último. No es una precaución teórica: en la web `useSaleQueue` hacía `leave()` al desmontarse y dejaba mudos a los otros dos, y en el hub el `disconnect()` de la pantalla que se va llegaba después del `connect()` de la que llega, porque vue-router monta antes de desmontar.

### Dónde falta envolver: se comprueba, no se confía

`tests/Feature/BroadcastResilienceTest` sustituye el transporte por uno que siempre falla —un Reverb caído— y exige que la operación siga respondiendo bien: tomar y soltar el lock de una venta, asignar una tarea de agenda (hoy un aviso, con su propia guardia), y emparejar o desemparejar un pedido web. Es la red que atrapa un `dispatch()` suelto antes de que llegue al mostrador.

> 2026-09-08: esos cinco caminos estaban sin envolver. Los dos de `OrderLinkService` además emitían **dentro** de la transacción, así que un fallo del transporte revertía un emparejamiento ya hecho y mantenía las filas bloqueadas durante la llamada HTTP; ahora se emiten después de confirmar. En agenda el evento ni siquiera lo consumía nadie: se arriesgaba un 500 por un aviso que no escuchaba ningún cliente (el evento se retiró el 2026-09-24).

### El emisor no recibe su propio eco

Los eventos de venta emitidos desde la web usan `SafeBroadcast::toOthers()`. Quien cobra ya recibe la venta actualizada en la respuesta de Inertia; su propio eco sólo disparaba una segunda recarga de los mismos datos. Laravel excluye al emisor por el `X-Socket-ID` que Echo inyecta en las peticiones de axios; si no hay socket id —el hub con token, una báscula, un comando— el evento llega a todos, como antes.

## Cómo escucha el frontend

| Quién escucha | Eventos | Qué hace al recibirlos |
|---------------|---------|------------------------|
| `useSaleQueue` | `NewExternalSale` | Inserta la venta en la cola y suena el beep |
| `useSaleLock` | `SaleLocked` · `SaleUnlocked` | Marca la venta como ocupada o libre |
| `useBranchRealtime` (las dos mesas) | `SaleUpdated` · `CustomerGlobalPaymentChanged` | Recarga parcial de Inertia, agrupada |
| `useNotifications` (la isla de avisos) | notificaciones de `App.Models.User.{id}` | Las trata como señal: relee `GET /avisos` y anuncia lo nuevo. Sondea 20 s / 4 s según el socket |
| Hub `SalesView` | los cuatro de venta + `CustomerGlobalPaymentChanged` | Refresca la lista, agrupado |
| Hub `ShiftView` | `ShiftUpdated` · `SaleUpdated` · `CustomerGlobalPaymentChanged` | Refresca el esperado, salvo si el cajero está capturando |

```js
// useBranchRealtime: el socket manda, el sondeo respalda
const { live, recovering, refreshSoon } = useBranchRealtime(branchId, {
    handlers: { SaleUpdated: (e, soon) => soon() },
    refresh: () => router.reload({ only: ['sales'], preserveScroll: true }),
});
```

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

> `config/broadcasting.php` tiene `'default' => env('BROADCAST_CONNECTION', 'null')`. **Si la variable falta, los eventos se descartan sin error ni log.** No es un fallo ruidoso: simplemente nada llega nunca.

## Levantarlo

```bash
./vendor/bin/sail artisan reverb:start
```

> **Es un proceso aparte que hay que arrancar a mano**; `composer run dev` no lo incluye. Sin él las pantallas siguen funcionando —el sondeo de respaldo las sostiene— pero todo llega con segundos de retraso y el chip dirá que no hay tiempo real.

## Riesgos y límites

- **Nada se persiste en el socket.** Quien estaba desconectado no recupera los eventos perdidos; su pantalla se pone al día leyendo por HTTP. Lo que no se puede perder va además a `notifications`.
- **Una operación que cambia una venta y no emite `SaleUpdated`** deja al resto de las pantallas con datos obsoletos hasta el siguiente sondeo.
- **El bloqueo de venta no es una garantía transaccional**, es un aviso cooperativo de 5 minutos: reduce colisiones, no las hace imposibles.
- **Un Reverb que cuelga suma latencia.** Todos los envíos son síncronos (eventos y notificaciones): un Reverb caído que rechaza se absorbe con las guardias, pero uno que no responde retrasa la petición que emite. No hay timeout propio en el cliente.

## Tests

- PHP: `tests/Feature/Api/SaleIdempotencyTest.php` (Reverb caído y reintentos), `tests/Feature/Ventas/CobroGlobalBroadcastTest.php`, `tests/Feature/Turnos/ShiftUpdatedBroadcastTest.php`, `tests/Feature/Sucursal/CancelacionAvisosTest.php`, `tests/Feature/Notifications/NotificationBroadcastTest.php` (notificaciones por `sync`, `broadcastType()` y guardia por destinatario).
- JS (`npm test`, vitest): `tests/js/realtimeState.test.js` (caída y recuperación del socket) y `tests/js/branchChannel.test.js` (canal compartido) y `tests/js/useNotifications.test.js` (el motor de la isla). En el hub, `test/realtimeState.test.js` y `test/realtimeChannelSharing.test.js`.
