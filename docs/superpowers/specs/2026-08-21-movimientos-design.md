# Movimientos: qué se le hizo a una venta después de cobrarla

- **Estado:** en implementación
- **Fecha:** 2026-08-21
- **Repo afectado:** `carniceria-saas`. El hub **sí** participa como origen de eventos (sus escrituras se registran), pero **no** recibe la pantalla.
- **Alcance:** registro y lectura. No cambia ninguna regla sobre quién puede modificar una venta.

---

## 1. Problema

El dueño sospecha que alguien aprendió sus credenciales y modifica ventas a escondidas: baja precios y ajusta lo que hay que entregar en efectivo. Hoy no hay forma de mirar eso.

No es que no se registre nada. `sale_item_changes` lleva desde mayo de 2026 una bitácora completa de los cambios a los productos de una venta —evento, snapshot antes y después, diff campo a campo, motivo, usuario y hora—. El problema es doble:

1. **Solo se ve venta por venta.** El historial se consulta desde el detalle de una venta concreta (`SaleItemController@history`). No existe ninguna pantalla que responda «¿qué se tocó hoy?».
2. **Falta la mitad que más importa.** De las cuatro formas de sacarle dinero a una venta ya cobrada, solo una deja rastro completo:

| Evento | Qué queda hoy |
|---|---|
| Cambiar precio o cantidad de un producto | Completo: antes, después, quién, cuándo, motivo |
| Editar un pago | Solo `payments.updated_by`. **El monto anterior se pierde** |
| Borrar un pago | Nada. La fila desaparece |
| Cancelar una venta | `cancelled_by` + `cancel_reason` en la venta |
| Reabrir una venta cobrada | Nada |
| Asignar o quitar el cliente | Nada |

Editar un pago de $680 a $380 y borrar el sobrante cuadra la caja sin dejar huella. Eso es exactamente «lo que tiene que entregar».

## 2. Lo que este registro puede y no puede decir

Importa dejarlo escrito, porque la expectativa equivocada haría inútil la pantalla.

**El registro va a decir que los cambios los hizo el dueño**, porque se harían con su cuenta. Eso no es un defecto: es la señal. El dueño mira la lista y reconoce —o no— sus propios cambios.

Para que ese reconocimiento sea posible se guarda el contexto de cada cambio. Aquí hay un límite real: **la IP no distingue equipos dentro del local**, porque todas las máquinas de la sucursal salen por el mismo router y el servidor ve una sola IP pública. Lo que sí distingue:

- **Dentro o fuera del local**: un cambio desde una IP distinta a la habitual de la sucursal es una señal fuerte por sí sola.
- **Tipo de equipo**: Windows, Android, Mac, iPhone — derivado del `User-Agent`.
- **Puerta de entrada**: la web, el hub de sucursal o una báscula son superficies distintas.

Esto responde «¿esto se hizo como yo lo hago normalmente?», no «¿qué tableta exacta fue?».

Y el alcance de la función: **deja ver lo que pasó, no lo impide**. Contra unas credenciales copiadas, la medida es cambiar la contraseña.

## 3. Dónde vive el registro

Se reutiliza `audit_logs` —la tabla que ya usan gastos y compras a través de `AuditLogger`— en vez de crear una nueva. Gana cuatro columnas:

| Columna | Para qué |
|---|---|
| `branch_id` (nullable) | Filtrar y aislar por sucursal sin recorrer la relación polimórfica |
| `ip_address` (45, nullable) | Distinguir «desde el local» de «desde fuera» |
| `user_agent` (255, nullable) | Derivar el tipo de equipo |
| `amount_effect` (decimal 12,2, nullable) | Efecto en dinero del evento. **`null` = evento no monetario** |

`amount_effect` se guarda en vez de calcularse al vuelo porque la pantalla ordena por él y suma el neto del periodo: en SQL es una columna indexable; al vuelo obligaría a traer todo a memoria.

**`sale_item_changes` no se toca.** Conserva sus cinco meses de historia y sigue alimentando el historial dentro del detalle de una venta. `SaleItemEditor` pasa a escribir en las dos tablas. Sí, los cambios de producto quedan duplicados; es preferible a migrar historia de auditoría, que es justo la que no se puede perder. La pantalla nueva lee solo de `audit_logs`, así que tiene una única fuente.

### Eventos nuevos en `AuditEvent`

