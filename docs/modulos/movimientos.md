# Movimientos

Qué se le hizo a una venta después de cobrarla.

- **Rutas:** `/{tenant}/empresa/movimientos` · `/{tenant}/sucursal/movimientos`
- **Roles:** admin-empresa (siempre) · admin-sucursal (con `branch_admin_movements_enabled`) · el cajero **nunca**
- **Estado:** implementado (2026-08-23) · spec: [`2026-08-21-movimientos-design.md`](../superpowers/specs/2026-08-21-movimientos-design.md)

## 1. Qué resuelve

Un dueño sospecha que alguien aprendió sus credenciales y modifica ventas a escondidas: baja precios y ajusta lo que hay que entregar en efectivo. Hasta agosto de 2026 no había forma de mirar eso.

No es que no se registrara nada. `sale_item_changes` lleva desde mayo de 2026 una bitácora completa de los cambios a los productos de una venta. El problema era doble: solo se consultaba **venta por venta** desde su detalle, y faltaba la mitad que más importa. De las cuatro formas de sacarle dinero a una venta ya cobrada, solo una dejaba rastro completo:

| Acción | Qué quedaba antes |
|---|---|
| Cambiar precio o cantidad de un producto | Completo |
| Editar un pago | Solo `payments.updated_by`; **el monto anterior se perdía** |
| Borrar un pago | Nada: la fila desaparecía |
| Cancelar una venta | `cancelled_by` + `cancel_reason` |
| Reabrir una venta cobrada | Nada |
| Asignar o quitar el cliente | Nada |

Editar un pago de \$680 a \$380 y borrar el sobrante cuadraba la caja sin dejar huella.

## 2. Qué puede y qué no puede decir

**El registro va a decir que los cambios los hizo el dueño**, porque se harían con su cuenta. Eso no es un defecto: es la señal. El dueño mira la lista y reconoce —o no— sus propios cambios.

Para que ese reconocimiento sea posible se guarda el contexto. Aquí hay un límite real: **la IP no distingue equipos dentro del local**, porque todas las máquinas salen por el mismo router y el servidor ve una sola IP pública. Lo que sí distingue:

- **Dentro o fuera del local** — una IP distinta a la habitual es una señal fuerte por sí sola.
- **Tipo de equipo** — Windows, Android, Mac, iPhone, hub de sucursal, derivado del `User-Agent`.

Responde «¿esto se hizo como yo lo hago normalmente?», no «¿qué tableta exacta fue?».

Y el alcance de la función: **deja ver lo que pasó, no lo impide**. Contra unas credenciales copiadas, la medida es cambiar la contraseña.

## 3. Dónde vive el registro

En `audit_logs`, la tabla que ya usaban gastos y compras a través de `AuditLogger`. Ganó cuatro columnas (migración `2026_08_21_134244`):

| Columna | Para qué |
|---|---|
| `branch_id` (nullable) | Filtrar por sucursal sin recorrer la relación polimórfica |
| `ip_address` (45) | Distinguir «desde el local» de «desde fuera»; 45 cubre IPv6 |
| `user_agent` (255) | Derivar el tipo de equipo. Se recorta a 255 al escribir |
| `amount_effect` (12,2) | Efecto en dinero. **`null` = evento no monetario** |

`amount_effect` se guarda en vez de calcularse al vuelo porque la pantalla ordena por él y suma el neto del periodo: en SQL es una columna indexable; al vuelo obligaría a traer todo a memoria.

**`sale_item_changes` no se tocó.** Conserva su historia y sigue alimentando el historial dentro del detalle de una venta. `SaleItemEditor` escribe en las dos tablas. La duplicación es deliberada: migrar historia de auditoría es justo lo que no se puede permitir perder.

## 4. Los eventos y su efecto en dinero

Negativo significa «reduce lo que hay que entregar». Es el número que la pantalla ordena y suma.

| Evento | `amount_effect` |
|---|---|
| `item_added` | `+subtotal` |
| `item_updated` | `subtotal_después − subtotal_antes` |
| `item_removed` | `−subtotal` |
| `payment_updated` | `monto_después − monto_antes` |
| `payment_deleted` | `−monto` |
| `cancelled` | `−total` de la venta |
| `reopened` | `null` |
| `customer_assigned` / `customer_removed` | `null` |

