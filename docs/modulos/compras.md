# Compras + Proveedores + Pagos a Proveedores

Registro trazable de mercancía/materia prima que ingresa al tenant (ganado, canales, carne por kilo, insumos), con quién la vendió, cuánto costó y si ya se pagó. Aislamiento multi-tenant estricto. Patrón draft + confirmación para captura IA.

## Responsabilidades

- Mantener catálogo de **proveedores** tenant-wide (ganaderos, mayoristas, insumos, servicios).
- Registrar **compras** con cabecera (proveedor, sucursal, fecha, factura) + N líneas (concepto, cantidad, unidad, precio).
- Mantener saldo pendiente actualizado y soportar **pagos parciales o totales**.
- Soportar **pagos "a cuenta"** del proveedor que se distribuyen en FIFO sobre compras pendientes.
- Capturar facturas con IA (foto + voz + texto) usando el mismo patrón draft+confirm de Gastos.
- Conservar evidencia (factura escaneada) en disco privado.
- Trazabilidad financiera: soft-delete con motivo, auditoría de quién creó/canceló.

**No hace** (en este sprint):
- No descuenta stock al vender (inventario activo queda para fase futura — F-Inv1+).
- No deriva `Product.cost_price` automáticamente desde compras.
- No maneja transformación canal → cortes (BOM).
- No transferencias entre sucursales.
- No bloquea ventas por stock.
- No reemplaza al módulo de Gastos — coexiste como módulo independiente.

## Principios inviolables

1. **Compras NO son Gastos.** Compras = CMV (costo de mercancía vendida). Gastos = OPEX. Reportes los separan siempre.
2. **`branch_id` obligatorio en `purchases`.** Si es corporativa, va a la matriz.
3. **`amount_paid`/`amount_pending` solo se modifican vía `PurchasePaymentService`.** Sin setters directos.
4. **`purchase_items.concept` es denormalizado.** Si el `Product` ligado se renombra/borra, la compra histórica conserva el nombre del momento.

## Modelo de datos

```
providers                          ← catálogo tenant-wide
├─ purchases                       ← cabecera de compra
│  ├─ purchase_items               ← líneas (concept libre, opcional ligar a Product)
│  ├─ purchase_attachments         ← facturas escaneadas
│  └─ provider_payments            ← pagos (parcial o total a esta compra)
│
└─ provider_payments               ← pagos "a cuenta" (sin compra específica, FIFO)

ai_purchase_drafts                 ← propuesta IA pendiente de confirmar
```

### Decisiones

| Decisión | Razón |
|---|---|
| Módulo separado de Gastos | Compras = CMV, Gastos = OPEX. Mezclarlos corrompe la P&L. |
| `branch_id` **NOT NULL** en `purchases` | Reportes por sucursal limpios; compras corporativas se asignan a matriz. |
| Líneas con `concept` libre + `product_id` opcional | Cubre canales (sin liga al catálogo) e insumos (con liga) sin obligar a tener "Canal de res" como producto vendible. |
| `concept` denormalizado | Preserva nombre histórico aunque se renombre el Product. |
| `payment_method` en `provider_payments` (no en `purchases`) | Una compra puede pagarse con varios métodos en distintos momentos. |
| `folio` interno + `invoice_number` separados | `CMP-YYYY-NNNNN` autogenerado tenant-wide; `invoice_number` es el folio que viene en la factura del proveedor. |
| `provider_payments.branch_id` **nullable** | Excedente de pago "a cuenta" sin compras pendientes no tiene sucursal específica. |
| `lockForUpdate()` en `applyPayment`/`applyAccountPayment` | Pagos simultáneos no pueden pasar el total ni cubrir dos veces la misma compra. |
| Soft delete en proveedores, compras y pagos | Trazabilidad financiera: nunca perder un registro. |
| Adjuntos en disco `local` (privado) | Facturas son sensibles — sólo descarga/preview autenticado. |
| Update reemplaza items (delete + recreate) | Estrategia simple; sin lógica de diff que agrega complejidad sin ganancia. |

