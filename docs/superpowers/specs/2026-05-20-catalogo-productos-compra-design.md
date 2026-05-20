# Catálogo de productos de compra — Diseño congelado

**Fecha:** 2026-05-20
**Estado:** Aprobado para implementación
**Autor:** colaboración con Claude (exploración + propuesta)

## Objetivo

Que **todo lo que se compra salga de un catálogo** propio de compras, en vez
de texto libre. Cada línea de compra debe referirse a un "producto de compra"
del catálogo; si no existe, se crea al vuelo. Esto da consistencia para
reportes (qué compras y a qué precio histórico) y prepara el terreno para
analizar rendimiento más adelante.

El catálogo de compras es **independiente del catálogo de ventas** (lo que
compras —p. ej. "media canal de res" por kg— suele no ser lo que vendes en
mostrador).

## Alcance

- Nueva entidad **`purchase_products`** (productos de compra), **tenant-wide**,
  administrada por **admin-empresa** (mismo patrón que Proveedores).
- Las líneas de compra (`purchase_items`) pasan a **exigir**
  `purchase_product_id`; se elimina el viejo `product_id` que apuntaba a
  productos de **venta**.
- Captura: buscador del catálogo en la línea de compra + **crear al vuelo**
  (nombre + unidad + categoría). Pantalla de administración (CRUD) en
  `/empresa`.
- Captura con **IA** (modal cámara/IA): empareja cada línea por nombre y crea
  el producto si no existe.

### Fuera de alcance (YAGNI)

- No se vincula el producto de compra con productos de venta (se eligió
  "catálogo aparte", no "mixto vinculable"). Sin tracking de rendimiento/merma.
- No se guarda "último precio" como columna: se **deriva** del historial
  (`purchase_items.unit_price`) y se muestra en el buscador.
- No se migran datos históricos a la fuerza (el módulo de compras es nuevo).

## Decisión de campos

Elegido por alineación con el proyecto: **nombre + unidad + categoría**,
tenant-wide, admin-empresa. La **categoría** reusa la idea del `type` de
`Provider` (un enum corto). El **último precio** se deriva del historial, no se
denormaliza.

## Modelo de datos

### Nueva tabla `purchase_products`

```
purchase_products
  id
  tenant_id        FK tenants cascade
  name             string(160)
  unit             string(10)        // kg, pieza, caja, l...
  category         string(20) null   // casts a PurchaseProductCategory
  status           string(12) default 'active'   // active|inactive
  created_by       FK users null nullOnDelete
  timestamps
  softDeletes
  unique(tenant_id, name)            // ignora soft-deleted
  index(tenant_id, status)
  index(tenant_id, category)
```

- Modelo `PurchaseProduct` con `BelongsToTenant` + `SoftDeletes` (igual que
  `Provider`).
- Enum `PurchaseProductCategory` (mismo estilo que `ProviderType`):
  `Res`, `Cerdo`, `Pollo`, `Insumos`, `Otro`. Extensible con una línea.

### Cambios en `purchase_items` (migración nueva, no se edita la original)

- **Añadir** `purchase_product_id` (FK → `purchase_products`, `nullOnDelete`).
  Nullable en BD (por `nullOnDelete` y para preservar historia), pero
  **requerido a nivel de validación**.
- **Eliminar** `product_id` (FK a productos de venta) y su índice.
- `concept` se conserva como **snapshot** del nombre del producto al momento
  de la compra (ya existe). `unit` se conserva en la línea, **prellenado** del
  producto.
- Renombrar la relación `PurchaseItem::product()` → `purchaseProduct()`.
- `HandlesPurchases::serializePurchase()` cambia `product_id` por
  `purchase_product_id` en el map de items.

### Orden de migraciones

Como `purchase_items` se crea en `2026_05_19_000003`, el catálogo va en
migraciones **nuevas** posteriores para respetar el FK en `migrate:fresh`:

1. `2026_05_20_000001_create_purchase_products_table`
2. `2026_05_20_000002_swap_product_id_for_purchase_product_id_on_purchase_items`

## Captura (elegir + crear al vuelo)

- En el form de compra, cada línea tiene un **buscador** de productos de
  compra (por nombre, dentro del tenant). Muestra unidad y, si hay historial,
  el último precio como sugerencia.
- Si no existe, botón **"Crear producto"** inline: nombre + unidad +
  categoría → se crea en `purchase_products` y se usa en la línea.
