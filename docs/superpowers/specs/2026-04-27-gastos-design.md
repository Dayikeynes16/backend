# Módulo de Gastos — Diseño congelado

**Fecha:** 2026-04-27
**Estado:** Aprobado para Fase 1
**Autor:** colaboración con Claude (auditoría + propuesta)

## Objetivo

Permitir a la empresa registrar de forma trazable los gastos operativos
(servicios, insumos, nómina, mantenimiento, transporte, renta, etc.),
con archivos adjuntos como evidencia (tickets, facturas), aislamiento
multi-tenant estricto y base lista para que el dashboard calcule la
**utilidad real** (`ventas − costo de producción − gastos`).

## Alcance F1 (mínimo viable)

- CRUD de **categorías** y **subcategorías** de gastos a nivel **tenant**
  (compartidas entre todas las sucursales del tenant).
- CRUD de **gastos** con campos mínimos: concepto, monto, fecha/hora
  editable, subcategoría obligatoria, sucursal opcional (gasto
  corporativo permitido), descripción y adjuntos.
- **Adjuntos** privados: jpg/jpeg/png/webp/pdf, hasta 5 archivos × 5 MB
  por gasto. Disco `local` (privado), descarga vía ruta autenticada.
- **Soft delete** del gasto con motivo de cancelación opcional.
- **Filtros**: rango de fechas, categoría, subcategoría, sucursal,
  búsqueda libre y usuario.
- **Permisos por rol**:
  - `superadmin`: todo.
  - `admin-empresa`: CRUD completo de categorías, subcategorías y gastos
    de su tenant; puede registrar gastos corporativos (sin sucursal).
  - `admin-sucursal`: CRUD de gastos de **su sucursal**; lectura de
    categorías/subcategorías; no las administra.
  - `cajero`: sin acceso.

Fuera de F1: aprobaciones, recurrencia, exportación CSV/PDF, OCR,
proveedores, integración con dashboard (queda preparada la base de
datos), tabla de auditoría con before/after.

## Modelo de datos

```
expense_categories (tenant_id, name, status, created_by, timestamps)
└─ expense_subcategories (tenant_id, expense_category_id, name, status, created_by, timestamps)
   └─ expenses (tenant_id, branch_id?, expense_subcategory_id, user_id,
                 updated_by?, concept, amount(12,2), expense_at, description?,
                 deleted_at, cancellation_reason?, cancelled_by?, timestamps)
      └─ expense_attachments (expense_id, tenant_id, original_name, path,
                                mime_type, size_bytes, uploaded_by, timestamps)
```

### Decisiones clave

- **Categorías a nivel tenant**: una categoría "Servicios" se comparte
  entre sucursales; reportes consolidados agrupan limpio por id.
- **`branch_id` nullable en `expenses`**: gastos corporativos como renta
  o contabilidad pueden no asociarse a una sucursal.
- **Soft delete del gasto**: financiero — no perder información. Los
  adjuntos del gasto soft-deleted **se conservan** para auditoría.
- **`tenant_id` denormalizado en `expense_attachments`**: para validar
  acceso a la descarga sin JOIN.
- **`expense_at` separado de `created_at`**: el usuario puede capturar
  un gasto del día anterior; `created_at` registra cuándo se ingresó al
  sistema, `expense_at` cuándo ocurrió realmente.
- **`updated_by` y `cancelled_by`**: trazabilidad sin tabla histórica.

### Indexación

- `expenses (tenant_id, expense_at)` — listados por periodo del tenant.
- `expenses (branch_id, expense_at)` — listados por sucursal.
- `expenses (expense_subcategory_id)` — agrupaciones.
- `expenses (user_id)` — filtros por quién registró.
- `expense_attachments (expense_id)`.

## Almacenamiento de archivos

- Disco: `local` (privado, `storage/app/private`).
- Path: `tenants/{tenant_id}/expenses/{expense_id}/{uuid}.{ext}`.
- Validación de subida: `mimes:jpg,jpeg,png,webp,pdf`, `max:5120` (5 MB),
  doble check con `mimetypes:image/jpeg,image/png,image/webp,application/pdf`.
- Descarga: ruta `/{tenant}/...gastos/{gasto}/adjuntos/{attachment}`
  protegida por `auth + role`. Valida `expense.tenant_id === user.tenant_id`
  (y branch para `admin-sucursal`). Devuelve `Storage::download($path)`.
