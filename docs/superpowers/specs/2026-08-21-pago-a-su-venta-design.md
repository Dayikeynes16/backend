# Del pago a su venta

- **Estado:** Implementado (2026-08-21) — doc vivo: [Ventas](../../modulos/ventas.md)
- **Fecha:** 2026-08-21
- **Repos afectados:** `carniceria-saas` (Pagos e Historial, cajero y sucursal). **El hub queda fuera a propósito** — ver §7.
- **Alcance:** navegación y lectura. No cambia cómo se registran, editan o cancelan pagos, ni el reparto FIFO de un cobro global.

---

## 1. Problema

Desde la pantalla de Pagos, la pregunta natural frente a un cobro es **«¿de qué venta fue esto?»**. Hoy se responde a medias y solo para una parte de la gente.

**El admin-sucursal** tiene la respuesta escondida: en el panel del pago, el folio de la venta es texto rojo subrayado (`Sucursal/Pagos/Index.vue:475`) que lleva al Historial filtrado por ese folio. Nada indica que sea una acción, y al llegar aparece una lista de **una sola fila que todavía hay que clickear** para ver la venta — ambos historiales arrancan con `selected = null` y el mensaje «Selecciona una venta».

**El cajero** no tiene la respuesta: el mismo folio es un `<span>` plano (`Caja/Pagos/Index.vue:358`). Y aunque lo tuviera, no habría a dónde ir: **el Historial de caja no busca por folio**. Sus filtros son fecha, producto y rango de total (`Caja/HistorialController.php:19-21`), con la fecha fijada a hoy por defecto.

**El cobro global es el peor caso.** Un abono FIFO reparte dinero entre varias ventas del cliente; en el listado se colapsa a un renglón y su panel muestra folio del cobro, cliente, método y monto — y ahí se corta. Nunca dice a qué ventas se fue el dinero. Es justamente donde más se pregunta uno.

## 2. Decisión

Un solo comportamiento para **admin-sucursal y cajero**, sin diferencias por rol. El destino sigue siendo el Historial: es donde vive el detalle de una venta y no hay razón para inventar otra superficie.

| Situación | Hoy | Queda |
|---|---|---|
| Pago de una venta, admin | folio subrayado → Historial, hay que clickear la fila | botón **«Ver venta ↗»** → Historial con la venta **ya abierta** |
| Pago de una venta, cajero | folio en texto plano, sin salida | igual que el admin |
| Cobro global, ambos | el panel se corta en el folio del cobro | sección **«Ventas que pagó»**: folio, fecha, monto aplicado y botón por venta |
| Buscar un folio en el Historial de caja | no existe | buscador de folio que **ignora la fecha** |

### Por qué buscar por folio ignora la fecha

Es la regla que el Historial de sucursal ya aplica (`SaleHistoryController.php:47`) y sin ella la función se cae sola: un cobro de hoy sobre una venta de anteayer —el caso típico de fiado— aterrizaría en un historial vacío.

### Por qué el cajero nunca llega a un callejón sin salida

El Historial de caja solo lista ventas donde ese cajero registró algún pago, y Pagos de caja solo muestra sus propios pagos. El salto siempre parte de un pago suyo, así que la venta destino siempre está en su historial. Lo mismo vale para las ventas de un cobro global: si él hizo el cobro, tiene un pago en cada una.

### Lo que queda fuera

- **El cliente de la venta en el panel de caja.** Sucursal lo muestra, caja no (`Caja/PagosController` no carga `sale.customer`). Es una diferencia real entre las dos pantallas, pero no es esta.
- **Ver la venta sin salir de Pagos** (modal). Se consideró y se descartó: más superficie para el mismo dato que el Historial ya presenta bien.

## 3. Backend

### 3.1 Búsqueda por folio en el Historial de caja

`Caja\HistorialController::index` gana un filtro `search`, calcado del de sucursal:

- Con `search`: `where('folio', 'ilike', "%{$term}%")` y **sin** filtro de fecha.
- Sin `search`: el comportamiento de hoy (fecha del request, o today).
- El resto del alcance no se toca: sigue restringido a `Payment::where('user_id', $user->id)`, y los filtros de producto y rango de total siguen combinándose.

`search` se suma al array `filters` que baja a Inertia.

### 3.2 Ventas de un cobro global

Ambos `PagosController` (Caja y Sucursal) añaden a su `with`:

```php
'customerPayment.payments:id,customer_payment_id,sale_id,amount',
'customerPayment.payments.sale:id,folio,status,created_at',
```

`CustomerPayment::payments()` ya existe como `HasMany`. El volumen está acotado por las ventas con saldo del cliente al momento del cobro.

## 4. Frontend

### 4.1 Dos componentes nuevos

Las dos pantallas de Pagos son primas, no gemelas: ~280 de ~500 líneas difieren, porque sucursal tiene edición de pagos, filtro por cajero y resumen del día. **No se fusionan.** Se extrae solo lo que si no habría que escribir dos veces:

- **`Components/Pagos/SaleHeaderBand.vue`** — la banda con folio, badge de estado y el botón «Ver venta ↗».
- **`Components/Pagos/CustomerPaymentSales.vue`** — la lista de ventas de un cobro global, cada renglón con su botón.

Ambos reciben la URL de destino ya resuelta (prop o función `historyUrl(folio)`), de modo que no conocen rutas, tenant ni rol. Eso los hace probables en aislamiento y evita que el componente decida a dónde puede ir cada quién.

### 4.2 Auto-selección en el Historial

En `Caja/Historial.vue` y `Sucursal/Historial/Index.vue`: al montar, si `filters.search` viene y la lista trae **exactamente una** venta, se selecciona sola. Con cero o varias, no se toca nada — seleccionar la primera de varias sería adivinar.

### 4.3 Buscador de folio en `Caja/Historial.vue`

Input junto a los filtros existentes, con el mismo debounce que ya usan producto y rangos, y chip de filtro activo para poder limpiarlo.

## 5. Pruebas

Feature (Pest/PHPUnit):

- El cajero encuentra por folio una venta suya **de otro día** — cubre la regla de ignorar la fecha.
- El cajero **no** encuentra por folio una venta donde no cobró — el alcance no se ensancha por buscar.
- Sin `search`, el Historial de caja sigue mostrando el día — no hay regresión.
- El panel de un cobro global expone sus ventas, en caja y en sucursal.

## 6. Documentación

- `docs/modulos/ventas.md` — el salto pago → venta y el buscador de folio en caja.
- `docs/modulos/clientes-cobro-global.md` — las ventas visibles desde el panel del cobro.

## 7. El hub

`carniceria-hub` tiene sus propias `PaymentsView` y `HistoryView`, y su dirección visual es paridad con la web. Este trabajo **no lo toca**, por decisión explícita de alcance (2026-08-21). Queda como paridad pendiente, a levantar en su propio ciclo.