## Roles y permisos

| Acción | superadmin | admin-empresa | admin-sucursal | cajero |
|---|:-:|:-:|:-:|:-:|
| Crear/editar Proveedores | ✅ | ✅ | Lectura siempre; crear/editar **si** la empresa habilita el toggle de su sucursal | ❌ |
| Eliminar Proveedores | ✅ | ✅ | ❌ (reservado a empresa) | ❌ |
| Ver/crear/editar Productos de compra | ✅ | ✅ | Solo **si** la empresa habilita el toggle de su sucursal (módulo oculto si no) | ❌ |
| Eliminar Productos de compra | ✅ | ✅ | ❌ (reservado a empresa) | ❌ |
| Ver historial de Producto de compra | ✅ | ✅ | Con el toggle activo | ❌ |
| Crear/editar Categorías de productos de compra | ✅ | ✅ | Con el mismo toggle de productos | ❌ |
| Eliminar Categorías de productos de compra | ✅ | ✅ | ❌ (reservado a empresa) | ❌ |
| Crear Compra | ✅ | Elige sucursal | Forzado a la suya | ❌ |
| Ver/editar Compra | ✅ | Todas | Solo su sucursal | ❌ |
| Cancelar Compra | ✅ | ✅ (con motivo) | Solo de su sucursal (con motivo) | ❌ |
| Registrar pago a Proveedor | ✅ | ✅ | Solo a compras de su sucursal | ❌ |
| Pago a cuenta (FIFO) | ✅ | FIFO sobre todas | FIFO sobre las de su sucursal | ❌ |
| Cancelar pago | ✅ | ✅ (con motivo) | Solo de su sucursal | ❌ |
| Captura con IA | ✅ | ✅ | ✅ | ❌ |
| Adjuntos (download/preview/delete) | ✅ | Todas | Solo de su sucursal | ❌ |

Implementación: middleware `role:...` en cada grupo de rutas + checks manuales `tenant_id`/`branch_id` en cada controller (mismo patrón que Gastos).

## Rutas

### Empresa (admin-empresa)

```
GET    /{tenant}/empresa/proveedores                              empresa.proveedores.index
POST   /{tenant}/empresa/proveedores                              empresa.proveedores.store
GET    /{tenant}/empresa/proveedores/{provider}                   empresa.proveedores.show
PUT    /{tenant}/empresa/proveedores/{provider}                   empresa.proveedores.update
DELETE /{tenant}/empresa/proveedores/{provider}                   empresa.proveedores.destroy
POST   /{tenant}/empresa/proveedores/{provider}/pagos             empresa.proveedores.pagos.store   ← pago a cuenta FIFO

GET    /{tenant}/empresa/compras                                  empresa.compras.index
POST   /{tenant}/empresa/compras                                  empresa.compras.store
PUT    /{tenant}/empresa/compras/{compra}                         empresa.compras.update
PATCH  /{tenant}/empresa/compras/{compra}/cancelar                empresa.compras.cancel

POST   /{tenant}/empresa/compras/ia/borrador                      empresa.compras.ia.store

POST   /{tenant}/empresa/compras/{compra}/pagos                   empresa.compras.pagos.store
DELETE /{tenant}/empresa/compras/{compra}/pagos/{pago}            empresa.compras.pagos.destroy

GET    /{tenant}/empresa/compras/{compra}/adjuntos/{att}          empresa.compras.adjuntos.download
GET    /{tenant}/empresa/compras/{compra}/adjuntos/{att}/preview  empresa.compras.adjuntos.preview
DELETE /{tenant}/empresa/compras/{compra}/adjuntos/{att}          empresa.compras.adjuntos.destroy
```

### Sucursal (admin-sucursal)

Igual que Empresa pero scopeado a su `branch_id`. Los proveedores siempre son de **lectura**; además, si la empresa activa el toggle por sucursal **`branch_admin_providers_enabled`** (columna en `branches`, default `false`, editable en *Editar Sucursal*), el admin-sucursal gana `proveedores.store` y `proveedores.update` sobre el catálogo **tenant-wide compartido** (no `destroy`). Esas rutas se gatean con el middleware `branch.feature:branch_admin_providers_enabled` y reutilizan el concern `HandlesProviderWrites` (mismo que empresa) y el componente `ProveedorFormModal` (parametrizado por `routePrefix`).

