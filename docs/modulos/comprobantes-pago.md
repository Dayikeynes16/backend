# Comprobantes de pago (transferencias)

Adjuntos de evidencia (imagen o PDF) para pagos por transferencia — tanto pagos de venta (`Payment`) como cobros globales de fiado (`CustomerPayment`). Resuelve un problema operativo real: cuando un cliente paga por transferencia, la captura del comprobante vivía en el WhatsApp del cajero o del dueño, sin liga con el pago registrado.

## Responsabilidades

- Permitir adjuntar uno o más comprobantes a un pago por transferencia, en el momento del cobro o después.
- Controlar por sucursal si adjuntar es opcional (`payment_receipts_enabled`) u obligatorio (`payment_receipts_required`) para cobrar por transferencia.
- Servir los archivos solo por descarga autenticada (disco privado, nunca URLs públicas — son datos bancarios).
- Conservar los comprobantes como evidencia histórica aunque el pago se edite (cambio de método) o el cobro global se cancele.
- Borrar el archivo físico junto con la fila cuando se elimina el pago que lo contiene (corrección administrativa).

## Principios / Decisiones

| Decisión | Razón |
|---|---|
| Alcance: `Payment` (venta) y `CustomerPayment` (cobro global) | Son los dos tipos de "pago" transferibles en la app. No aplica a compras/gastos (ya tienen adjuntos propios) ni al hub (paridad futura). |
| Comprobante vive en el **padre** de un cobro global | Un cobro global (FIFO) reparte un monto en N `Payment` hijos (uno por venta afectada). El comprobante es uno solo por la transferencia real → vive en el `CustomerPayment` padre. Adjuntar directo a un pago hijo de CG → 422. |
| Tabla propia con **dos FKs nullable + CHECK** en vez de morphs | El proyecto no usa relaciones polimórficas en ningún otro módulo; se sigue el mismo patrón que `payments` (columnas explícitas) en vez de introducir una excepción. |
| `required` implica `enabled` | Regla de negocio simple: no se puede exigir algo que no está permitido. Acoplado también en la UI de Editar Sucursal (el checkbox "Exigir" se deshabilita si "Permitir" está apagado). |
| `required` se valida en los **controladores web**, nunca en el servicio de dominio | `PaymentReceiptService` no decide permisos ni reglas de negocio (mismo principio que `ExpenseAttachmentService`). El confirm del asistente IA (`CustomerGlobalPaymentDraftConfirmer`, que crea cobros globales por transferencia sin poder adjuntar en el chat) queda **exento** del `required`: su comprobante se adjunta después vía los endpoints dedicados. |
| Límite propio: **3 comprobantes por pago** (no 5, como gastos) | Un pago normalmente tiene una sola captura de transferencia; 3 cubre reintentos/ángulos sin invitar a acumular. |
| Mismos mimes/tamaño que gastos (jpg/png/webp/pdf, 5 MB) | Reutiliza la validación ya probada de `ExpenseAttachmentService`, sin inventar reglas nuevas. |
| Comparte el disco privado de gastos (`config('expenses.disk')`) | No se justifica un disco nuevo solo para este módulo; mismo nivel de sensibilidad (evidencia financiera). |
| Editar el método de un pago **no** borra sus comprobantes | Si un pago pasa de transferencia a otro método, el comprobante ya adjunto se conserva como evidencia histórica. Se cumple por omisión: no hay código que los borre en `update()`; el cascade solo corre en `destroy()`. |
| Cancelar un cobro global (soft-delete) **conserva** los comprobantes | La cancelación no es una reversión contable completa — el dinero pudo haberse recibido. Los comprobantes quedan como evidencia aunque el CG quede cancelado. |
| Eliminar un pago (corrección admin) sí borra sus comprobantes | A diferencia de gastos (donde el soft-delete no toca adjuntos), el `destroy()` de un `Payment`/hijo de venta es una eliminación real del registro — arrastra archivo + fila en la misma operación. |

## Modelo de datos

```
payment_receipts
  id
  tenant_id            FK tenants, cascade
  payment_id           FK payments, nullable, cascade
  customer_payment_id  FK customer_payments, nullable, cascade
  uploaded_by           FK users, nullable, null on delete
  original_name         string(255)
  path                  string
  mime_type              string
  size_bytes             unsignedInteger
  created_at / updated_at

CHECK ((payment_id IS NULL) != (customer_payment_id IS NULL))  -- exactamente un padre
```