- Pantalla de administración bajo `/empresa` (CRUD como Proveedores): listar,
  crear, editar, activar/inactivar. Bloqueo de borrado/inactivación dura si
  el producto ya tiene compras (se permite inactivar, no borrar).
- Al guardar la compra, el servidor toma el `name` actual del producto como
  `concept` (snapshot) y `unit` del producto como default de la línea.

## Captura con IA (modal cámara/IA)

Al consumir el draft de IA / capturar, cada línea trae un nombre sugerido:

1. Se **empareja por nombre** (case-insensitive, dentro del tenant) contra
   `purchase_products` activos.
2. Si existe, se usa; si no, se **crea** (`name` sugerido, `unit` sugerida o
   default, `category = null`) y se usa.

Así la IA alimenta el catálogo sin pasos extra.

## Rutas (nuevas, bajo `/{tenant}/empresa`)

```
GET    /{tenant}/empresa/productos-compra              index/admin (admin-empresa)
POST   /{tenant}/empresa/productos-compra              store
PUT    /{tenant}/empresa/productos-compra/{producto}   update
DELETE /{tenant}/empresa/productos-compra/{producto}   destroy (soft, bloqueado si tiene compras)
```

Lectura + creación al vuelo para quien captura compras:

```
GET    /{tenant}/sucursal/productos-compra             búsqueda/listado (admin-sucursal)
POST   /{tenant}/sucursal/productos-compra             crear al vuelo (admin-sucursal)
```

(En Fase 2 del spec del turno, el cajero obtiene las equivalentes bajo
`/caja` para crear al vuelo durante la compra en efectivo.)

## Validaciones

### Producto de compra

- `name`: required, string, max 160, único por tenant (ignora soft-deleted).
- `unit`: required, string, max 10.
- `category`: nullable, enum `PurchaseProductCategory`.
- `status`: en update, required `in:active,inactive`.

### Línea de compra (cambio)

- `items.*.purchase_product_id`: **required**, exists en `purchase_products`
  con `tenant_id` actual y no soft-deleted.
- Se elimina `items.*.product_id` y `items.*.concept` como entrada del cliente:
  el `concept` lo fija el servidor desde el producto (snapshot).
- `items.*.quantity`, `items.*.unit`, `items.*.unit_price`: como hoy (`unit`
  default del producto, editable).

## Permisos

- **admin-empresa**: CRUD completo del catálogo (tenant-wide).
- **admin-sucursal**: lectura/búsqueda + **crear al vuelo** al capturar
  compras; no edita ni inactiva el catálogo.
- **cajero**: sin acceso ahora; obtiene lectura + crear al vuelo en Fase 2 del
  spec del turno (compra en efectivo desde caja).

## UI

- `/empresa/productos-compra`: tabla con búsqueda y filtro por categoría/
  estado; botón "Nuevo producto"; modal de alta/edición (nombre, unidad,
  categoría, estado). Mismo estilo que Proveedores.
- En el form de compra (`Components/Compras/...`): el campo de concepto libre
  se reemplaza por el buscador de catálogo con opción "Crear producto".

## Secuenciación con el spec del turno

- Este catálogo es **prerequisito de la Fase 2** del spec
  `2026-05-20-gastos-compras-turno-corte-design.md` (la compra desde caja ya
  debe usar el catálogo).
- La **Fase 1 del turno (gasto en efectivo)** no depende de esto y puede ir
  primero.
- Orden recomendado: Fase 1 del turno → catálogo de compras → Fase 2 del turno.

## Pruebas (PHPUnit, feature)

- CRUD del catálogo (admin-empresa); aislamiento por tenant.
- Nombre duplicado por tenant: bloqueado; permitido en otro tenant.
- Crear al vuelo desde la captura de compra (admin-sucursal) deja el producto
  en el catálogo y lo usa en la línea.
- Compra **exige** `purchase_product_id`: rechaza línea sin producto.
- `concept` se sella desde el nombre del producto (snapshot) aunque luego se
  renombre.
- Emparejado de IA: línea con nombre existente reusa; nombre nuevo crea.
- Inactivar producto con compras: permitido; borrar (hard) bloqueado.
- Permisos: cajero sin acceso (pre-Fase 2); admin-sucursal no administra.
- Actualizar factory/seeder de `PurchaseItem` para usar `purchase_product_id`.

## Roadmap posterior

- Vincular producto de compra ↔ productos de venta para rendimiento/merma
  (la opción "mixto" que quedó fuera).
- Sugerencia de precio y alertas de variación de costo.
