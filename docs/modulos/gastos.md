# Gastos

Registro trazable de gastos operativos de la empresa (servicios, insumos, nómina, mantenimiento, transporte, renta, etc.) con archivos adjuntos como evidencia. Aislamiento multi-tenant estricto.

## Responsabilidades

- Mantener un catálogo de **categorías** y **subcategorías** a nivel tenant.
- Registrar gastos con monto, concepto, subcategoría, fecha/hora editable, sucursal opcional, descripción y adjuntos.
- Conservar evidencia (tickets, facturas, comprobantes) en almacenamiento privado, accesible sólo a usuarios autorizados.
- Soft-delete con motivo para no perder información financiera.
- Filtros y agregaciones para reporte (base preparada para utilidad real en el dashboard).

## Modelo de datos

```
expense_categories  (tenant_id, name, status, created_by, timestamps)
└─ expense_subcategories (tenant_id, expense_category_id, name, status, created_by, timestamps)
   └─ expenses (tenant_id, branch_id?, expense_subcategory_id, user_id, updated_by?,
                cancelled_by?, concept, amount(12,2), expense_at, description?,
                deleted_at, cancellation_reason?, timestamps)
      └─ expense_attachments (expense_id, tenant_id, uploaded_by?, original_name,
                                path, mime_type, size_bytes, timestamps)
```

### Decisiones

| Decisión | Razón |
|---|---|
| Categorías/subcategorías a nivel tenant | Reportes consolidados agrupan por id, sin duplicación entre sucursales. |
| `branch_id` **NOT NULL** en `expenses` | Cada gasto pertenece a una sucursal. Reportes por sucursal limpios sin huecos. Gastos verdaderamente corporativos se asignan a la sucursal matriz. |
| Soft delete del gasto | Trazabilidad financiera: nunca perder un registro. |
| `tenant_id` denormalizado en `expense_attachments` | Validar acceso a la descarga sin JOIN. |
| `expense_at` separado de `created_at` | El usuario puede capturar gastos de días anteriores. **El usuario solo elige el día**; el sistema estampa la hora actual del registro. |
| Adjuntos en disco `local` (privado) | Tickets/facturas son sensibles — sólo descarga/preview autenticado. |
| Categorías y gastos NO se siembran por defecto | Cada empresa crea las suyas según su operación. El demo seeder no inserta nada de gastos. |

## Roles y permisos

| Acción | superadmin | admin-empresa | admin-sucursal | cajero |
|---|:-:|:-:|:-:|:-:|
| Ver/editar/eliminar gastos del tenant | ✅ | ✅ todas las sucursales | sólo su sucursal | ❌ |
| Crear gasto (con sucursal obligatoria) | ✅ | ✅ elige sucursal | ✅ forzado a la suya | ❌ |
| CRUD categorías y subcategorías | ✅ | ✅ | ❌ (sólo lectura) | ❌ |
| Descargar / previsualizar adjuntos | ✅ | ✅ todas | ✅ sólo de su sucursal | ❌ |

Implementación: middleware `role:...` en cada grupo de rutas + checks manuales `tenant_id`/`branch_id` en cada controller (igual que `WithdrawalController`).

## Rutas

### Empresa (admin-empresa)

```
GET    /{tenant}/empresa/gastos                              empresa.gastos.index
POST   /{tenant}/empresa/gastos                              empresa.gastos.store
PUT    /{tenant}/empresa/gastos/{gasto}                      empresa.gastos.update
DELETE /{tenant}/empresa/gastos/{gasto}                      empresa.gastos.destroy

POST   /{tenant}/empresa/gastos/categorias                   empresa.gastos.categorias.store
PUT    /{tenant}/empresa/gastos/categorias/{category}        empresa.gastos.categorias.update
DELETE /{tenant}/empresa/gastos/categorias/{category}        empresa.gastos.categorias.destroy

POST   /{tenant}/empresa/gastos/subcategorias                empresa.gastos.subcategorias.store
PUT    /{tenant}/empresa/gastos/subcategorias/{subcategory}  empresa.gastos.subcategorias.update
DELETE /{tenant}/empresa/gastos/subcategorias/{subcategory}  empresa.gastos.subcategorias.destroy

GET    /{tenant}/empresa/gastos/{gasto}/adjuntos/{attachment}  empresa.gastos.adjuntos.download
DELETE /{tenant}/empresa/gastos/{gasto}/adjuntos/{attachment}  empresa.gastos.adjuntos.destroy
```

### Sucursal (admin-sucursal)

```
GET    /{tenant}/sucursal/gastos                              sucursal.gastos.index
POST   /{tenant}/sucursal/gastos                              sucursal.gastos.store
PUT    /{tenant}/sucursal/gastos/{gasto}                      sucursal.gastos.update
DELETE /{tenant}/sucursal/gastos/{gasto}                      sucursal.gastos.destroy

GET    /{tenant}/sucursal/gastos/{gasto}/adjuntos/{attachment}  sucursal.gastos.adjuntos.download
DELETE /{tenant}/sucursal/gastos/{gasto}/adjuntos/{attachment}  sucursal.gastos.adjuntos.destroy
```

## Validaciones

| Campo | Reglas |
|---|---|
| `concept` | required, string, max 160 |
| `amount` | required, numeric, min 0.01, max 99,999,999.99 |
| `expense_subcategory_id` | required, exists del tenant actual con `status='active'` |
| `expense_date` | required, formato `Y-m-d`, ≤ mañana (tolerancia TZ) |
| `description` | nullable, string, max 1000 |
| `branch_id` | **required**; debe pertenecer al tenant; admin-sucursal se fuerza a su branch |
| `attachments[]` | array, max 5 elementos por gasto |
| `attachments.*` | mimes:jpg,jpeg,png,webp,pdf · mimetypes verificados · max 5 MB |

