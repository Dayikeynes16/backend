# Módulo Compras + Proveedores + Pagos a Proveedores

Propuesta de arquitectura para registrar mercancía/materia prima que ingresa al tenant, quién la vendió, cuánto costó y si ya se pagó. Documenta decisiones tomadas antes de implementar.

> **Estado:** propuesta. No implementado. Auditoría del estado actual hecha 2026-05-19 — el sistema no tiene nada relacionado con proveedores, compras, pagos a proveedores ni inventario (greenfield total).
>
> **Infraestructura existente reutilizable:** `BelongsToTenant` trait, `ExpenseAttachmentService` (patrón adjuntos), `AiExpenseDraftService` (patrón draft + confirm IA), `OpenAiClient` + `AssistantTranscriber` (Whisper + GPT-4o), enum `PaymentMethod`, role gating Empresa/Sucursal, soft-delete con motivo.

## Responsabilidades

- Mantener un catálogo de **proveedores** a nivel tenant (ganaderos, mayoristas de carne, insumos, servicios).
- Registrar **compras** con cabecera + N líneas: qué entró, cuánto, a qué precio, fecha, sucursal, factura del proveedor.
- Registrar **pagos a proveedores**, parciales o totales, ligados a una compra específica o "a cuenta" (FIFO sobre compras pendientes).
- Capturar facturas con IA (foto/PDF/voz/texto) usando el mismo patrón draft+confirm de Gastos.
- Conservar evidencia (facturas escaneadas) en almacenamiento privado.
- Trazabilidad completa: quién capturó, quién editó, quién canceló, motivos.

**No hace** (en este sprint):
- No descuenta stock al vender (inventario activo queda para fase posterior).
- No deriva `Product.cost_price` automáticamente desde compras (sigue manual).
- No maneja transformación canal→cortes (BOM).
- No hace transferencias entre sucursales.
- No bloquea ventas por stock.
- No reemplaza al módulo de Gastos — coexiste como módulo independiente.

## Principios inviolables

Cuatro reglas sin excepciones. Si una propuesta las viola, la respuesta por defecto es **no**.

1. **Compras NO son Gastos.** Aunque ambos sean salidas de dinero, viven en módulos distintos. Reportes los separan siempre (CMV vs OPEX).
2. **`branch_id` obligatorio en `purchases`**. Si es corporativa, va a la matriz. Sin huecos.
3. **`amount_paid`/`amount_pending` solo se modifican vía `PurchasePaymentService`.** Idéntico al patrón de `SalePaymentService`. Sin setters directos.
4. **Líneas se denormalizan:** `concept` se guarda como string aunque haya `product_id` ligado. Si el producto se renombra, la compra histórica conserva el concepto del momento.

## Modelo de datos

```
providers                          ← catálogo tenant-wide
├─ purchases                       ← cabecera de compra
│  ├─ purchase_items               ← líneas (concept libre, opcional ligar a Product)
│  ├─ purchase_attachments         ← facturas escaneadas
│  └─ provider_payments            ← pagos (parcial o total, a esta compra)
│
└─ provider_payments               ← pagos "a cuenta" (sin compra específica)

ai_purchase_drafts                 ← propuesta IA pendiente de confirmar
```

### `providers`

| Columna | Tipo | Notas |
|---|---|---|
| id, tenant_id | FK | Tenant-scoped |
| name | string(160) | Único dentro del tenant |
| contact_name, phone, email | string | Nullable |
| rfc | string(20) | Nullable. No se valida formato (México permite RFCs viejos malformados) |
| address | string(500) | Nullable |
| type | enum | `ganadero`, `mayorista_carne`, `insumos`, `servicios`, `otro` |
| payment_terms_days | uint | Nullable. Días de crédito default (ej. 30 para mayorista) |
| notes | text | Nullable |
| status | enum | `active`/`inactive` |
| created_by, timestamps, deleted_at | | Soft delete |