Migraciones: `2026_07_15_000001_create_payment_receipts_table.php`, `2026_07_15_000002_add_payment_receipt_toggles_to_branches.php`.

Modelo `App\Models\PaymentReceipt` — usa `BelongsToTenant` (ver lista de modelos tenant en el `CLAUDE.md` del workspace). Relaciones: `payment()`, `customerPayment()`, `uploader()`. Relaciones inversas: `Payment::receipts()`, `CustomerPayment::receipts()`.

Columnas nuevas en `branches` (después de `branch_admin_expense_categories_enabled`):

| Columna | Tipo | Default | Editable por |
|---|---|---|---|
| `payment_receipts_enabled` | boolean | `false` | admin-empresa, en *Empresa → Editar Sucursal* |
| `payment_receipts_required` | boolean | `false` | admin-empresa, acoplado en UI a `enabled` (checkbox deshabilitado si `enabled` está apagado) |

## Servicio de dominio

`App\Services\PaymentReceiptService` — espejo de `ExpenseAttachmentService`:

- Constantes: `ALLOWED_MIMES` (`image/jpeg`, `image/png`, `image/webp`, `application/pdf`), `MAX_BYTES` (5 MB), `MAX_PER_PAYMENT = 3`.
- `disk(): string` — `config('expenses.disk', 'local')`, disco privado compartido con Gastos.
- `attach(Payment|CustomerPayment $parent, iterable $files, ?int $uploadedBy): array` — guarda cada archivo en `tenants/{tenant_id}/payment_receipts/{prefix}/{uuid}.{ext}` (`prefix` = `p-{payment_id}` para ventas, `cg-{customer_payment_id}` para cobros globales) con `visibility: private`, y crea la fila `PaymentReceipt`. Ignora silenciosamente entradas que no sean `UploadedFile` o que fallen al guardarse.
- `delete(PaymentReceipt $receipt): void` — borra el archivo del disco y luego la fila.
- No decide permisos ni flags — eso vive en los controladores (mismo principio que el resto de servicios de adjuntos del proyecto).

## Roles y permisos

| Acción | superadmin | admin-empresa | admin-sucursal | cajero |
|---|:-:|:-:|:-:|:-:|
| Configurar `payment_receipts_enabled`/`required` por sucursal | — | ✅ (Editar Sucursal) | ❌ | ❌ |
| Cobrar venta por transferencia con comprobante en el momento | ✅ | — (no cobra) | ✅ su sucursal | ✅ su sucursal |
| Cobro global de fiado con comprobante en el momento | ✅ | — | ✅ su sucursal | ❌ (sin ruta de store; sí puede vía asistente IA, exento de `required`) |
| Ver comprobantes de un pago | quien puede ver el pago (mismo criterio que el pago/venta) | | | |
| Adjuntar / eliminar comprobante después del cobro | ✅ cualquiera de su sucursal | ✅ cualquiera de su sucursal | ✅ cualquiera de su sucursal | ✅ **solo pagos propios de su turno abierto** |
| Eliminar comprobante de cobro global vía prefijo `/caja` | — | — | — | ❌ (no existe ruta `destroy` en el grupo `caja`; el cajero adjunta/descarga los suyos pero no elimina) |

Regla de turno del cajero (`payments`/`customer_payments` no tienen `shift_id`): se deriva por `user_id === $payment->user_id` (o `$customerPayment->user_id`) **y** `created_at >= turno_abierto->opened_at`. Fuera de esas condiciones → 403 `"Solo puedes modificar comprobantes de tus pagos del turno abierto."`. Sin turno abierto → mismo 403.

Con el flag apagado (`enabled` y `required` ambos `false`), todos los endpoints de comprobantes devuelven 403 `"Tu empresa no ha habilitado esta función para tu sucursal."` (mismo mensaje que produce `EnsureBranchFeature`, aunque estos endpoints no usan ese middleware directamente — la validación combina dos flags con OR, algo que el middleware de flag único no expresa).

## Flujos