#### Productos de compra (admin-sucursal)

A diferencia de Proveedores, el catálogo de **productos de compra** está **totalmente oculto** para la sucursal salvo que la empresa active el toggle **`branch_admin_purchase_products_enabled`** (columna en `branches`, default `false`, editable en *Editar Sucursal*). Con el toggle activo, **todo** el grupo de rutas de sucursal (incluida la lectura) queda disponible y se gatea con `branch.feature:branch_admin_purchase_products_enabled`; sin él, hasta el `index` responde 403 y el ítem de menú no aparece (`SucursalLayout` lee el flag de `auth.branch`). Puede `index`/`store`/`update` y ver `historial`, pero **no `destroy`** (reservado a empresa). Reutiliza los concerns `HandlesPurchaseProductWrites` y `SerializesPurchaseProducts`, el componente compartido `PurchaseProductsManager` (con `canDelete=false`), `ProductoCompraFormModal` y el drawer `ProductoCompraHistorialDrawer`.

```
GET  /{tenant}/sucursal/productos-compra                            sucursal.productos-compra.index       ← gated
GET  /{tenant}/sucursal/productos-compra/{producto}/historial       sucursal.productos-compra.historial   ← gated
POST /{tenant}/sucursal/productos-compra                            sucursal.productos-compra.store       ← gated
PUT  /{tenant}/sucursal/productos-compra/{producto}                 sucursal.productos-compra.update      ← gated
```

#### Categorías de productos de compra (administrables)

Las categorías de los productos de compra son un **catálogo en BD** (`purchase_product_categories`, tenant-wide), no un enum fijo. Cada empresa crea/edita/desactiva sus propias categorías. `purchase_products.category` (string enum legacy) fue reemplazado por la FK **`purchase_product_category_id`** (`nullOnDelete`). Al crear un tenant se siembran 5 categorías estándar como punto de partida (`Res, Cerdo, Pollo, Insumos, Otro`, vía `PurchaseProductCategory::seedDefaultsFor()`); la migración de datos preservó las asignaciones previas.

Se gestionan en una **pestaña "Categorías"** dentro de la pantalla *Productos de compra* (componente `PurchaseProductCategoriesManager`). Empresa: crear/editar/eliminar. Sucursal: crear/editar con el mismo toggle de productos; **no eliminar**. **Eliminar una categoría deja sus productos sin categoría** (la FK queda en `null`). Concern compartido: `HandlesPurchaseProductCategoryWrites`. Rutas: `…/productos-compra/categorias` (store), `…/productos-compra/categorias/{categoria}` (update; destroy solo empresa).

#### Historial de productos de compra

Cada producto de compra registra su historial de cambios en `audit_logs` (vía el trait `RecordsHistory` + servicio `AuditLogger`, igual que Compras/Gastos): se loguean los eventos **`created`** y **`updated`** (con diff de Nombre, Unidad, Categoría y Estado — la desactivación queda como cambio de `status`). Se consulta bajo demanda en `…/productos-compra/{producto}/historial` (JSON) y se muestra con `HistorialTimeline`. El `destroy` de empresa no borra las filas de auditoría (son inmutables; quedan huérfanas e inofensivas).

## Validaciones

### Purchase

| Campo | Reglas |
|---|---|
| `provider_id` | required, exists del tenant, status=active no borrado |
| `branch_id` | required, pertenece al tenant; admin-sucursal se fuerza a su branch |
| `invoice_number` | nullable, string, max 60 |
| `purchased_at` | required, date |
| `notes` | nullable, string, max 2000 |
| `items` | required, array, min 1 |
| `items.*.product_id` | nullable, exists del tenant |
| `items.*.concept` | required, string, max 160 |
| `items.*.quantity` | required, numeric, min 0.001 |
| `items.*.unit` | required, string, max 10 (kg/g/l/ml/pieza/caja/bulto/cabeza) |
| `items.*.unit_price` | required, numeric, min 0 |
| `items.*.notes` | nullable, string, max 500 |
| `attachments[]` | array, max 5 |
| `attachments.*` | mimes:jpg,jpeg,png,webp,pdf + mimetypes verificados + max 5 MB |
| `ai_draft_id` | nullable; si llega, se consume el draft (mueve adjuntos) |