`item_added`, `item_updated`, `item_removed`, `payment_updated`, `payment_deleted`, `reopened`, `customer_assigned`, `customer_removed`. Los existentes `cancelled` y `payment_cancelled` se reutilizan. **`payment_added` no**: hoy solo lo escribe el módulo de compras (`PurchasePaymentService`), y registrar cada cobro de venta llenaría la pantalla con todas las ventas del día — el ruido que esta pantalla existe para evitar (corregido el 2026-08-23).

### Cómo se calcula `amount_effect`

Negativo significa «reduce lo que hay que entregar».

| Evento | Efecto |
|---|---|
| `item_updated` | `subtotal_después − subtotal_antes` |
| `item_removed` | `−subtotal` |
| `item_added` | `+subtotal` |
| `payment_updated` | `monto_después − monto_antes` |
| `payment_deleted` | `−monto` |
| `cancelled` | `−total` de la venta |
| `reopened` | `null` |
| `customer_assigned` / `customer_removed` | `null` |

**El cambio de cliente es deliberadamente no monetario.** Pasar una venta a fiado saca el dinero del efectivo del día pero no lo pierde: contarlo como pérdida daría un neto falso. Se muestra en la lista, en gris, fuera del total.

## 4. Dónde se escribe

| Punto | Qué registra |
|---|---|
| `SaleItemEditor` | Los tres eventos de item, además de lo que ya escribe en `sale_item_changes` |
| `Sucursal\PaymentController@update` / `@destroy` | `payment_updated` con monto y método antes/después; `payment_deleted` |
| `Api\Hub\PaymentController` (equivalentes) | Lo mismo desde el hub |
| `Sucursal\WorkbenchController@assignCustomer` y su par de `Caja` | `customer_assigned` / `customer_removed` con el nombre del cliente |
| `Api\Hub\SaleController@assignCustomer` | Lo mismo desde el hub |
| Transiciones de estado (`updateStatus`) | `reopened` cuando una venta Completed vuelve a Active |

`AuditLogger` captura `branch_id`, `ip_address` y `user_agent` por su cuenta desde el request, para que ningún punto de escritura tenga que acordarse. En cola o consola no hay request: los tres quedan `null` y eso es correcto.

## 5. La pantalla

Un renglón **por venta modificada**, desplegable, ordenado por efecto en dinero descendente. Encima, el resumen del periodo: número de ventas tocadas y efecto neto —que excluye los eventos no monetarios—.

Cada renglón: folio, número de cambios, resumen en una línea, efecto y contexto (tipo de equipo, dentro/fuera del local). Al desplegar, un renglón por evento con hora, qué cambió, valor antes → después y efecto.

**Marca de patrón sospechoso:** cuando en una misma venta hay un cambio de precio y un cambio de pago **dentro de la misma hora**, el renglón se destaca. Es la firma del fraude que se está buscando; un cambio suelto casi siempre es una corrección legítima.

Filtros: rango de fechas (por defecto hoy), sucursal —solo para admin-empresa—, tipo de evento y usuario.

## 6. Quién entra

- **admin-empresa**: siempre, en `/{tenant}/empresa/movimientos`, con todas las sucursales.
- **admin-sucursal**: en `/{tenant}/sucursal/movimientos`, solo su sucursal, y **solo si `branch_admin_movements_enabled`** está encendido en la ficha de la sucursal. Nuevo flag por sucursal, `default false` — un registro de vigilancia se enciende a propósito, no por omisión. Se gobierna con `EnsureBranchFeature`, igual que el resto.
- **cajero**: nunca.

## 7. Pruebas

- Cada uno de los eventos deja un `audit_log` con su `amount_effect` correcto, desde la web y desde el hub.
- Editar un pago guarda el monto anterior; borrarlo deja registro con el monto completo en negativo.
- El neto del periodo excluye los eventos no monetarios (asignar cliente no mueve el total).
- El admin-sucursal sin el flag recibe 403; con el flag, solo ve su sucursal.
- El cajero recibe 403 en ambas rutas.
- La marca de patrón sospechoso aparece con precio+pago en la misma hora y no aparece con uno solo.

## 8. Documentación

Doc vivo nuevo `docs/modulos/movimientos.md`, entrada en `docs/README.md` y el flag nuevo en la lista de `docs/modulos/sucursales.md`.
