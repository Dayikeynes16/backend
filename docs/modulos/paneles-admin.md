# Paneles de Administración

Dashboards con métricas y reportes para cada nivel de administración.

## Layout del Superadmin (`AdminLayout.vue`)

El superadmin usa un layout independiente con sidebar lateral izquierdo, separado del `AuthenticatedLayout` que usan los otros roles.

**Estructura:**
- Sidebar fijo (256px) en `gray-950` con logo, nav links y sección de usuario
- Contenido principal con fondo `gray-50`
- Top bar mobile con hamburger que abre sidebar como overlay
- Badge "Superadmin" en la barra superior

**Paleta de colores:**
- Primario: `red-600` — botones, links activos, badges
- Secundario: `orange-600` — acentos, gradientes del logo
- Sidebar: `gray-950` con texto blanco
- Active link: borde izquierdo `red-500` + fondo `red-500/10`
- Cards: blanco con `shadow-sm` y `ring-1 ring-gray-100`

**Navegación sidebar:**
- Dashboard (`/admin`)
- Empresas (`/admin/empresas`)

## Dashboard Superadmin (`/admin`)

**Controller:** `Admin\DashboardController`

**5 stat cards:** empresas, sucursales, usuarios, ventas hoy, ingresos hoy — cada uno con icono en fondo coloreado.

**Tabla de empresas:** nombre (+ slug), estado (badge), sucursales, usuarios, ventas del día, link a editar.

**Datos:** consulta todas las tablas sin TenantScope (`withoutGlobalScopes()`).

## CRUD Empresas (Superadmin)

**Controller:** `Admin\EmpresaController`

### Index (`/admin/empresas`)

- 3 stat cards: total, activas, inactivas
- Búsqueda con debounce (300ms) e icono de lupa
- Tabla con columnas: nombre (+slug), RFC, sucursales (X/max con barra de progreso), usuarios, estado, acciones
- Barra de progreso de sucursales: verde (<60%), naranja (60-89%), rojo (>=90%)
- Paginación con estilo red-600

### Create (`/admin/empresas/create`)

Formulario en 2 secciones (cards):
1. **Información General:** nombre, slug (auto-generado desde nombre con prefijo `app.com/`), RFC, dirección, teléfono
2. **Configuración SaaS:** máximo de sucursales (number input, min 1, max 100)

Breadcrumb: Empresas > Nueva Empresa

### Edit (`/admin/empresas/{empresa}/edit`)

Formulario en 4 secciones:
1. **Información General:** nombre, slug, RFC, dirección, teléfono
2. **Configuración SaaS:** máximo de sucursales, estado (activa/inactiva)
3. **Resumen (solo lectura):** sucursales actuales/máximo (con barra de progreso), usuarios, fecha de creación
4. **Zona de Peligro:** eliminar empresa con botón outlined y confirmación

Breadcrumb: Empresas > {nombre}

### Validaciones

| Campo | Store | Update |
|-------|-------|--------|
| `name` | required, string, max 255 | required, string, max 255 |
| `slug` | required, unique, alpha_dash | required, unique (ignore self), alpha_dash |
| `rfc` | nullable, string, max 13 | nullable, string, max 13 |
| `address` | nullable, string, max 500 | nullable, string, max 500 |
| `phone` | nullable, string, max 20 | nullable, string, max 20 |
| `max_branches` | required, integer, min 1, max 100 | required, integer, min 1, max 100 |
| `status` | — | required, in:active,inactive |

## Campo `max_branches`

Agregado al modelo `Tenant` como `unsignedInteger` con default 1. Controla el número máximo de sucursales que una empresa puede crear.

**Migración:** `2026_03_27_200000_add_max_branches_to_tenants_table.php`

## Dashboards de Empresa y Sucursal (compartidos)

Ambos roles usan el componente `Components/Dashboard/DashboardOverview.vue` que centraliza el diseño iOS (tokens `cn-*`, sparklines, donut, gráfica de horas). El componente es presentational puro: recibe los datos como props.

**Backend:** `Sucursal\DashboardController` y `Empresa\DashboardController` devuelven la misma estructura (`totals`, `hoursData`, `paymentMethods`, `topProducts`, `recentShifts`, `expenses`, `pendingCount`, etc.). El de Empresa además recibe `selectedBranch` y `branches`.

**Diferencias:**

| Aspecto | Sucursal | Empresa |
|---|---|---|
| Scope datos | siempre `branch_id = $user->branch_id` | `tenant_id` por default; `branch_id` cuando se selecciona una sucursal |
| Header | DatePicker | DatePicker + selector de sucursal ("Todas las sucursales" o una específica) |
| Quick actions | Productos, Gastos, Cajeros, Cortes | (no se renderizan) |
| Cancel requests CTA | Link a `sucursal.cancelaciones.index` | Solo banner informativo |

### Bloque de Gastos en el Dashboard

Ambos contextos incluyen `expenses`:

```
{
  total, total_yesterday, count, delta_pct,
  hourly: [{h, amount}],            // 13 puntos 7-19h
  top_categories: [{category, subcategory, total, count}],  // top 5
  recent: [{id, concept, amount, expense_at, category, subcategory, branch, user}],
}
```

Visualización:
- **KPI "Gastos hoy"** con sparkline ámbar y delta vs ayer (verde si bajaron, rojo si subieron — opuesto a ventas).
- **KPI "Utilidad neta"** = `ventas - gastos`. Verde si positiva, vino si negativa. Delta vs ayer.
- **Card "Gastos del día"** con top 5 subcategorías y barra ámbar.
- **Card "Gastos recientes"** con últimos 5 del día (en empresa muestra la sucursal).
- En vista empresa, los Cortes recientes muestran nombre de sucursal junto al cajero.

## Navegación por rol

## Navegación por rol

| Rol | Layout | Links |
|-----|--------|-------|
| superadmin | `AdminLayout` (sidebar) | Dashboard, Empresas |
| admin-empresa | `EmpresaLayout` (sidebar rojo) | Dashboard, Sucursales, Usuarios, Tickets, Gastos, Métricas, Configuración |
| admin-sucursal | `SucursalLayout` (sidebar rojo) | Dashboard, Productos, Mesa de Trabajo, Pagos, Historial, Mi Turno, Clientes, Cancelaciones, Gastos, Cortes, Métricas, Menú online, Configuración |
| cajero | `CajeroLayout` | Mesa de Trabajo, Mi Turno, Pagos, Historial |
