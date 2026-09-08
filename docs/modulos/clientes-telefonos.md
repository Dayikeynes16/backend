# Teléfonos y resolución de clientes

Cómo el sistema identifica a una persona por su número de teléfono. Es el mecanismo que permite que capturar un teléfono en una venta la asocie a un cliente —reutilizando el existente o creándolo— sin generar duplicados, y que la cartera se vaya construyendo sola a partir de la operación diaria.

Implementado el 2026-08-11. Plan: [2026-08-11-telefonos-clientes-ventas.md](../superpowers/plans/2026-08-11-telefonos-clientes-ventas.md).

## Responsabilidades

- Normalizar todo teléfono de cliente a **E.164** (`+52XXXXXXXXXX`), sea cual sea el canal que lo escriba.
- Decidir, en un único punto, si un número corresponde a un cliente existente o a uno nuevo.
- Crear clientes **sin nombre** cuando solo se conoce el teléfono, y permitir completarlos después.
- Impedir que asignar un cliente altere en silencio el importe de una venta.
- Rechazar como teléfono lo que no lo es, para no llenar la cartera de registros inservibles.

**No hace:** no unifica clientes entre sucursales (la cartera es por sucursal, por diseño), no valida que el número exista realmente, y no fusiona clientes automáticamente fuera del comando de migración.

## Decisiones

| Decisión | Razón |
|---|---|
| La normalización vive en un **mutator del modelo** (`Customer::phone`), no en cada controlador | Los cinco canales que dan de alta clientes (CRUD web, hub, asistente IA, pedido web, captura en venta) quedan cubiertos sin que ninguno tenga que acordarse. Antes, `customers.phone` guardaba lo que se tecleara y `sales.contact_phone` guardaba E.164: el mismo número era dos valores distintos y el índice único no servía de nada. |
| `customers.name` sigue **NOT NULL**, con placeholder + `name_pending` | Una decena de puntos del frontend hacen `c.name.toLowerCase()` y reventarían con null. El placeholder (`Cliente 993 123 4567`) mantiene todo funcionando; la bandera permite filtrarlos y curarlos. |
| La asignación de cliente es **automática salvo que cambie el importe** | Asignar recalcula precios preferenciales y puede completar la venta. Pedir confirmación siempre habría estorbado el flujo normal —mandar la nota después de cobrar—, y no pedirla nunca habría alterado ventas cobradas en silencio. Se calcula el impacto real y solo se confirma si lo hay. |
| La cartera sigue siendo **por sucursal** | `UNIQUE (tenant_id, branch_id, phone)`. El mismo número puede ser cliente independiente en dos sucursales. Cambiar esto obligaría a fusionar carteras entre sucursales, y es una decisión de negocio, no técnica. |
| Solo se acepta como teléfono lo que **parece un teléfono** (`isPlausible`) | El campo se usó históricamente como cajón de sastre: en producción había `'0'`, `'89'`, `'344'`, `'*455'`. Normalizarlos a `'+0'`, `'+344'` no destruía el dato, pero habilitaba fusiones accidentales entre clientes distintos cuya basura coincidía. |
| El rescate histórico **no** pasa por `AssignCustomerToSale` | Ese servicio recalcula el total desde las líneas. Aplicarlo a ventas antiguas ya cobradas les cambiaría el importe. El comando solo rellena `customer_id`. |

## Modelo de datos

```
customers
├─ name           string    NOT NULL   ← placeholder si no se conoce
├─ name_pending   boolean   default false
├─ phone          string(20) nullable  ← SIEMPRE E.164 (+52XXXXXXXXXX)
├─ branch_id, tenant_id
└─ UNIQUE (tenant_id, branch_id, phone) WHERE phone IS NOT NULL

sales
├─ customer_id    FK → customers.id, nullable
├─ contact_name   string(255) nullable  ─┐ pedidos web: parte del registro
└─ contact_phone  string(20)  nullable  ─┘ del pedido, no un cliente suelto
```

Migración: `2026_08_11_085345_add_name_pending_to_customers_table.php`.

**`sales.contact_phone` dejó de ser el destino de la captura en ventas POS.** Al asignarse el cliente, `AssignCustomerToSale` lo limpia (salvo en `origin='web'`). Solo vuelve a usarse cuando el usuario rechaza explícitamente la asociación (`skip_assign`).