> **Fecha vs hora:** el form recibe sólo `expense_date` (YYYY-MM-DD). El backend
> compone `expense_at = expense_date + now()->format('H:i:s')`. Así, capturar
> un gasto del día anterior estampa la hora actual del registro automáticamente.

## Almacenamiento de archivos

- Disco: `local` (privado, `storage/app/private`).
- Path: `tenants/{tenant_id}/expenses/{expense_id}/{uuid}.{ext}`.
- Subida vía `App\Services\ExpenseAttachmentService::attach()`.
- **Dos endpoints** de servido:
  - `download` — `Storage::download()` con `Content-Disposition: attachment` (descarga forzada).
  - `preview` — `Storage::get()` con `Content-Disposition: inline` (visualiza en `<img>` o `<iframe>` sin descargar).
- Ambos validan `tenant_id` y, para `admin-sucursal`, también `branch_id`.
- Eliminación física: hook `deleting` en `ExpenseAttachment` borra el archivo. El soft-delete del gasto **no** borra archivos (auditoría).
- Frontend: `Components/Gastos/AttachmentViewerModal.vue` usa el endpoint `preview` para imagen (img tag) y PDF (iframe). Botón secundario "Descargar" usa el endpoint `download`.

## UI

Default de listado: **gastos del día actual**. El backend aplica `expense_at >= today AND <= today` cuando no llegan `from`/`to`. Filtros disponibles: rango con presets (Hoy, Ayer, 7 días, Este mes, Mes pasado, Este año), Personalizado y un día específico (mismo `from=to`).

### `/empresa/gastos`

Pantalla con dos pestañas:

- **Gastos**: KPIs (Total filtrado, # Gastos, Promedio), filtros (DateField rango, sucursal, categoría → subcategoría dependiente, búsqueda), modales de form y detalle. Adjuntos visibles como badge azul con conteo.
- **Categorías**: lista de categorías con sus subcategorías. CRUD inline. Bloqueo de borrado si la categoría tiene subcategorías o la subcategoría tiene gastos. Empty state con CTA cuando no hay categorías.

### `/sucursal/gastos`

KPIs + filtros (DateField rango, categoría, subcategoría, búsqueda). Si el tenant no tiene categorías configuradas, banner ámbar indicando contactar al admin de empresa. Sucursal del gasto se fija automáticamente a `$user->branch_id`.

### Componentes reutilizables

- `Components/DateField.vue` — selector de fecha tipo iOS, popover, sin dependencias. Modos `single` y `range`. Presets integrados (Hoy/Ayer en single; Hoy/Ayer/7 días/Este mes/Mes pasado/Este año en range). **Reemplaza el input nativo `<input type="date">` en todo el sistema** — también lo usa `Metrics/DateRangeFilter.vue`.
- `Components/Gastos/GastoFormModal.vue` — form crear/editar con cascada categoría → subcategoría, fecha (sólo día), multi-upload con validación cliente, eliminación de adjuntos existentes.
- `Components/Gastos/GastoDetailModal.vue` — detalle con monto destacado y grid de adjuntos como thumbnails. Click abre el viewer.
- `Components/Gastos/AttachmentViewerModal.vue` — visor inline para imagen (img) y PDF (iframe). Navegación prev/next, descarga secundaria, atajos de teclado (Esc, ArrowLeft, ArrowRight).

## Auditoría y trazabilidad

| Campo | Significado |
|---|---|
| `expenses.user_id` | Quién creó el gasto |
| `expenses.updated_by` | Último usuario que lo editó |
| `expenses.cancelled_by` | Quién lo eliminó |
| `expenses.cancellation_reason` | Motivo opcional al eliminar |
| `expenses.created_at`/`updated_at`/`deleted_at` | Timestamps automáticos de Eloquent |
| `expense_attachments.uploaded_by` | Quién subió cada archivo |

En F2 se planea agregar tabla `expense_audit_log` con before/after JSON.

## Reportes (base preparada)

`expenses` tiene índices `(tenant_id, expense_at)` y `(branch_id, expense_at)` para listados temporales eficientes. Las agregaciones por subcategoría/sucursal/usuario quedan disponibles para el cálculo de utilidad real en el dashboard:

```
utilidad = ventas - costo de producción - gastos
```

## Comandos rápidos

```bash
# Resetear DB (módulo de Gastos arranca vacío — sin categorías sembradas)
php artisan migrate:fresh --seed

# Tests del módulo
php artisan test --filter='Empresa\\GastoControllerTest|Sucursal\\GastoControllerTest'
```

## Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Filtración cross-tenant de archivos | Disco privado + ruta de descarga que valida `tenant_id` y `branch_id`. Test feature `attachment download is protected by tenant`. |
| Archivos huérfanos al borrar | Hook `deleting` en `ExpenseAttachment` borra el archivo físico. Test `attachment destroy removes file from disk`. |
| Subcategoría borrada con gastos | `restrictOnDelete` a nivel BD + UX bloquea hard-delete con `withTrashed` count. Test `subcategory with expenses cannot be deleted`. |
| MIME falseado | Doble validación: `mimes` (extensión) + `mimetypes` (real). Test `attach rejects disallowed mime`. |
| Branch_id manipulado por admin-sucursal | Controller fuerza `$user->branch_id` y rechaza updates de otra sucursal con 403. Test `admin sucursal creates expense forced to own branch`. |

## Roadmap

- **F2**: integración con dashboard de utilidad real, exportación CSV/PDF, agregaciones por subcategoría/sucursal, tabla de auditoría con before/after.
- **F3**: aprobaciones por umbral, recurrencia (renta mensual), proveedores, registro rápido desde Caja, OCR de tickets.
