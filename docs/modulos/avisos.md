# Avisos (notificaciones persistentes)

Lo que un usuario tiene que enterarse **aunque no estuviera mirando la pantalla**.

## Por qué existe

El WebSocket entrega al instante pero no guarda nada: quien estaba desconectado en ese segundo no se entera nunca. Para un beep de venta nueva da igual —la venta está en la lista y se ve al entrar—, pero no para algo que espera una decisión.

El caso que lo motivó: un cajero pedía cancelar una venta de varios miles y **no salía ningún aviso**. El administrador no se enteraba hasta entrar por su cuenta a la pantalla de cancelaciones, y el cajero se quedaba esperando un desenlace del que tampoco se le informaba.

La regla que queda: **el evento avisa, la fila garantiza.**

## Responsabilidades

- Guardar avisos dirigidos a un usuario concreto y entregarlos en vivo si está conectado.
- Que se puedan leer después, marcar como leídos y contar los pendientes.

**No hace:** no manda correo (para eso están las `Notification` con canal `mail`), no agrupa ni repite, no caduca solos.

## Cómo funciona

Es el sistema estándar de Laravel, sin capa propia encima:

1. Una `Notification` con `via() === ['database', 'broadcast']`.
2. `database` escribe en la tabla `notifications`.
3. `broadcast` la emite por el canal privado `App.Models.User.{id}`, que ya estaba declarado y autorizado en `routes/channels.php`.
4. La campana (`NotificationBell.vue`) lee por HTTP al montar —el socket no reenvía lo anterior— y escucha el canal para lo que llegue después.

### Aislamiento

No hay `tenant_id` en la tabla, **a propósito**: el aislamiento lo da `notifiable_id`, que es un usuario y ya pertenece a un tenant y a una sucursal. La relación `$user->notifications()` no puede alcanzar las de otro. Añadir la columna sería un segundo camino para lo mismo, y dos caminos se contradicen tarde o temprano.

Por eso las rutas viven **fuera** del prefijo de tenant: no hay nada que aislar más allá del propio usuario, y la campana tiene que funcionar igual en los cuatro paneles.

### Niveles

El campo `level` del payload decide cuánto grita el aviso:

| Nivel | Significa | Ejemplo |
|-------|-----------|---------|
| `action` | Algo espera una decisión suya | Solicitud de cancelación por resolver |
| `important` | Ya ocurrió y le cambia el trabajo | Su cancelación fue aprobada |
| `info` | Contexto, se lee de pasada | Su cancelación fue rechazada |

## Rutas

Todas bajo `auth`, sin prefijo de tenant.

| Método | Ruta | Nombre | Qué hace |
|--------|------|--------|----------|
| GET | `/avisos` | `notifications.index` | Últimos 20 del usuario + contador de no leídos |
| PATCH | `/avisos/{notification}/leido` | `notifications.read` | Marca uno (404 si no es suyo) |
| PATCH | `/avisos/leidos` | `notifications.read-all` | Marca todos los suyos |

## Avisos existentes

| Notificación | Va a | Cuándo |
|--------------|------|--------|
| `SaleCancellationRequested` | Administradores de la sucursal de la venta | Un cajero pide cancelar |
| `SaleCancellationResolved` | El cajero que la pidió | El administrador aprueba o rechaza |
| `DeviceBatteryLow` | Administradores de la sucursal del equipo + admin-empresa | Un equipo baja del umbral de aviso de su sucursal sin cargar; otra vez al umbral urgente. El payload lleva `severity` — [equipos](equipos.md) |
| `DeviceRegistered` | Los mismos | Primer latido de un equipo nuevo |
| `DeviceSilent` | Los mismos | Un equipo lleva > 30 min sin reportar dentro de la ventana 08–20 h |
| `DeviceOutdated` | Los mismos | Un equipo sigue en una versión menor a la publicada hace > 24 h |

Los cinco puntos de entrada del circuito de cancelaciones (mesa de sucursal, mesa de caja y la API del hub para las tres acciones) pasan por `SaleCancellationNotifier`, que resuelve destinatarios en un solo sitio. Repetir esa regla cinco veces es la forma habitual de que una de las cinco se quede sin avisar.

## Añadir un aviso nuevo

1. Una clase en `app/Notifications/` con `via()` devolviendo `['database', 'broadcast']`.
2. `toArray()` con `type`, `level`, `title`, `body` y los ids que necesite la UI.
3. `toBroadcast()` devolviendo `new BroadcastMessage($this->toArray($notifiable))`.
4. Si el aviso necesita destino calculado (roles, sucursal), un servicio como `SaleCancellationNotifier` en vez de repetir la consulta en cada controlador.
5. Si el aviso debe llevar a algún sitio, añadir el caso en `linkFor()` de `NotificationBell.vue`.

**Nunca dejes que el envío tumbe la operación.** Cuando se notifica, el trabajo real ya está guardado; un fallo se registra y se sigue.

## Límites conocidos

- **La campana pide 20 y no pagina.** Suficiente hoy; si un usuario acumula muchos, los viejos sólo se ven marcando leídos.
- **Nada las borra.** No hay limpieza programada de avisos antiguos.
- **El Hub Electron las muestra desde la 1.3.0** con su «isla» (ver `carniceria-hub/docs/avisos.md`). La campana web, en cambio, pierde el enlace de los avisos que llegan en vivo: el evento trae el `type` pisado por el nombre de clase (`BroadcastNotificationCreated::broadcastType()`), así que `linkFor()` no los reconoce hasta recargar. Pendiente: `broadcastType()` en las notificaciones, y que `linkFor()` conozca `sale.cancellation.approved`/`rejected`.
- **El admin-empresa las recibe si es destinatario**, pero hoy ninguna lo tiene como tal: las cancelaciones son cosa de sucursal.

## Tests

`tests/Feature/NotificationInboxTest.php` (la bandeja sólo devuelve lo propio), `tests/Feature/Hub/NotificationInboxTest.php` (la misma bandeja bajo Sanctum, con el aislamiento intacto) y `tests/Feature/Sucursal/CancelacionAvisosTest.php` (los tres momentos del circuito, aislamiento entre sucursales y entre empresas, y que un fallo al notificar no rompe la solicitud).