## Arquitectura

| Pieza | Responsabilidad |
|---|---|
| `App\Services\PhoneNormalizer` | Única autoridad sobre el formato. `normalize()` → E.164 o `null`; `isPlausible()` → regla E.164 (`+`, primer dígito ≠ 0, 8–15 dígitos); `displayLocal()` → `993 123 4567` para el placeholder |
| `App\Models\Customer::phone` | Mutator que aplica `normalize()` en toda escritura |
| `App\Services\Customers\ResolveCustomerByPhone` | Buscar-o-crear dentro de la sucursal, con advisory lock. Devuelve `CustomerResolution{customer, wasCreated}` |
| `App\Services\Customers\CustomerAssignmentPreview` | Calcula el impacto de asignar **sin escribir**: total resultante, si quedaría cobrada, presentaciones por pieza que se saltan |
| `App\Http\Controllers\Concerns\HandlesSalePhoneCapture` | Orquesta captura → resolución → asignación. Compartido por Caja, Sucursal y hub |
| `App\Support\SaleTotals` | Fuente única del total de una venta: **líneas + costo de envío** |

`PhoneNormalizer` maneja los formatos mexicanos reales: 10 dígitos, 12 con lada (`52…`) y 13 del móvil legacy (`521…`), que antes producían dos valores distintos para el mismo número.

## Flujos

### Capturar un teléfono en una venta

1. El usuario abre el chip de WhatsApp y teclea 10 dígitos.
2. `ResolveCustomerByPhone` busca en la sucursal por el número normalizado.
3. Si no existe, crea el cliente con `name_pending` y nombre placeholder.
4. `CustomerAssignmentPreview` calcula qué pasaría al asignarlo.
5. Si el total cambiaría o la venta quedaría cobrada → responde `requires_confirmation` con el detalle; **no toca nada**.
6. Si no → `AssignCustomerToSale` asigna, y devuelve el link `wa.me`.

Ante la confirmación, el usuario tiene tres salidas: **asociar** (reenvía con `confirmed`), **solo guardar el teléfono** (`skip_assign`, comportamiento anterior: queda en `contact_phone` sin tocar clientes) o **cancelar**.

### Contrato del endpoint

`POST {caja,sucursal}/…/whatsapp-phone` y `POST /api/v1/hub/sales/{sale}/whatsapp-phone`

```jsonc
// Petición
{ "phone": "9931234567", "confirmed": false, "skip_assign": false }

// Asignado
{ "url": "https://wa.me/...", "available": true,
  "customer": { "id": 12, "name": "Juan Pérez", "name_pending": false, "phone": "+529931234567" },
  "customer_created": false }

// Necesita confirmación
{ "requires_confirmation": true,
  "customer": { ... }, "customer_created": false,
  "preview": { "current_total": 450.0, "new_total": 410.0, "changes_total": true,
               "would_complete": false, "skipped_piece_presentations": [] } }
```

### Completar el nombre

Los clientes creados automáticamente aparecen en el chip con **"Poner nombre"** (o "Sin nombre" si el rol no puede editar). Al guardarlo, `HandlesCustomers::update` apaga `name_pending`.

Lo mismo desde el hub: `Api\Hub\CustomerController::update` apaga `name_pending` en cuanto el nombre deja de estar vacío, y `index`/`row` lo exponen para que la pantalla de Clientes marque **"falta nombre"** en la lista y arranque el campo vacío al editarlos.

> 2026-09-08: el hub guardaba el nombre pero **no** apagaba la bandera, así que `saleNames` seguía tratándolo como placeholder y las ventas mostraban el teléfono. Desde el mostrador se veía como que poner el nombre no servía de nada — y, al no distinguirse en la lista, el cajero acababa intentando dar de alta a un cliente que ya existía y chocando contra *"Ya existe un cliente con ese teléfono en la sucursal"*.

## Roles y permisos

| Acción | admin-empresa | admin-sucursal | cajero |
|---|:-:|:-:|:-:|
| Capturar teléfono y que resuelva cliente | ✅ | ✅ | ✅ |
| Confirmar una asignación que cambia el total | ✅ | ✅ | ✅ |
| Poner nombre desde el chip | ✅ | ✅ | solo con `cashier_customers_enabled` |