### `purchases` (cabecera)

| Columna | Tipo | Notas |
|---|---|---|
| id, tenant_id, branch_id | FK | `branch_id` **obligatorio** |
| provider_id | FK | |
| folio | string(20) | Autogenerado interno (`CMP-2026-00001`) |
| invoice_number | string(60) | Nullable. Folio que viene en la factura del proveedor |
| purchased_at | timestamp | Fecha del comprobante (no de captura) |
| status | enum | `received`/`cancelled`. (En F1 no usamos `draft`.) |
| subtotal | decimal(12,2) | Suma de líneas |
| total | decimal(12,2) | = subtotal en F1; reserva para impuestos futuros |
| amount_paid | decimal(12,2) | Default 0 |
| amount_pending | decimal(12,2) | total − amount_paid |
| notes | text | Nullable |
| created_by, cancelled_by, cancelled_at, cancel_reason | | |
| timestamps, deleted_at | | Soft delete |

Índices: `(tenant_id, purchased_at)`, `(branch_id, purchased_at)`, `(provider_id, amount_pending)`.

### `purchase_items`

| Columna | Tipo | Notas |
|---|---|---|
| id, purchase_id | FK | |
| product_id | FK nullable | Opcional ligar al catálogo |
| concept | string(160) | Requerido. **Denormalizado** — preserva nombre histórico |
| quantity | decimal(12,3) | 3 decimales para kilos |
| unit | string(10) | Texto libre: `kg`, `pieza`, `l`, `caja`, etc. |
| unit_price | decimal(12,4) | 4 decimales para precios por kilo finos |
| subtotal | decimal(12,2) | quantity × unit_price (calculado en server) |
| notes | string(500) | Nullable |
| timestamps | | Sin soft delete (cascade desde purchase) |

### `provider_payments`

| Columna | Tipo | Notas |
|---|---|---|
| id, tenant_id, branch_id | FK | |
| provider_id | FK | |
| purchase_id | FK nullable | Pago "a cuenta" si es null |
| paid_at | timestamp | |
| amount | decimal(12,2) | min 0.01 |
| payment_method | enum | `cash`/`card`/`transfer`. **Nunca `credit`** (eso es la propia compra a crédito). |
| reference | string(60) | Nullable. Folio del comprobante bancario |
| notes | string(500) | Nullable |
| user_id | FK | Quien capturó |
| cancelled_by, cancelled_at, cancel_reason | | |
| timestamps, deleted_at | | Soft delete |

### `purchase_attachments`

Espejo de `expense_attachments`. `tenant_id` denormalizado para validación sin JOIN.

### `ai_purchase_drafts`

Espejo de `ai_expense_drafts`. Apunta a `purchase_id` cuando se consume; status `pending → ready | failed → consumed | expired` (24h).

## Permisos por rol

| Acción | superadmin | admin-empresa | admin-sucursal | cajero |
|---|:-:|:-:|:-:|:-:|
| CRUD Proveedores | ✅ | ✅ | Solo lectura | ❌ |
| Crear Compra | ✅ | Elige sucursal | Forzado a la suya | ❌ |
| Ver/editar Compra | ✅ | Todas | Solo su sucursal | ❌ |
| Cancelar Compra | ✅ | ✅ (con motivo) | Solo de su sucursal | ❌ |
| Registrar pago a Proveedor | ✅ | ✅ | Solo a compras de su sucursal | ❌ |
| Cancelar pago | ✅ | ✅ (con motivo) | Solo de su sucursal | ❌ |
| Captura con IA | ✅ | ✅ | ✅ | ❌ |
| Adjuntos (download/preview/delete) | ✅ | Todas | Solo de su sucursal | ❌ |

Implementación: middleware `role:...` en cada grupo de rutas + checks manuales `tenant_id`/`branch_id` en controller (mismo patrón que [Gastos](../modulos/gastos.md)).