**Los tres `null` son deliberados.** Reabrir habilita mover dinero pero no lo mueve. Y pasar una venta a fiado saca el dinero del efectivo del día sin perderlo: contarlo como pérdida daría un neto falso. Se ven en la lista, en gris, fuera del total.

**`payment_added` no es un movimiento de venta.** Hoy solo lo escribe el módulo de compras (`PurchasePaymentService`). Registrar cada cobro pondría en la pantalla todas las ventas del día, que es el ruido que esta pantalla existe para evitar: lo que se vigila es lo que se le hace a una venta *después* de cobrarla. Por eso `AuditEvent::saleMovements()` lo excluye.

## 5. Dónde se escribe

| Punto | Qué registra |
|---|---|
| `SaleItemEditor` | Los tres eventos de producto, además de lo que ya escribía en `sale_item_changes` |
| `Sucursal\PaymentController@update` / `@destroy` | `payment_updated` con monto y método antes/después; `payment_deleted` |
| `Api\Hub\PaymentController` (sus equivalentes) | Lo mismo desde el hub |
| `AssignCustomerToSale` | `customer_assigned` / `customer_removed`. Es el punto único por el que pasan los tres controladores que asignan cliente |
| `SaleAuditObserver` | `cancelled` y `reopened`. Va en un observer porque el estado de una venta se cambia desde cuatro controladores distintos, y una quinta ruta futura se olvidaría |

`AuditLogger` resuelve `branch_id`, `ip_address` y `user_agent` por su cuenta desde el request, para que ningún punto de escritura tenga que acordarse. En consola o en cola no hay `REMOTE_ADDR` ni `User-Agent` y los dos quedan `null`, que es lo correcto: un cambio automático no salió de ningún equipo.

## 6. La pantalla

Un renglón **por venta modificada**, desplegable, ordenado por efecto en dinero: primero la que más perdió. Encima, el resumen del periodo —ventas tocadas, número de movimientos y efecto neto, que excluye los eventos no monetarios—.

Se pagina por venta y no por evento: si no, una venta con doce cambios empujaría al resto fuera de la página y el orden por impacto mentiría.

**Marca de patrón sospechoso:** cuando en una misma venta hay un cambio de precio y un cambio de pago **dentro de la misma hora** (`SaleMovementsQuery::SUSPICIOUS_WINDOW_MINUTES = 60`), el renglón se destaca. Es la firma del fraude que se busca; un cambio suelto casi siempre es una corrección legítima, y marcarlo todo sería igual que no marcar nada.

Filtros: rango de fechas (por defecto hoy), sucursal —solo admin-empresa—, tipo de evento y usuario. El componente `MovimientosPanel.vue` lo comparten las dos pantallas; la diferencia se reduce a `scope` y la ruta de recarga.

## 7. Quién entra

- **admin-empresa** — siempre, con todas sus sucursales. Un `branch_id` que no sea de su empresa se descarta en vez de filtrar por él.
- **admin-sucursal** — solo la suya, y **solo con `branch_admin_movements_enabled`** encendido (`EnsureBranchFeature`). La sucursal se fuerza desde el usuario: mandar otra en la URL no amplía el alcance.
- **cajero** — nunca.

El flag **nace apagado**, a diferencia del resto de flags de sucursal. Es un registro de vigilancia sobre quien opera la caja, y la cuenta de admin-sucursal es una de las que puede quedar bajo sospecha: se enciende a propósito, nunca por omisión. Lo enciende el admin-empresa, que ve todo desde su propia pantalla.

## 8. Pruebas

`tests/Feature/Movimientos/` — 30 casos:

| Archivo | Qué cubre |
|---|---|
| `SaleMovementWritesTest` | Que cada acción deje su registro con el efecto correcto, y el contexto del cambio |
| `HubSaleMovementWritesTest` | Lo mismo entrando por la API del hub |
| `SaleMovementsQueryTest` | Agrupación, neto, orden, filtros y los cuatro lados de la marca de patrón |
| `MovimientosAccessTest` | Las tres reglas de acceso y el alcance por sucursal |

Dos límites del entorno de pruebas, anotados también en el código: el caso de consola no se puede reproducir por HTTP (el request sintético de PHPUnit siempre trae `127.0.0.1`), y sin `HTTP_USER_AGENT` Symfony rellena `'Symfony'`. Por eso ese caso se prueba con un request sin datos de servidor, que es lo que hay en un proceso CLI real.