- Eliminación: hook `deleting` en `ExpenseAttachment` borra el archivo
  físico. Soft-delete del gasto **no** borra archivos.

## Rutas

```
/{tenant}/empresa/gastos                          [GET]    index (admin-empresa)
/{tenant}/empresa/gastos                          [POST]   store
/{tenant}/empresa/gastos/{gasto}                  [PUT]    update
/{tenant}/empresa/gastos/{gasto}                  [DELETE] destroy (soft)
/{tenant}/empresa/gastos/categorias               [POST]   store
/{tenant}/empresa/gastos/categorias/{cat}         [PUT]    update
/{tenant}/empresa/gastos/categorias/{cat}         [DELETE] destroy
/{tenant}/empresa/gastos/subcategorias            [POST]   store
/{tenant}/empresa/gastos/subcategorias/{sub}      [PUT]    update
/{tenant}/empresa/gastos/subcategorias/{sub}      [DELETE] destroy
/{tenant}/empresa/gastos/{gasto}/adjuntos/{att}   [GET]    download
/{tenant}/empresa/gastos/{gasto}/adjuntos/{att}   [DELETE] destroy

/{tenant}/sucursal/gastos                         [GET]    index (admin-sucursal)
/{tenant}/sucursal/gastos                         [POST]   store
/{tenant}/sucursal/gastos/{gasto}                 [PUT]    update
/{tenant}/sucursal/gastos/{gasto}                 [DELETE] destroy (soft)
/{tenant}/sucursal/gastos/{gasto}/adjuntos/{att}  [GET]    download
/{tenant}/sucursal/gastos/{gasto}/adjuntos/{att}  [DELETE] destroy
```

## Validaciones

- `concept`: required, string, max 160.
- `amount`: required, numeric, min 0.01, max 99,999,999.99.
- `expense_subcategory_id`: required, exists en `expense_subcategories`
  con `tenant_id` actual y `status='active'`.
- `expense_at`: required, date, ≤ ahora + 1 día (tolerancia de zona horaria).
- `branch_id`: nullable (sólo admin-empresa puede `null`); si viene,
  debe pertenecer al tenant. `admin-sucursal` se fuerza a su branch.
- `description`: nullable, string, max 1000.
- `attachments[]`: array, max 5 elementos.
- `attachments[].file`: image|pdf, max 5 MB, mimes/mimetypes verificados.

## UI

### `/empresa/gastos` (admin-empresa)

Pantalla con dos pestañas: **Gastos** y **Categorías**.

- **Tab Gastos**: tabla con filtros (rango fechas, sucursal, categoría →
  subcategoría dependiente, búsqueda, usuario). Botón "Registrar gasto"
  abre modal con form. Cards de resumen: total filtrado, # de gastos,
  top 3 subcategorías. Click en fila abre modal de detalle con adjuntos.

- **Tab Categorías**: lista de categorías con sus subcategorías expandibles.
  Acciones inline: crear/editar/desactivar categoría; crear/editar/
  desactivar subcategoría. Bloqueo de borrado si tiene gastos.

### `/sucursal/gastos` (admin-sucursal)

Pantalla simple con tabla de gastos de su sucursal + filtros (rango,
categoría, subcategoría, búsqueda). Botón "Registrar gasto". Sin tab
de categorías (sólo lectura como dropdown del form).

### Componentes compartidos

- `Components/Gastos/GastoFormModal.vue`: form crear/editar con upload
  multi-archivo y selector cascada categoría → subcategoría.
- `Components/Gastos/GastoDetailModal.vue`: vista detalle con preview
  de imágenes y links de PDF.

## Tests F1

- Tenant isolation: tenant A no ve gastos/categorías/adjuntos de B.
- Branch isolation: admin-sucursal de B1 no ve gastos de B2.
- Cajero recibe 403 en cualquier ruta de gastos.
- Validaciones de adjuntos: tipos, tamaño, MIME real.
- Borrado de subcategoría con gastos: bloqueado.
- Soft-delete preserva el registro y `cancelled_by`.
- Descarga autenticada: 403 si tenant no coincide.

## Roadmap posterior (F2/F3)

- F2: integración con dashboard de utilidad real, exportación CSV/PDF,
  agregaciones por subcategoría/sucursal, tabla de auditoría con
  before/after JSON.
- F3: aprobaciones por umbral, recurrencia (renta mensual), proveedores,
  registro rápido desde Caja, OCR de tickets.