## Defensas (todas reutilizan patrones existentes)

| Defensa | Origen |
|---|---|
| `TenantScope` global en todos los modelos | `BelongsToTenant` |
| `tenant_id` denormalizado en attachments | `ExpenseAttachment` |
| Disco `local` privado, paths `tenants/{id}/purchases/{purchase_id}/{uuid}.ext` | `ExpenseAttachmentService` |
| Doble validación adjuntos: `mimes` + `mimetypes` | Gastos |
| admin-sucursal: `branch_id` forzado, 403 si manipulan | `WithdrawalController`, `GastoController` |
| `lockForUpdate()` al consumir draft IA | `AiExpenseDraftService` |
| Parser IA descarta ids inventados | `AiExpenseProposalParser` |
| Prompt injection: texto en `<<< >>>`, system prompt blindado | Gastos IA, Asistente IA |
| `ProviderContextBuilder` filtra datos sensibles antes de mandar a OpenAI | `ExpenseContextBuilder` |
| Soft-delete con motivo, auditoría con `created_by`/`cancelled_by` | Toda la app |

## Lifecycle

### Purchase

```
received  ←  estado inicial al crear (default)
    ↓  cancelar (con motivo)
cancelled (terminal)
```

F1 no usa `draft` para Purchase. Si después aparece flujo de "compra registrada pero pendiente de autorización", se agrega.

### Payment state (derivado, no almacenado)

```
amount_paid = 0          → "pendiente"
0 < amount_paid < total  → "abonada"
amount_paid >= total     → "pagada"
```

### Operaciones que mutan `amount_paid` / `amount_pending`

Solo `PurchasePaymentService`:
- `applyPayment(Purchase, amount, method, ...)` — incrementa
- `cancelPayment(ProviderPayment, reason)` — decrementa (revierte)
- `applyAccountPayment(Provider, amount, ...)` — distribuye en orden FIFO sobre compras pendientes del proveedor

**Regla:** un pago no puede llevar `amount_paid > total`. Si excede, se rechaza con 422.

## Rutas

### Empresa (admin-empresa)

```
GET    /empresa/proveedores                              empresa.proveedores.index
POST   /empresa/proveedores                              empresa.proveedores.store
GET    /empresa/proveedores/{provider}                   empresa.proveedores.show
PUT    /empresa/proveedores/{provider}                   empresa.proveedores.update
DELETE /empresa/proveedores/{provider}                   empresa.proveedores.destroy

GET    /empresa/compras                                  empresa.compras.index
GET    /empresa/compras/{compra}                         empresa.compras.show
POST   /empresa/compras                                  empresa.compras.store
PUT    /empresa/compras/{compra}                         empresa.compras.update
PATCH  /empresa/compras/{compra}/cancelar                empresa.compras.cancel

POST   /empresa/compras/ia/borrador                      empresa.compras.ia.store

POST   /empresa/compras/{compra}/pagos                   empresa.compras.pagos.store
DELETE /empresa/compras/{compra}/pagos/{pago}            empresa.compras.pagos.destroy
POST   /empresa/proveedores/{provider}/pagos             empresa.proveedores.pagos.store

GET    /empresa/compras/{compra}/adjuntos/{att}          empresa.compras.adjuntos.download
GET    /empresa/compras/{compra}/adjuntos/{att}/preview  empresa.compras.adjuntos.preview
DELETE /empresa/compras/{compra}/adjuntos/{att}          empresa.compras.adjuntos.destroy
```

### Sucursal (admin-sucursal)

Mismo set sin CRUD de proveedores (solo `index`/`show`), todo scoped por `$user->branch_id`.

## UI

### `/empresa/proveedores`

- Lista con: nombre, tipo (badge color por tipo), contacto, **saldo total pendiente** (computado), # compras
- Modal crear/editar
- Click → vista detalle: datos + lista de compras + historial de pagos + saldo
- Botón "Registrar pago a cuenta" abre modal de pago global