1. **Cobro de venta con comprobante en el momento.** `Sucursal\PaymentController@store` (compartido por `sucursal.workbench.payment` y `caja.payment.store` — es el mismo controlador para ambos prefijos) acepta `receipts[]` opcional en el mismo multipart del cobro. Solo se procesan si `method === 'transfer'` y el flag `enabled`/`required` está prendido; con otros métodos, cualquier archivo enviado se ignora. El adjunto ocurre **dentro de la misma `DB::transaction`** que crea el `Payment` y llama a `SalePaymentService::recalculate()`.
2. **`required` bloquea el cobro sin archivo.** Si `payment_receipts_required` está activo y el método es `transfer` sin `receipts[]`, la petición nunca crea el `Payment`: 422 con el mensaje `"Adjunta el comprobante de la transferencia."` en la clave `receipts`. Otros métodos de pago no se ven afectados.
3. **Cobro global de fiado con comprobante en el momento.** `Sucursal\CustomerPaymentController@store` (ruta `sucursal.clientes.cobro-global`, exclusiva de admin-sucursal/superadmin — no hay contraparte de creación en `/caja`) aplica la misma regla de `required`, pero el comprobante se adjunta al `CustomerPayment` padre **después** de que `CustomerGlobalPaymentService::apply()` reparte el monto en los `Payment` hijos. El adjunto no está envuelto en la transacción del servicio a propósito: si falla, el cobro queda válido sin comprobante (preferible a perder el cobro).
4. **Adjuntar/descargar/eliminar después del cobro.** Los cuatro endpoints dedicados (`pagos/{payment}/comprobantes*` y `cobros/{customerPayment}/comprobantes*`, en ambos prefijos `sucursal`/`caja`) cubren la captura tardía — el caso más común, porque el cliente suele mandar la captura minutos después de cobrar.
5. **Descarga.** Streaming autenticado (`Storage::download()`, `Content-Disposition: attachment`) — valida que el `receipt` pertenezca al `payment`/`customerPayment` de la URL (404 si no) y que el usuario tenga acceso de vista (branch_id + flag).
6. **Eliminar un pago borra sus comprobantes.** `PaymentController@destroy` (corrección administrativa, solo roles admin) recolecta las rutas de archivo antes de borrar las filas, hace `$payment->receipts()->delete()` + `$payment->delete()` dentro de la transacción, y difiere el borrado físico a `DB::afterCommit()` — así un rollback nunca deja filas apuntando a un archivo ya eliminado del disco.
7. **Cancelar un cobro global conserva sus comprobantes.** `CustomerPaymentController@destroy` hace soft-delete de los `Payment` hijos y del `CustomerPayment` padre (marca `cancelled_at`/`cancelled_by`/`cancel_reason`); nunca toca `payment_receipts` — los archivos y filas sobreviven a la cancelación.

## Rutas

Comparten prefijo/rol con los pagos existentes. `{tenant}` se omite (resuelto por `ResolveTenant`).

### Adjuntar en el momento del cobro (ya existentes, extendidos con `receipts[]`)

| Método | Ruta | Nombre | Rol |
|---|---|---|---|
| POST | `sucursal/mesa-de-trabajo/ventas/{sale}/pagos` | `sucursal.workbench.payment` | admin-sucursal, admin-empresa, superadmin |
| POST | `caja/ventas/{sale}/pagos` | `caja.payment.store` | cajero, superadmin (mismo controlador que el de arriba) |
| POST | `sucursal/clientes/{customer}/cobro-global` | `sucursal.clientes.cobro-global` | admin-sucursal, superadmin |

### Comprobantes de pago de venta

| Método | Ruta | Nombre |
|---|---|---|
| POST | `sucursal/pagos/{payment}/comprobantes` | `sucursal.pagos.receipts.store` |
| GET | `sucursal/pagos/{payment}/comprobantes/{receipt}` | `sucursal.pagos.receipts.download` |
| DELETE | `sucursal/pagos/{payment}/comprobantes/{receipt}` | `sucursal.pagos.receipts.destroy` |
| POST | `caja/pagos/{payment}/comprobantes` | `caja.pagos.receipts.store` |
| GET | `caja/pagos/{payment}/comprobantes/{receipt}` | `caja.pagos.receipts.download` |
| DELETE | `caja/pagos/{payment}/comprobantes/{receipt}` | `caja.pagos.receipts.destroy` |

### Comprobantes de cobro global