El cajero sin ese flag ve la etiqueta "Sin nombre" sin acción: la ruta de edición no le está abierta.

## Comandos de mantenimiento

| Comando | Qué hace |
|---|---|
| `customers:normalize-phones {--dry-run=true}` | Deja `customers.phone` en E.164 y fusiona los clientes que solo diferían en formato. **Prerrequisito del mutator.** Deja intacto lo que no parece un teléfono |
| `sales:link-orphan-phones {--dry-run=true} {--branch=}` | Asocia a un cliente las ventas con `contact_phone` huérfano. Solo rellena `customer_id`: importes y estado quedan intactos |
| `sales:check-integrity {--branch=} {--show=10}` | Solo lectura. Reporta ventas cuyo total no coincide con líneas + envío |
| `customers:dedup` | **Legacy.** El índice único vigente impide los duplicados exactos que buscaba; avisa si detecta teléfonos sin normalizar |

Los tres primeros traen `--dry-run` activado por defecto. `customers:normalize-phones` **fusiona y borra registros**: conviene respaldar `customers`, `sales.customer_id` y `customer_product_prices` antes de aplicarlo.

## Riesgos y limitaciones

| Riesgo / limitación | Estado |
|---|---|
| **El teléfono es obligatorio al dar de alta un cliente**, lo que empuja al mostrador a inventar números | Observado en producción: casi la mitad de los teléfonos no lo eran. La columna ya es nullable; hacerlo opcional en el formulario eliminaría el incentivo. **Pendiente de decisión.** |
| La UI no permite cambiar el teléfono de una venta con cliente asignado | El chip solo ofrece editar/quitar en fuente `manual`. Cambiar el teléfono de un cliente sigue siendo cosa del módulo de Clientes |
| `destroyWhatsappPhone` sigue existiendo y limpia `contact_phone` | Tras este cambio casi nunca hay un `contact_phone` que borrar en ventas POS. Queda decidir si en POS ese botón debería significar "quitar cliente" |
| Un cliente creado puede quedar sin venta si se cancela la confirmación | Solo en un caso raro (venta pagada aún no `Completed` + teléfono nuevo). Inocuo: el número es real |
| La lista de clientes se precarga completa en la mesa de trabajo | `WorkbenchController` manda todos los activos en cada render. Con creación automática la cartera crece más rápido. Si duele, convertir la búsqueda en endpoint server-side |
| Fusionar clientes es irreversible | El comando reasigna ventas y precios preferenciales; si dos tenían precio del mismo producto, gana el del superviviente y se avisa por consola |

## Tests

| Archivo | Cubre |
|---|---|
| `tests/Unit/Services/PhoneNormalizerTest` | Formatos MX (10/12/13 dígitos), idempotencia, `isPlausible` |
| `tests/Feature/Clientes/CustomerPhoneNormalizationTest` | Mutator, unicidad por formato, `name_pending` |
| `tests/Feature/Clientes/ResolveCustomerByPhoneTest` | Buscar-o-crear, aislamiento por sucursal, inactivos, sin tenant en el contenedor |
| `tests/Feature/Clientes/CustomerAssignmentPreviewTest` | Impacto sin escribir + **consistencia con `AssignCustomerToSale`** |
| `tests/Feature/Ventas/CapturePhoneCreatesCustomerTest` | Los dos caminos de confirmación, `skip_assign`, idempotencia, los 3 canales |
| `tests/Feature/Ventas/SaleTotalsWithDeliveryTest` | El costo de envío sobrevive a asignar cliente y editar líneas |
| `tests/Feature/Ventas/WebOrderReusesCustomerTest` | El checkout reutiliza cliente y rechaza teléfonos falsos |
| `tests/Feature/Console/{NormalizeCustomerPhones,LinkOrphanSalePhones,DedupCustomers,CheckSalesIntegrity}Test` | Los comandos, con casos reales de producción |

## Ver también

- [Clientes en Caja](clientes-caja.md) — módulo opcional del cajero
- [Clientes — Dashboard](clientes-dashboard.md) — ficha, estadísticas, precios preferenciales
- [Ventas](ventas.md) · [Pedidos web](pedidos-web.md)
