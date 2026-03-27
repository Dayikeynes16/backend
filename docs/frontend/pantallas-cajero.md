# Pantallas del Cajero

El cajero tiene 4 pantallas. No tiene acceso a configuración, productos ni usuarios.

## 1. Abrir Turno (`Caja/OpenShift.vue`)

Pantalla inicial si el cajero no tiene turno abierto. Muestra un botón para abrir turno.

- Ruta: `GET /{tenant}/caja/turno/abrir`
- Acción: `POST /{tenant}/caja/turno` → crea CashRegisterShift → redirige a Queue
- Muestra flash success si viene de cerrar un turno anterior

## 2. Cola de Ventas (`Caja/Queue.vue`)

Pantalla principal. Scroller vertical de cards con ventas pendientes. Requiere turno abierto.

- Ruta: `GET /{tenant}/caja`
- Datos: ventas pending de la sucursal + branchId para Echo
- Tiempo real: composable `useSaleQueue` (ver `docs/frontend/cola-ventas.md`)
- Cada card: folio, badge de pago, items, total, tiempo transcurrido, botón Cobrar
- Animaciones: TransitionGroup (fade+slide)
- Si no hay turno → redirige a OpenShift

## 3. Dashboard del Día (`Caja/Dashboard.vue`)

Resumen de ventas cobradas en el turno activo. Solo lectura.

- Ruta: `GET /{tenant}/caja/dashboard`
- 4 cards de métricas: total del turno, transacciones, promedio, desglose por método
- Tabla de últimas 10 ventas cobradas (folio, método, total, hora)
- Muestra hora de apertura del turno

## 4. Corte de Caja (`Caja/Shift.vue`)

Vista del turno activo para cerrarlo.

- Ruta: `GET /{tenant}/caja/turno`
- Muestra resumen detallado: ventas cobradas, efectivo, tarjeta, transferencia, total
- Botón "Cerrar Turno" (con confirmación)
- Al cerrar: calcula totales, marca closed_at, redirige a OpenShift

## Navegación

El cajero navega entre estas pantallas usando el layout de Breeze (AuthenticatedLayout). Las rutas están bajo `/{tenant}/caja/...`.

## Historial de cortes (admin-sucursal)

El admin de sucursal ve los cortes cerrados en `/{tenant}/sucursal/cortes`:

- Tabla con: cajero, apertura, cierre, ventas, efectivo, tarjeta, transferencia, total
- Filtro por fecha
- Paginado (15 por página)