### `/empresa/compras` (dos tabs)

- **Tab Compras:** KPIs (total comprado, # compras, ticket promedio), filtros (rango fecha, proveedor, sucursal, status, búsqueda), tabla con badge de payment_status (pendiente/abonada/pagada)
- **Tab Por pagar:** lista de compras con `amount_pending > 0`, agrupable por proveedor, botón rápido "Registrar pago" inline
- Botón "Nueva compra" + botón **"Capturar con IA"** (gradiente violeta — patrón existente)

### `CompraFormModal.vue`

- Cabecera: proveedor (combobox con buscar/crear), sucursal, fecha, # factura, notas
- **Tabla de líneas editable**: agregar/eliminar filas, autocomplete de producto opcional, cantidad, unidad, precio unitario, subtotal (read-only calculado)
- Total auto-calculado al pie
- Multi-upload de adjuntos (max 5, jpg/png/webp/pdf, 5 MB c/u)
- Si viene `ai_draft_id`, banner tricolor de confianza + badges ✨ por campo

### `CompraDetailModal.vue`

- Cabecera + líneas + adjuntos como thumbnails
- Panel de pagos: lista con fecha/método/monto + botón "Agregar pago"
- Footer: subtotal/total/pagado/pendiente
- Acciones: editar, cancelar, ver factura

### `/sucursal/compras`

Idéntico a empresa, sucursal forzada, sin CRUD de proveedores (solo selector lookup).

## Captura con IA (F4)

Patrón idéntico al de `AiExpenseDraftService` pero con cabecera + N líneas.

**Flujo:**

1. Usuario abre `CompraCapturaIAModal.vue` → foto/PDF de factura + audio opcional + texto opcional
2. `POST /compras/ia/borrador` → `AiPurchaseDraftService`:
   - Whisper transcribe audio (si hay)
   - GPT-4o lee imágenes + texto combinado
   - `OpenAiClient` ya existente (mismo modelo `gpt-4o`)
3. Respuesta JSON esperada:

```json
{
  "proveedor": { "id": 12, "nombre": "Carnes Don Pedro" },
  "invoice_number": "F-4521",
  "purchased_at": "2026-05-18",
  "lineas": [
    { "concepto": "Pulpa de res", "product_id": 34, "quantity": 25.5, "unit": "kg", "unit_price": 185.00, "subtotal": 4717.50 },
    { "concepto": "Costilla", "product_id": null, "quantity": 10, "unit": "kg", "unit_price": 160.00, "subtotal": 1600 }
  ],
  "total": 6317.50,
  "confianza": "alta",
  "confianza_por_campo": { "proveedor": "alta", "total": "alta", "lineas": "media" },
  "alertas": [],
  "sugerencia_nuevo_proveedor": null
}
```

4. `AiPurchaseProposalParser` valida:
   - `proveedor.id` debe existir en el tenant → si no, ignora id y deja solo nombre + bandera para crear
   - `product_id` por línea: valida contra catálogo del tenant
   - Cantidades/precios saneados (decimal positivo, máx razonable)
   - Cuadre: si `sum(subtotales) != total`, marca alerta pero no rechaza
5. Form se prerellena. Usuario revisa, **edita líneas** (clave: pueden faltar líneas o estar mal el precio), confirma.
6. Si la IA propuso proveedor nuevo, el form muestra inline botón "Crear proveedor 'X' y registrar compra".
7. `POST /compras` con `ai_draft_id`: abre transacción, crea purchase + items + mueve adjuntos del draft, marca draft como `consumed`.
8. Drafts viven 24h, job los limpia.

### `PurchaseContextBuilder` manda a OpenAI

- Lista de proveedores activos: `{ id, nombre, tipo }` (sin RFC, sin direcciones, sin saldos)
- Lista de productos activos del tenant: `{ id, nombre, unit_type, cost_price }` (cost_price ayuda a la IA a validar precios)
- Reglas en texto plano
- **NO manda:** compras previas, pagos, montos históricos, datos fiscales del tenant

## Integración con resto del sistema

### F1 NO toca

- Sales / SaleItem / cortes de caja / Pagos de clientes
- Productos / `cost_price` (sigue manual)
- `MarginMetrics` / dashboard de utilidad
- Gastos (vive aparte)
- Inventario (no existe)

### F1 SÍ agrega

- Snapshot en `/empresa/dashboard`: "Compras del día" + "Por pagar a proveedores" (igual al snapshot existente de gastos)
- 2 Read Tools nuevos en Asistente IA:
  - `consultar_compras` (resumen por periodo, top proveedores)
  - `consultar_cuentas_por_pagar` (saldo total + top proveedores con deuda)

### Futuro (no en este plan)

- **F-Inv1:** Inventario activo (kardex, stock por sucursal, descuento al vender)
- **F-Inv2:** Costo derivado (promedio ponderado desde compras → `Product.cost_price` automático)
- **F-Inv3:** Transformación canal→cortes (BOM)
- **F-Inv4:** Transferencias entre sucursales, merma documentada

## Implementación por fases

Cada fase es independientemente útil y verificable.

### F0 — Migraciones, modelos, factories (1–2 días)

- 6 migraciones: `providers`, `purchases`, `purchase_items`, `provider_payments`, `purchase_attachments`, `ai_purchase_drafts`
- Modelos con `BelongsToTenant`, enums `PurchaseStatus`, `ProviderType`
- Factories para tests
- Sin UI ni controllers todavía

### F1 — CRUD Proveedores (1–2 días)

- `Empresa\ProviderController` (resource completo)
- `Sucursal\ProviderController` (solo index/show)
- Pantalla `/empresa/proveedores` con modal CRUD
- Tests: CRUD, tenant isolation, role gating, no se puede borrar provider con compras vivas (`restrictOnDelete`)

### F2 — CRUD Compras manual + adjuntos (3–5 días)

- `PurchaseController` (Empresa + Sucursal)
- `PurchasePaymentService` (stub para F3)
- `PurchaseAttachmentService` (reusa lógica de `ExpenseAttachmentService`)
- Pantalla `/empresa/compras` tab Compras
- Modal form con líneas editables + total calculado
- Tests: crear/editar/cancelar, branch enforcement, totales correctos, adjuntos, cross-tenant blocked

### F3 — Pagos a proveedores (2–3 días)

- `ProviderPaymentController`
- `PurchasePaymentService` completo (apply, cancel, applyAccountPayment FIFO)
- Tab "Por pagar" en `/empresa/compras`
- Vista detalle de proveedor con saldo + historial de pagos
- Pagos parciales, pagos a cuenta, cancelación revierte amount_paid
- Tests: pago parcial, total, sobre-pago bloqueado (422), cancelar revierte, pago a cuenta distribuye FIFO

### F4 — Captura con IA (3–5 días)

- `AiPurchaseDraftService` + `AiPurchaseProposalParser` + `PurchaseContextBuilder`
- Reutiliza `OpenAiClient` y `AssistantTranscriber` (Whisper) — sin código nuevo de transporte
- Modal `Components/Compras/CompraCapturaIAModal.vue`
- Banner tricolor + badges ✨ por campo
- Si la IA propone proveedor nuevo, inline crear durante confirm
- Tests: parser descarta ids inventados, draft idempotente, cross-tenant blocked, propuesta de proveedor nuevo funciona

### F5 — Integración dashboard + Asistente IA (1–2 días)

- KPI "Compras del día" y "Por pagar" en `/empresa/dashboard`
- 2 nuevos Read Tools (`ConsultarComprasTool`, `ConsultarCuentasPorPagarTool`) registrados en `ToolRegistry` del Asistente IA
- Cards Vue correspondientes
- Tests

### F6 — Documentación final (paralelo)

- `docs/modulos/compras.md` (estilo idéntico a `docs/modulos/gastos.md`) cuando F2 esté completa
- Actualizar `MEMORY.md` con `project_compras.md`

### Total estimado

- F0–F5: **11–19 días de trabajo** (~2–3 semanas con verificación)
- F6: paralelo

### No hacer en este sprint

- Stock / inventario / kardex / descuento al vender
- Transformación / BOM / desposte
- Transferencias entre sucursales
- Costeo automático de Productos
- Aprobaciones por umbral
- Recurrencia (renta mensual a proveedor)
- Integración con cortes de caja (los pagos a proveedores NO afectan corte de caja en F1 — solo trazabilidad)

## Decisiones tomadas (2026-05-19)

1. **Módulo separado** de Gastos — no ampliar.
2. **Solo compras + pagos en este sprint** — sin stock activo. Inventario queda para fase futura.
3. **Líneas con `concept` libre + opcional ligar a `product_id`.** Cubre canales (sin liga) e insumos (con liga) sin obligar a tener "Canal de res" como producto vendible.
4. **Captura con IA en F4** del módulo (el módulo manual estable primero, IA después).
5. **`payment_method` vive en `provider_payments`, no en `purchases`.** Una compra puede pagarse con varios métodos en momentos distintos.
6. **`folio` interno (`CMP-YYYY-NNNNN`)** y `invoice_number` (factura del proveedor) son campos separados. Búsqueda independiente.
7. **Soft-delete con motivo** en proveedores, compras y pagos.
8. **Cajero no participa** en compras. Es flujo de admin.

## Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Usuarios siguen capturando compras como gastos | UI lo desincentiva con CTAs claros; banner en `/empresa/gastos` "¿es compra a proveedor? → ir a Compras" |
| Doble captura (compra registrada + gasto manual de lo mismo) | Sin mitigación técnica en F1. F5+: vincular pagos a proveedor con egresos en corte de caja para reconciliar |
| Captura IA inventa proveedor que no existe | Parser propone "Crear proveedor 'X'" como confirmación explícita; nunca crea sin click |
| Sobre-pago a una compra | `PurchasePaymentService` valida y devuelve 422; UI muestra "monto excede saldo pendiente" |
| Cancelar pago revierte amount_paid pero el dinero ya salió de caja | F1: solo trazabilidad. F2 del módulo: integrar con cortes de caja para reflejar egresos. Por ahora cancelar pago = "registré mal, corrijo" |
| IA mal lee el `total` y desencadena saldo incorrecto | Cuadre `sum(subtotales) vs total` alerta visible antes de confirmar; el usuario edita lo necesario |
| Proveedor con misma razón social en distinto tenant | Imposible — `name` único POR `tenant_id` |
| Borrar proveedor con compras vivas | `restrictOnDelete` BD + UX bloquea con count, igual que subcategorías de gastos |
| MIME falseado en factura PDF | Doble validación (`mimes` + `mimetypes`), igual que Gastos |

## Referencias internas

- [multitenant.md](multitenant.md) — `TenantScope` y `BelongsToTenant` que se reutilizan.
- [roles-permisos.md](roles-permisos.md) — Roles del sistema.
- [ia-asistente.md](ia-asistente.md) — Asistente conversacional donde se agregarán los 2 nuevos Read Tools en F5.
- [../modulos/gastos.md](../modulos/gastos.md) — Patrón draft+confirm y de adjuntos a clonar.
- `app/Services/Ai/AiExpenseDraftService.php` — Patrón de referencia para captura IA.
- `app/Services/ExpenseAttachmentService.php` — Patrón de referencia para adjuntos privados.
- `app/Services/SalePaymentService.php` — Patrón de referencia para `PurchasePaymentService`.
