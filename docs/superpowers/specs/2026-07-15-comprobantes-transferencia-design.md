# Comprobantes de pago en transferencias

**Fecha:** 2026-07-15
**Estado:** Implementado (2026-07-16) — ver docs/modulos/comprobantes-pago.md
**Alcance:** web (Sucursal y Caja). El hub replicará después por paridad con sus endpoints `/api/v1/hub/*` (spec/plan aparte). Fuera de alcance: OCR/IA sobre el comprobante, notificaciones, pagos a proveedores (compras y gastos ya tienen adjuntos propios).

## Problema

Cuando un cliente paga por transferencia, envía una captura del comprobante (JPG/PNG o PDF). Hoy no hay dónde guardarla: queda en el WhatsApp del cajero o del dueño, sin liga con el pago registrado. Se necesita adjuntar comprobantes a los cobros por transferencia — tanto de ventas como de cobros de fiado — con control por sucursal de si es opcional u obligatorio.

## Decisiones (aprobadas por el usuario)

| Decisión | Valor |
|---|---|
| Alcance de pagos | Pagos de venta (`Payment`) y cobros globales de fiado (`CustomerPayment`). No aplica a pagos hijos de un cobro global (el comprobante vive en el padre CG) |
| Obligatoriedad | Configurable por sucursal: `payment_receipts_enabled` (permite adjuntar) + `payment_receipts_required` (exige comprobante al cobrar por transferencia). `required` implica `enabled` |
| Momento | Al cobrar (multipart en el mismo request) y también después (endpoints dedicados), porque la captura suele llegar tarde |
| Permisos | Regla de gastos: ver = quien ve el pago; adjuntar/eliminar = cajero solo pagos de su turno abierto, admin-sucursal cualquiera de su sucursal |
| Storage | Disco privado (`ExpenseAttachmentService::disk()`), rutas `tenants/{tenant}/payment_receipts/{payment|cg}/{uuid}.ext`, descarga solo por endpoint autenticado con streaming. Nunca URLs públicas (datos bancarios) |
| Modelo de datos | Tabla `payment_receipts` con dos FKs nullable (`payment_id`, `customer_payment_id`) + CHECK de exactamente-uno. (Nota: NO es idéntico al esquema de `payments`, donde `sale_id` es NOT NULL y `customer_payment_id` es liga al padre — aquí el CHECK exige exactamente un padre.) Sin morphs (el proyecto no los usa) |
| Límites | jpg/jpeg/png/webp/pdf y 5 MB por archivo (mismos mimes/tamaño que gastos); máx. **3 por pago** (propio de este módulo — gastos usa 5) |

## Modelo de datos

```
payment_receipts
  id
  tenant_id            FK tenants
  payment_id           FK payments, nullable
  customer_payment_id  FK customer_payments, nullable
  uploaded_by          FK users, nullable
  original_name        string(255)
  path                 string
  mime_type            string
  size_bytes           unsignedInteger
  created_at / updated_at
  CHECK ((payment_id IS NULL) != (customer_payment_id IS NULL))
```

Modelo `PaymentReceipt` con `BelongsToTenant` (se agrega a la lista de modelos tenant en el CLAUDE.md del workspace). Relaciones `receipts()` en `Payment` y `CustomerPayment`.

Columnas nuevas en `branches`: `payment_receipts_enabled` (bool, default false), `payment_receipts_required` (bool, default false). Fillable + cast + editables por admin-empresa en Empresa → Editar Sucursal, junto a los toggles existentes.

## Servicio de dominio

`App\Services\PaymentReceiptService`, espejo de `ExpenseAttachmentService`:

- Constantes: `ALLOWED_MIMES`, `MAX_BYTES` (5 MB), `MAX_PER_PAYMENT = 3`, `disk()` (comparte `config('expenses.disk')`).
- `attach(Payment|CustomerPayment $parent, array $files, ?int $uploadedBy): array` — guarda en disco privado y crea filas.
- `delete(PaymentReceipt $receipt): void` — borra archivo + fila.
- No decide permisos ni flags: eso es de los controladores (igual que los demás servicios de adjuntos).

## Reglas de negocio