| Método | Ruta | Nombre |
|---|---|---|
| POST | `sucursal/cobros/{customerPayment}/comprobantes` | `sucursal.cobros.receipts.store` |
| GET | `sucursal/cobros/{customerPayment}/comprobantes/{receipt}` | `sucursal.cobros.receipts.download` |
| DELETE | `sucursal/cobros/{customerPayment}/comprobantes/{receipt}` | `sucursal.cobros.receipts.destroy` |
| POST | `caja/cobros/{customerPayment}/comprobantes` | `caja.cobros.receipts.store` |
| GET | `caja/cobros/{customerPayment}/comprobantes/{receipt}` | `caja.cobros.receipts.download` |

El grupo `caja` **no** expone `destroy` para comprobantes de cobro global: el cajero no crea cobros globales por la web (solo vía asistente IA), así que puede adjuntar/descargar los suyos pero no eliminarlos — verificado con `route:list --name=receipts` (11 rutas totales: 3+3 en `sucursal`, 3+2 en `caja`).

Controladores: `App\Http\Controllers\Sucursal\PaymentReceiptController` y `App\Http\Controllers\Sucursal\CustomerPaymentReceiptController` (bajo el namespace `Sucursal` mismo para las rutas de `caja`, igual que `PaymentController`).

### Validación de subida (ambos controladores de comprobantes)

| Campo | Reglas |
|---|---|
| `receipts` | required (endpoint dedicado) / nullable (store del cobro), array, max 3 |
| `receipts.*` | `mimes:jpg,jpeg,png,webp,pdf`, `mimetypes` real, `max:5120` KB |

Mensajes: `"Máximo 3 comprobantes por pago."`, `"Solo se permiten imágenes (jpg, png, webp) o PDF."`, `"Cada archivo no puede superar 5 MB."`. El tope de 3 se revalida contra el conteo **acumulado** (`existentes + entrantes`), no solo por request.

## Frontend

- **`Components/PaymentReceiptsPanel.vue`** — panel reutilizable (se monta dentro de `Components/Modal.vue` existente) para ver/agregar/descargar/eliminar comprobantes de un pago o cobro global ya creado. Usa **Inertia `router.post`/`router.delete`** (no axios) porque los endpoints de T5/T6 responden `back()->with('success', ...)` / `back()->withErrors(...)` — el flujo estándar de Inertia. Props: `receipts`, `parentType` (`payment` | `customer-payment`), `parentId`, `canManage`, `tenantSlug`, `routePrefix` (`sucursal` | `caja`). `canDelete` se apaga automáticamente si `routePrefix === 'caja' && parentType === 'customer-payment'` (sin ruta `destroy` ahí).
- **Clips `📎 {count}`** — visibles cuando `method === 'transfer' && (branchInfo.payment_receipts_enabled || payment_receipts_required)`, en: `Components/Sucursal/SaleDetail.vue` y `Components/Caja/SaleDetail.vue` (filas de "Pagos"), `Pages/Sucursal/Pagos/Index.vue` y `Pages/Caja/Pagos/Index.vue` (filas de lista y detalle de pago), `Components/Clientes/CustomerFinancesTab.vue` (solo filas de cobro global en el ledger de cliente).
- **Input de comprobante al cobrar** — implementado inline (no vía un componente de formulario compartido) en `Components/Sucursal/SaleDetail.vue`, `Components/Caja/SaleDetail.vue` y `Components/Clientes/CustomerPaymentModal.vue`: aparece cuando el método es `transfer` y el flag está prendido; con `required`, el botón "Cobrar" se deshabilita sin archivo y muestra la ayuda `"Adjunta el comprobante para poder cobrar."`.
- **`Components/PaymentForm.vue`** — componente standalone que **no se importa en ningún lugar de la app** (código muerto, confirmado por `grep`); se actualizó por consistencia con el resto del módulo pero el flujo real de cobro vive inline en los `SaleDetail.vue` y en `CustomerPaymentModal.vue`.
- Serialización `receipts` (`id, payment_id, customer_payment_id, original_name, mime_type, size_bytes`) añadida en 7 controladores: `Sucursal\WorkbenchController`, `Caja\WorkbenchController`, `Sucursal\SaleHistoryController`, `Caja\HistorialController`, `Sucursal\PagosController`, `Caja\PagosController`, `Sucursal\CustomerStatsController` (endpoint `payments()`, usado por `CustomerFinancesTab.vue`).
- Toggle de configuración: `Pages/Empresa/Sucursales/Edit.vue`, sección "Comprobantes de pago" — dos switches (Comprobantes de transferencia / Exigir comprobante), el segundo deshabilitado si el primero está apagado (`:disabled="!form.payment_receipts_enabled"`, con `watch` que apaga `required` al apagar `enabled`).