### Provider

| Campo | Reglas |
|---|---|
| `name` | required, string, max 160, único por tenant (ignora soft-deleted) |
| `type` | required, enum `ganadero`/`mayorista_carne`/`insumos`/`servicios`/`otro` |
| `payment_terms_days` | nullable, integer, 0–365 |
| `rfc`, `phone`, `email`, etc | nullable con max correspondiente |

### ProviderPayment

| Campo | Reglas |
|---|---|
| `amount` | required, numeric, min 0.01, max 99,999,999.99; **no puede exceder el saldo pendiente** (`PurchasePaymentService::applyPayment` valida) |
| `payment_method` | required, enum `cash`/`card`/`transfer` (**`credit` rechazado**) |
| `paid_at` | nullable, date |
| `reference` | nullable, string, max 60 |
| `notes` | nullable, string, max 500 |
| `reason` (al cancelar) | required, string, max 500 |

## Almacenamiento de archivos

- Disco: `local` (privado, `storage/app/private`).
- Path: `tenants/{tenant_id}/purchases/{purchase_id}/{uuid}.{ext}`.
- Subida vía `App\Services\PurchaseAttachmentService::attach()`.
- Captura IA usa directorio temporal `tenants/{tenant_id}/ai_purchase_drafts/{draft_id}/...` y los archivos **se mueven** al directorio de la compra al confirmar (`attachFromDraft`).
- Dos endpoints de servido:
  - `download` — `Storage::download()` con `Content-Disposition: attachment`.
  - `preview` — `Storage::get()` con `Content-Disposition: inline` (para `<img>`/`<iframe>`).
- Ambos validan `tenant_id` y, para `admin-sucursal`, también `branch_id`.
- Eliminación física: hook `deleting` en `PurchaseAttachment` borra el archivo. Soft-delete de la compra **no** borra archivos (auditoría).

## UI

### Navegación unificada (2026-07-15)

El sidebar tiene **una sola entrada "Compras"** (Empresa y Sucursal). Dentro, el
componente `Components/Compras/ComprasTabs.vue` muestra 3 tabs de navegación
(Compras | Productos de compra | Proveedores); cada tab es un `<Link>` de
Inertia a la ruta existente de su sección — las rutas y controladores no
cambiaron. El detalle de proveedor conserva la barra con "Proveedores" activo.
El segmented Productos/Categorías sigue viviendo dentro de *Productos de
compra*. Spec: `docs/superpowers/specs/2026-07-15-compras-tabs-unificacion-design.md`.

### `/{tenant}/empresa/proveedores`

- KPIs: activos, inactivos, con saldo pendiente.
- Filtros: búsqueda (nombre/contacto/RFC), tipo, status.
- Tabla con saldo pendiente computado (`withSum`+`withCount`).
- CRUD vía modal `ProveedorFormModal.vue`.
- `Show.vue` con detalle + placeholder para historial de compras/pagos.

### `/{tenant}/empresa/compras`

- KPIs: total comprado, # compras, por pagar (monto + conteo).
- Filtros: fecha, sucursal, proveedor, status (recibida/cancelada), payment_status (pendiente/abonada/pagada).
- Tabla con badge tricolor de payment_status.
- Dos botones: **+ Nueva compra** y **✨ Capturar con IA** (gradiente violeta-fucsia).
- Modal `CompraFormModal.vue` con tabla de líneas editable, total auto-calculado, multi-upload de adjuntos.
- Modal `CompraDetailModal.vue` con secciones: cabecera, líneas, **Pagos** (lista + botón "Registrar pago"), adjuntos, notas. Sub-modal de cancelación con motivo obligatorio.

