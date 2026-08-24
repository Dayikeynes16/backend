# Sucursales (Branches)

Unidad operativa dentro de una empresa. Cada sucursal tiene su propio catálogo de productos, cajeros y API Key.

## Responsabilidades

- Agrupar productos, ventas y usuarios por punto de venta físico.
- Definir horarios y datos de contacto de la sucursal.

## Modelo Eloquent (`app/Models/Branch.php`)

| Campo | Tipo | Notas |
|-------|------|-------|
| `id` | bigint PK | |
| `tenant_id` | FK → tenants | Cascade on delete |
| `name` | string | |
| `address` | string nullable | |
| `phone` | string nullable | |
| `schedule` | string nullable | Ej: "Lun-Sáb 7am-8pm" |
| `status` | string | `active` (default) o `inactive` |
| `timestamps` | | |

**Relaciones:** `users`, `products`, `sales`, `apiKey` (HasOne activa), `cashRegisterShifts`.

**Usa `BelongsToTenant`** — filtrado automático por tenant.

## Feature flags por sucursal

Además de los campos de la tabla anterior, `branches` guarda banderas booleanas
con las que el admin-empresa habilita capacidades **sucursal por sucursal**
desde Empresa → Sucursales → Editar. Se aplican con el middleware
`branch.feature:{flag}` (`EnsureBranchFeature`) y se exponen al frontend en
`auth.branch` (`HandleInertiaRequests`).

| Flag | Default | Qué habilita |
|---|:--:|---|
| `cashier_expenses_enabled` | `true` | Módulo de Gastos del cajero |
| `cashier_purchases_enabled` | `true` | Módulo de Compras del cajero |
| `cashier_customers_enabled` | `true` | Módulo de Clientes del cajero: cartera, alta/edición y cobro global FIFO — **sin** precios preferenciales. Ver [clientes-caja.md](clientes-caja.md) |
| `cashier_scale_sales_enabled` | `false` | Pantalla **Mostrador** del hub de escritorio: el cajero pesa y arma ventas con la báscula USB conectada al hub. No cobra ahí — la venta llega a la Mesa de Trabajo como las de las básculas Android |
| `branch_admin_providers_enabled` | `false` | Que el admin-sucursal cree/edite proveedores (catálogo tenant-wide) |
| `branch_admin_expense_categories_enabled` | `false` | Que el admin-sucursal cree/edite categorías de gasto |
| `branch_admin_purchase_products_enabled` | `false` | Que el admin-sucursal gestione el catálogo de insumos de compra (tenant-wide). Ver [compras.md](compras.md) |
| `branch_admin_movements_enabled` | `false` | Que el admin-sucursal vea la bitácora de movimientos sobre ventas ya cobradas |
| `payment_receipts_enabled` | `false` | Adjuntar comprobantes de transferencia. Ver [comprobantes-pago.md](comprobantes-pago.md) |
| `payment_receipts_required` | `false` | Exigir el comprobante para cobrar por transferencia |
| `online_ordering_enabled`, `delivery_enabled`, `pickup_enabled` | — | Menú QR y modalidades de pedido web |

Los toggles del cajero nacen en `true` para no quitarle capacidades a
sucursales existentes al desplegar; los de admin-sucursal nacen en `false`
porque tocan catálogos compartidos por todo el tenant.

`cashier_scale_sales_enabled` es la excepción entre los del cajero: nace en
`false` porque no quita nada — es una capacidad nueva que nadie tenía. Que
cada empresa la encienda en las sucursales donde el hub tenga báscula.

## Controller (`app/Http/Controllers/Empresa/SucursalController.php`)

Accesible por admin-empresa. Rutas bajo `/{tenant}/empresa/sucursales`.

| Método | Ruta | Descripción |
|--------|------|-------------|
| `index` | `GET /{tenant}/empresa/sucursales` | Lista con búsqueda y conteo de usuarios |
| `create` | `GET /{tenant}/empresa/sucursales/create` | Formulario |
| `store` | `POST /{tenant}/empresa/sucursales` | Crea sucursal con tenant_id del contexto |
| `edit` | `GET /{tenant}/empresa/sucursales/{sucursal}/edit` | |
| `update` | `PUT /{tenant}/empresa/sucursales/{sucursal}` | |
| `destroy` | `DELETE /{tenant}/empresa/sucursales/{sucursal}` | Cascade elimina productos, ventas, etc. |

## Validaciones

- `name`: required, string, max 255
- `address`: nullable, string, max 500
- `phone`: nullable, string, max 20
- `schedule`: nullable, string, max 255
- `status` (solo update): required, in:active,inactive