## Riesgos y limitaciones

| Riesgo | Mitigación / estado |
|---|---|
| Filtración cross-tenant/cross-branch de archivos | Disco privado + descarga valida `tenant_id` (scope automático de `BelongsToTenant`) y `branch_id` del usuario contra la venta/cobro dueño del comprobante. Test `test_receipt_belonging_to_another_customer_payment_returns_404`. |
| Archivos huérfanos si el proceso muere entre el commit y el callback `afterCommit` | `PaymentController@destroy` borra filas dentro de la transacción y difiere el borrado físico a `DB::afterCommit()` para que un *rollback* nunca deje una fila apuntando a un archivo ya borrado. El caso inverso — la transacción hace commit pero el proceso muere antes de ejecutar el callback — sí puede dejar un archivo huérfano en disco (fila ya no existe, archivo sí). **Riesgo aceptado**: es el mismo patrón que usa `DB::afterCommit` en el resto del proyecto para operaciones post-commit; el archivo huérfano no representa un riesgo de datos (nadie puede volver a asociarlo) y un job de limpieza de disco quedaría fuera de alcance de este módulo. |
| 403/404 del panel de comprobantes no se ven inline | `PaymentReceiptsPanel.vue` usa Inertia `router.post/delete`, que solo enruta al callback `onError` cuando la respuesta trae el header `X-Inertia` (caso de validación 422). Un rechazo de autorización real (403 fuera de turno, 404 cross-branch) es una página de error normal de Laravel sin ese header, así que Inertia no lo puede interceptar — se ve el overlay de error por defecto del navegador Inertia. **Riesgo aceptado**: mismo comportamiento preexistente en `GastoFormModal.vue::removeExistingAttachment`; evita tocar los controladores de comprobantes solo para uniformar el manejo de error. |
| MIME falseado | Doble validación: `mimes` (extensión) + `mimetypes` (tipo real detectado). |
| Cobro global grande sin comprobante si el `attach()` falla tras crear el CG | Decisión deliberada (no un bug): el cobro queda registrado y válido; el comprobante puede adjuntarse después vía los endpoints dedicados. Prioriza no perder dinero cobrado sobre completitud del comprobante. |
| Paridad con el hub (Electron) pendiente | Fuera de alcance de esta iteración (solo web). El hub replicará después reutilizando `PaymentReceiptService`; se rastrea en la auditoría de paridad hub↔web cuando la web esté desplegada. |

## Tests

- `tests/Feature/Sucursal/PaymentReceiptTest.php` (13 tests) — modelo/relación, `PaymentReceiptService::attach()/delete()` sobre disco fake, cobro de venta con/sin comprobante bajo `required`, endpoints de adjuntar/descargar/eliminar después, flag apagado → 403, método ≠ transfer → 422, reglas de turno del cajero (fuera de turno, pago ajeno, pago anterior al turno), cascade de borrado de pago.
- `tests/Feature/Sucursal/CustomerPaymentReceiptTest.php` (14 tests) — cobro global con comprobante en el padre (hijos sin comprobante propio), `required` en cobro global, reglas de turno del cajero sobre CG, `caja` sin ruta `destroy` para CG, tope acumulado de 3, comprobante de otro CG → 404, **cancelar CG conserva comprobantes**.
- Asserts de serialización en tests de props Inertia ya existentes (más baratos que crear archivos nuevos): `tests/Feature/Http/Empresa/SucursalControllerTest.php` (persistencia de los toggles de sucursal), `tests/Feature/Sucursal/CustomerShowControllerTest.php` (flags `paymentReceiptsEnabled`/`paymentReceiptsRequired` expuestos al detalle de cliente), `tests/Feature/Caja/PagosIndexTest.php` y `tests/Feature/Sucursal/PagosSummaryTest.php` (`payments.data.*.receipts` en la lista de Pagos de ambos prefijos).

```bash
sail artisan test --filter='PaymentReceiptTest|CustomerPaymentReceiptTest|SucursalControllerTest|CustomerShowControllerTest|PagosIndexTest|PagosSummaryTest'
```