### `/{tenant}/sucursal/compras`

Idéntico a empresa, sucursal forzada (sin selector), sin CRUD de proveedores (solo lectura).

### Componentes reutilizables

- `Components/Compras/CompraFormModal.vue` — form con líneas editables, autocomplete de unidad, recibe propuesta IA opcional (`aiResult` prop) con badges ✨ y banner tricolor.
- `Components/Compras/CompraDetailModal.vue` — detalle + sub-modal `PagoProveedorModal` integrado.
- `Components/Compras/CompraCapturaIAModal.vue` — captura por foto + voz + texto.
- `Components/Compras/PagoProveedorModal.vue` — registrar pago (3 botones de método, atajo "Saldar total").
- `Components/Proveedores/ProveedorFormModal.vue` — CRUD proveedor.
- `composables/usePurchaseAiDraft.js` — submitDraft + applyProposalToForm.

## Lifecycle

### Purchase

```
received  ←  estado inicial al crear (default)
    ↓  cancelar (con motivo obligatorio)
cancelled (terminal)
```

F2 no usa `draft` para Purchase. Si después se necesita "compra registrada pero pendiente de autorización", se agrega.

### Payment status (derivado, no almacenado)

```
amount_paid = 0          → "pendiente"
0 < amount_paid < total  → "abonada"
amount_paid >= total     → "pagada"
status = cancelled        → "cancelled"
```

### Folio

`PurchaseFolioGenerator::nextFolio($tenantId)` genera `CMP-YYYY-NNNNN` único por tenant. Busca el último folio del año y suma 1. La unicidad la garantiza el constraint `UNIQUE(tenant_id, folio)` en BD.

## Pagos a Proveedores

### Operaciones (todas en `PurchasePaymentService`)

| Método | Qué hace |
|---|---|
| `recalculate(Purchase)` | Suma pagos vivos no cancelados y actualiza `amount_paid`/`amount_pending`. |
| `applyPayment(Purchase, payload)` | `lockForUpdate`, valida que no exceda saldo (422 si excede), crea `ProviderPayment`, recalcula. |
| `cancelPayment(ProviderPayment, by, reason)` | Idempotente. Marca cancelled_at, recalcula la compra ligada. |
| `applyAccountPayment(Provider, payload)` | FIFO sobre compras pendientes (más antigua primero). Si sobra dinero después de saldar todas, crea un pago a-favor con `purchase_id=null`. |
| `previewAccountPayment(Provider, amount, branchId?)` | Desglose FIFO de solo lectura (misma query que `applyAccountPayment`, sin lock ni persistencia) con `surplus`. Lo usa el asistente IA (tool `preparar_pago_proveedor_cuenta`, ver [asistente-ia.md](asistente-ia.md)) para mostrar el reparto antes de confirmar. |

### Reglas

- **Método `credit` rechazado** (es para ventas, no aplica a pagos a proveedor).
- **No se puede pagar una compra cancelada.**
- **`applyAccountPayment` respeta `branch_id`** cuando se pasa: admin-sucursal solo afecta sus compras.

## Captura con IA

Flujo paralelo al manual donde el usuario aporta foto/audio/texto de la factura y la IA prerellena el form.

### Pipeline

1. Usuario abre `CompraCapturaIAModal.vue` → foto/PDF + audio + texto opcional.
2. `POST /compras/ia/borrador` → `App\Services\Ai\AiPurchaseDraftService`:
   - Guarda archivos en `tenants/{id}/ai_purchase_drafts/{draft_id}/...`
   - Whisper transcribe audio (si hay) → guarda en `audio_transcription`.
   - GPT-4o multimodal lee imágenes + texto combinado.
   - `PurchaseContextBuilder` arma el contexto con proveedores y productos activos (sin RFC ni historial).
   - `AiPurchaseProposalParser` valida la respuesta: descarta `proveedor.id`/`product_id`/`branch_id` inventados; marca alerta si total no cuadra con suma de líneas.
