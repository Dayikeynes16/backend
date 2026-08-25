# Pantallas del cajero

Las superficies de `/{tenant}/caja`, el puesto de cobro. Es la pantalla que más horas al día está abierta en el negocio.

## Responsabilidades

- Cobrar las ventas que llegan de la báscula, en tiempo real.
- Abrir y cerrar turno, con su corte y conciliación.
- Dar al cajero, si su sucursal lo habilita, gastos, compras y cartera de clientes.

**No hace:** el cajero no edita los items de una venta ni fija precios preferenciales — eso es de admin-sucursal. Cancelar una venta lo **solicita**, no lo ejecuta.

## Las pantallas

Todas cuelgan de `/{tenant}/caja` y usan `CajeroLayout`.

| Pantalla | Ruta | Componente | Depende de |
|----------|------|------------|------------|
| **Mesa de trabajo** | `/` | `Caja/Workbench.vue` | — |
| **Turno** | `turno` | `Caja/Turno/Open.vue` · `Active.vue` | — |
| **Corte** | `turno/corte/{shift}` | `Caja/Turno/Corte.vue` | — |
| **Historial** | `historial` | `Caja/Historial.vue` | — |
| **Pagos** | `pagos` | `Caja/Pagos/Index.vue` | — |
| **Gastos** | `gastos` | `Caja/Gastos/Index.vue` | `cashier_expenses_enabled` |
| **Compras** | `compras` | `Caja/Compras/Index.vue` | `cashier_purchases_enabled` |
| **Clientes** | `clientes` · `clientes/{id}` | `Caja/Clientes/Index.vue` · `Show.vue` | `cashier_customers_enabled` |

Las tres últimas son módulos opcionales: el admin-empresa los enciende por sucursal y el middleware `branch.feature` las bloquea si están apagadas. Ver [modulos/sucursales.md](../modulos/sucursales.md).

### Mesa de trabajo (`Caja/Workbench.vue`)

El corazón del puesto. Lista las ventas de la sucursal y permite cobrarlas.

- **Recibe ventas en vivo**: se suscribe a `sucursal.{branchId}` y `NewExternalSale` inserta la venta sin recargar. Ver [cola-ventas.md](cola-ventas.md).
- **Se refresca ante cambios ajenos**: escucha `SaleUpdated` y hace `router.reload({ only: ['sales'], preserveScroll: true })` — recarga parcial, sin perder la posición.
- **Bloquea la venta que se está cobrando** con `useSaleLock`: 5 minutos con latidos, y las demás pantallas ven quién la tiene.
- Cobro con varios métodos de pago, con adjunto de comprobante en transferencias.
- Asignar cliente, enlace de WhatsApp, y solicitud de cancelación (que aprueba admin-sucursal).
- Si `FEATURE_WEB_ORDERS` está encendido, vincula pedidos web con la venta real.

### Turno (`Caja/Turno/*`)

Un mismo controlador decide qué renderizar según el estado:

- **`Open.vue`** — no hay turno abierto: pide el fondo inicial.
- **`Active.vue`** — turno en curso: totales por método de pago, retiros de efectivo, y el cierre.
- **`Corte.vue`** — el corte de un turno ya cerrado, con su conciliación.

Detalle del cálculo y la conciliación: [modulos/corte-de-caja.md](../modulos/corte-de-caja.md).

### Historial, Pagos

**Historial** lista las ventas ya cerradas de la sucursal, con filtros. **Pagos** lista los cobros registrados y permite llegar desde el pago a su venta.

### Gastos, Compras, Clientes

Versiones para cajero de los módulos de sucursal, con menos potestades. Gastos y Compras incluyen captura con IA (foto, voz y texto). Clientes da cartera, alta, edición y cobro FIFO, **sin** precios preferenciales ni cancelación de cobros.

Ver [modulos/gastos.md](../modulos/gastos.md), [modulos/compras.md](../modulos/compras.md) y [modulos/clientes-caja.md](../modulos/clientes-caja.md).

## Código sin ruta

Hay cuatro componentes y tres controladores del cajero que **ya no son alcanzables**: ninguna ruta apunta a ellos.

| Componente | Lo renderizaba | Estado |
|------------|----------------|--------|
| `Caja/Queue.vue` | `Caja\SaleController` | Sin ruta |
| `Caja/Shift.vue` | `Caja\ShiftController` | Sin ruta |
| `Caja/OpenShift.vue` | `Caja\ShiftController` | Sin ruta |
| `Caja/Dashboard.vue` | `Caja\DashboardController` | Sin ruta |

Son la generación anterior de estas pantallas, sustituida por `Workbench.vue` y `Turno/*`. Se quedaron en el árbol al migrar. **Hasta 2026-08-23 este documento describía justo esas cuatro y ninguna de las vivas.**

Antes de borrarlas conviene confirmar que ningún test ni enlace las referencia.

## Tests

`tests/Feature/Caja/` — 11 archivos que cubren mesa de trabajo, turno, cobro, gastos, compras y clientes del cajero.