1. Solo pagos con `method = transfer` aceptan comprobantes (venta o cobro global). Si el método del pago se edita después y deja de ser transferencia, los comprobantes existentes **se conservan** (evidencia histórica).
2. Con `payment_receipts_required` activo: registrar un pago por transferencia (venta o cobro global) sin al menos un archivo → 422 `"Adjunta el comprobante de la transferencia."`. Los demás métodos no cambian. **El `required` se valida en los controladores de los formularios web de cobro, nunca dentro de los servicios de dominio:** el confirm del asistente IA (`CustomerGlobalPaymentDraftConfirmer`, que crea cobros globales por transferencia sin posibilidad de adjuntar en el chat) queda exento — su comprobante se adjunta después vía los endpoints dedicados.
3. Con `payment_receipts_enabled` apagado: los endpoints de comprobantes devuelven 403 `"Tu empresa no ha habilitado esta función para tu sucursal."` (mismo mensaje que `EnsureBranchFeature`).
4. Mutación (adjuntar/eliminar tras el cobro): cajero solo en pagos ligados a su turno abierto; admin-sucursal cualquiera de su sucursal. Paridad con la regla de corrección de gastos.
5. La creación del pago sigue pasando por `SalePaymentService` / `CustomerGlobalPaymentService` sin cambios: el adjunto se procesa en el controlador dentro de la misma transacción, después de obtener el `Payment`/`CustomerPayment` creado.

## Endpoints (web, por rol como los pagos actuales)

Los `store` de pago existentes aceptan `receipts[]` opcional (multipart):
- Cobro de venta: `Sucursal\PaymentController@store` — **es el mismo controlador para ambos prefijos**: la ruta `caja.payment.store` lo reusa (no existe `Caja\PaymentController`). Un solo store que modificar.
- Cobro global: `Sucursal\CustomerPaymentController@store`. **No hay ruta de store de cobro global en caja**; los endpoints `cobros/.../comprobantes` con prefijo caja existen solo para adjuntar/descargar después (p. ej. cobros creados por el asistente ligados a su turno).

Endpoints nuevos (prefijos `sucursal`/`caja` según rol, protegidos por el flag):

| Método | Ruta | Acción |
|---|---|---|
| POST | `pagos/{payment}/comprobantes` | Adjuntar (multipart, respeta MAX_PER) |
| GET | `pagos/{payment}/comprobantes/{receipt}` | Descargar (streamed, autenticado) |
| DELETE | `pagos/{payment}/comprobantes/{receipt}` | Eliminar |
| POST/GET/DELETE | `cobros/{customerPayment}/comprobantes[/{receipt}]` | Ídem para cobro global |

## UI web

- **Cobro (mesa de trabajo / historial):** al elegir Transferencia con el flag activo aparece "Adjuntar comprobante" (input de archivo + nombre elegido); con `required`, el botón Cobrar se deshabilita sin archivo y muestra la ayuda.
- **Cobro global de fiado (detalle de cliente):** mismo bloque en el formulario de cobro.
- **Listas (Pagos, detalle de venta, ledger de fiado):** los pagos por transferencia muestran un clip con contador (📎 1); clic abre ver/descargar/eliminar/agregar, reusando el patrón visual de `AttachmentsSection` de gastos/compras.
- Los controladores que sirven esas listas exponen `receipts` (id, original_name, mime_type, size_bytes) y los flags de la sucursal.

## Errores y bordes

- Archivo inválido → 422 con los mensajes de gastos ("Solo se permiten imágenes (jpg, png, webp) o PDF.", "Cada archivo no puede superar 5 MB.", "Máximo 3 adjuntos…").
- Pago hijo de cobro global → 422 "El comprobante va en el cobro global." si se intenta adjuntar directo.
- Pago de otro método → 422 "Solo los pagos por transferencia llevan comprobante."
- Eliminación de un pago (corrección admin) → borra sus comprobantes (archivo + fila) en la misma transacción.

## Tests

Feature tests (PHPUnit) por rol:
- Cobrar venta por transferencia con `receipts[]` crea pago + comprobante en disco privado (Storage::fake).
- `required` activo sin archivo → 422; con otros métodos no exige.
- Cobro global con comprobante en el padre CG; hijo directo → 422.
- Cajero: adjuntar/eliminar solo en pagos de su turno abierto (403 fuera); admin: cualquiera de su sucursal.
- Flag apagado → 403 en endpoints nuevos y `receipts[]` ignorado/rechazado en el store.
- Descarga autenticada devuelve el archivo; usuario de otra sucursal → 404/403.
- Límites: mime inválido, >5 MB, >3 archivos → 422.
- Eliminar pago borra comprobantes (fila y archivo).

## Paridad futura (hub)

El hub replicará con `POST /api/v1/hub/sales/{id}/payments` aceptando multipart (o endpoint de comprobantes aparte reutilizando `PaymentReceiptService`), y los flags viajarán en el payload de auth o del endpoint. Se rastrea en la auditoría de paridad cuando la web esté desplegada.