3. Devuelve `{ draft_id, status, proposal, attachments, audio_transcription }`.
4. Frontend prerellena `CompraFormModal` con `aiResult` prop: banner tricolor de confianza, badges ✨ por campo, alertas listadas.
5. Usuario revisa, edita lo que la IA leyó mal, confirma.
6. `POST /compras` con `ai_draft_id` → `HandlesPurchases::consumeAiDraft`:
   - `lockForUpdate` sobre el draft.
   - Valida `tenant_id` + `status=ready`.
   - Mueve adjuntos del draft a la compra (`PurchaseAttachmentService::attachFromDraft`).
   - Marca draft como `consumed`.
7. Drafts no confirmados viven 24h y se limpian (job futuro, mismo patrón que gastos).

### Datos enviados a OpenAI

`PurchaseContextBuilder` filtra explícitamente. **Solo manda:**

- Proveedores activos: `{ id, nombre, tipo, rfc }` (max 80).
- Productos activos: `{ id, nombre, unidad, costo_actual }` (max 120).
- Sucursales visibles para el usuario.
- Métodos de pago disponibles.
- Reglas en texto plano.

**Nunca manda:** compras previas, pagos, montos históricos, datos fiscales del tenant, gastos.

### Riesgos mitigados

| Riesgo | Mitigación |
|---|---|
| IA inventa `proveedor.id` | Parser valida contra catálogo; deja `id=null`, conserva `nombre`. UI sugiere crear proveedor nuevo si la IA lo propuso. |
| IA inventa `product_id` en línea | Parser valida; deja `product_id=null`, conserva `concepto` libre. |
| IA reporta total que no cuadra con suma de líneas | Parser marca alerta visible en el banner. Usuario decide qué corregir. |
| IA devuelve JSON malformado | `extractJsonFromResponse` tolera markdown; si sigue inválido, draft pasa a `failed`, endpoint devuelve 502. |
| Inyección de prompt en `input_text` o transcripción | Texto envuelto en `<<< >>>` aparte del system prompt; la transcripción se etiqueta `[Nota de voz transcrita]`. |
| Datos sensibles a OpenAI | `PurchaseContextBuilder` filtra explícitamente. |
| Draft consumido dos veces | `lockForUpdate()` + status filtrado a `ready`. |
| Cross-tenant: usar draft de otro tenant | `where('tenant_id', $tenantId)` en `consumeAiDraft`. Test cubierto. |
| API key faltante | `OpenAiClient::fromConfig()` lanza `RuntimeException` → 502 con mensaje neutro. |

## Auditoría y trazabilidad

| Campo | Significado |
|---|---|
| `purchases.created_by` | Quién registró la compra |
| `purchases.cancelled_by` | Quién la canceló |
| `purchases.cancel_reason` | Motivo (obligatorio al cancelar) |
| `provider_payments.user_id` | Quién registró el pago |
| `provider_payments.cancelled_by`/`cancel_reason` | Cancelación con motivo |
| `purchase_attachments.uploaded_by` | Quién subió cada archivo |
| `providers.created_by` | Quién creó el proveedor |
| `created_at`/`updated_at`/`deleted_at` | Timestamps automáticos |

## Integración con resto del sistema

### F1–F5 NO tocan

- Sales / SaleItem / cortes de caja / Pagos de clientes
- Productos / `Product.cost_price` (sigue manual)
- `MarginMetrics` / dashboard de utilidad real
- Gastos (vive aparte)
- Inventario (no existe todavía)

### F1–F5 SÍ agregan

- KPI "Compras del día" + "Por pagar" en `/{tenant}/empresa/dashboard` (`purchasesSnapshot` en `Empresa\DashboardController`).
- 2 Read Tools en Asistente IA:
  - `consultar_compras` → `PurchaseSummaryTool` (resumen periodo + top proveedores).
  - `consultar_cuentas_por_pagar` → `AccountsPayableTool` (saldo + top con deuda).
- Cards Vue `PurchaseSummaryCard.vue` (violeta) y `AccountsPayableCard.vue` (rose) en `AsistenteChat`.

## Reportes (base preparada)

`purchases` tiene índices `(tenant_id, purchased_at)`, `(branch_id, purchased_at)`, `(provider_id, amount_pending)` para listados temporales y queries de cuentas por pagar eficientes.

Agregaciones disponibles para futuro:

- Compras por sucursal/proveedor/periodo.
- Saldo histórico por proveedor.
- Promedio ponderado de costo por producto (cuando se active F-Inv2).
- Utilidad real: `ventas − costo_real_de_lo_vendido − gastos_operativos` (cuando F-Inv2 derive `cost_price` desde compras).

## Comandos rápidos

```bash
# Resetear DB (módulo arranca vacío — sin proveedores ni compras sembradas)
vendor/bin/sail artisan migrate:fresh --seed

# Tests del módulo
vendor/bin/sail artisan test --compact tests/Feature/Compras/

# Pint
vendor/bin/sail bin pint --dirty --format agent
```

## Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Usuarios siguen capturando compras como gastos | UI separa los flujos; futura señal en gastos.md "¿es compra? → ir a Compras". |
| Doble captura (compra + gasto manual de lo mismo) | Sin mitigación técnica en F1. F6+: vincular pagos a corte de caja para reconciliar. |
| Sobre-pago a una compra | `PurchasePaymentService::applyPayment` valida y devuelve 422. Test cubierto. |
| Cancelar pago revierte amount_paid pero el dinero ya salió de caja | F1–F5: solo trazabilidad. Fase futura integra con cortes de caja. Por ahora cancelar pago = "registré mal, corrijo". |
| Borrar proveedor con compras vivas | `Empresa\ProviderController::destroy` rechaza con flash error; UX bloquea hard-delete. Test cubierto. |
| Cross-tenant: compras/pagos/drafts de otro tenant | `TenantScope` global + checks manuales en cada controller. Tests cubiertos. |
| Implicit route binding silencioso | **Lesson learned 2026-05-19:** Laravel implicit binding requiere que el nombre del parámetro PHP coincida con el segmento URL. `{compra}` exige `$compra` (no `$purchase`); de lo contrario Laravel inyecta un modelo vacío sin abort 404. |
| `JSON serializa (float) 800 como int` | En tests Inertia, no asumas `===` estricto en floats: usa closure `fn ($v) => (float) $v === 800.0`. |
| MIME falseado en factura PDF | Doble validación (`mimes` + `mimetypes`), igual que Gastos. |

## Roadmap

- **F-Inv1:** Inventario activo (kardex, stock por sucursal, descuento al vender).
- **F-Inv2:** Costo derivado (promedio ponderado desde compras → `Product.cost_price` automático). Destraba la utilidad real del dashboard.
- **F-Inv3:** Transformación canal→cortes (BOM), merma documentada.
- **F-Inv4:** Transferencias entre sucursales.
- **Otras:** Aprobaciones por umbral, recurrencia (renta mensual a proveedor), prompt caching + budget tracking en IA, job de limpieza 24h, reportes exportables.

## Referencias internas

- [docs/arquitectura/compras-modulo.md](../arquitectura/compras-modulo.md) — propuesta original con principios y decisiones.
- [docs/arquitectura/multitenant.md](../arquitectura/multitenant.md) — `TenantScope`.
- [docs/arquitectura/ia-asistente.md](../arquitectura/ia-asistente.md) — Asistente IA donde viven los 2 Read Tools nuevos.
- [docs/modulos/gastos.md](gastos.md) — patrón de adjuntos + draft IA que se reusó.
- `app/Services/PurchasePaymentService.php` — único punto que muta `amount_paid`/`amount_pending`.
- `app/Services/Ai/AiPurchaseDraftService.php` — orquestación Whisper + GPT-4o.
- `app/Http/Controllers/Concerns/HandlesPurchases.php` — store/update/cancel compartidos Empresa+Sucursal.
- `app/Http/Controllers/Concerns/HandlesProviderPayments.php` — pagos compartidos.
