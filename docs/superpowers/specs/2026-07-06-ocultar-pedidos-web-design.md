# Ocultar el módulo Pedidos Web / Menú Online tras un feature flag global

**Fecha:** 2026-07-06
**Estado:** Aprobado — pendiente de plan de implementación
**Alcance:** apagado total y reversible (UI interna + acceso público). No se elimina código ni datos.

## Problema

El módulo de pedidos web (menú público por QR, configuración por sucursal, vinculación pedido↔venta) está implementado y funcional, pero **no se usa** y sus superficies estorban visualmente en el panel: item "Menú online" en el sidebar de Sucursal, item "Personalización" en Empresa, la sección "Pedidos en línea" + horarios + teléfono público en Editar Sucursal, badges y chips en el listado de sucursales, el checkbox/badge/toggle `visible_online` en Productos, y los botones/modales de vincular pedido en la mesa de trabajo. Además la URL pública `/menu/{tenant}` y 4 endpoints API sin login siguen accesibles.

## Decisión

Un **feature flag global de aplicación** (no por tenant ni por sucursal):

- `config/features.php` (archivo nuevo): `'web_orders' => env('FEATURE_WEB_ORDERS', false)` — **apagado por defecto**.
- `FEATURE_WEB_ORDERS` documentado en `.env.example` y agregado al `.env` local.
- `HandleInertiaRequests::share()` expone una prop global nueva `features: ['webOrders' => (bool) config('features.web_orders')]` — mismo patrón que los toggles `cashier_*` ya compartidos en `auth.branch` y consumidos por `CajeroLayout`.

Reversión: cambiar `FEATURE_WEB_ORDERS=true` (+ `config:clear`). Sin migraciones, sin cambios de datos, sin código borrado.

### Alternativas descartadas

| Alternativa | Por qué no |
|---|---|
| Quitar/comentar los elementos directamente | Re-activar implica revertir commits dispersos; el código diverge mientras tanto. |
| Flag por tenant administrado por superadmin | Requiere migración + UI de administración; sobredimensionado para "ocultar por ahora". Se puede evolucionar desde el flag global si algún día se necesita. |

## Principio rector

**Se ocultan puertas de entrada y acciones; se conserva el renderizado pasivo de datos históricos.** Las ventas con `origin='web'` ya existentes siguen mostrando sus badges informativos ("🛒 Pedido web", "✓ Cumplido", chip "Vinculada al pedido web") en Workbench e Historial: son solo lectura y removerlas rompería la auditoría. Desaparece todo lo que permite crear, configurar o vincular pedidos.

## Cambios backend (rutas envueltas en `if (config('features.web_orders'))`)

Con el flag apagado las rutas **no se registran** → 404 natural y Ziggy no las expone a JS.

| Grupo | Rutas | Ubicación actual |
|---|---|---|
| SPA pública | `GET /menu/{tenantSlug}/{any?}` (`public.menu`) | `routes/web.php:97-101` |
| API pública | grupo `public/{tenantSlug}` completo: tenant show, menu, delivery quote, orders store | `routes/api.php:143-155` |
| Menú QR | ruta de `Sucursal/MenuQrController@show` (`sucursal.menu-online`) | `routes/web.php` (grupo sucursal) |
| Personalización | GET/POST/reset de `Empresa/PersonalizacionController` | `routes/web.php` (grupo empresa) |
| Vinculación Sucursal | `workbench.pending-web-orders`, `workbench.linkable-sales`, `workbench.link-order`, `workbench.unlink-order` | `routes/web.php:327-335` |
| Vinculación Caja | `caja.pending-web-orders`, `caja.linkable-sales`, `caja.link-order`, `caja.unlink-order` | `routes/web.php:507-516` |

**Explícitamente NO se toca:**

- `workbench.update-status` / `caja.update-status` — transición genérica de estados de venta, no exclusiva de web.
- `whatsapp-link` (Sucursal y Caja) — también se usa para enviar ventas normales al cliente.
- Enum `SaleStatus` (incluido `Fulfilled`), scope `Sale::accountable()`, `OrderLinkService`, `DeliveryFeeService`, `BrandingService`, modelos, migraciones, columnas (`online_ordering_enabled`, `visible_online`, `delivery_*`, etc.), seeders y el bundle Vite `resources/js/public/main.js` (se sigue compilando; sin la ruta no es alcanzable).
- La validación de `SucursalController` (`validateOnlineOrderingConfig`, normalización de teléfono/tiers/horarios) queda intacta: el form seguirá enviando los valores actuales sin modificarlos.

## Cambios frontend (`v-if` sobre `$page.props.features.webOrders`)

| Archivo | Qué se oculta |
|---|---|
| `Layouts/SucursalLayout.vue:34` | Item "Menú online" del sidebar (filtrar en `navLinks`) |
| `Layouts/EmpresaLayout.vue:23` | Item "Personalización" del sidebar |
| `Pages/Empresa/Sucursales/Edit.vue` | Sección "Pedidos en línea" completa (186-262), "Horarios de atención" (173-184), campo `public_phone` en PhoneFields (137-143). Los campos permanecen en el `useForm` con los valores que llegan del servidor |
| `Pages/Empresa/Sucursales/Index.vue` | Badge "Menú online activo/apagado" (135-139), chips 🚚/🏪 (142-145), resumen de horarios (152) y teléfono público (162-164) |
| `Pages/Empresa/Sucursales/Show.vue` | Vista previa de config online (hours/tiers/onlineEnabled/deliveryEnabled) |
| `Pages/Sucursal/Productos/Create.vue:304` y `Edit.vue:299` | Checkbox "Visible en menú online" |
| `Pages/Sucursal/Productos/Index.vue:290-293` | Badge "Online" |
| `Components/Productos/ProductDetailModal.vue:302-311` | Toggle rápido de `visible_online` |
| `Components/Sucursal/SaleDetail.vue` | Botones "Vincular pedido web" (261-265) / "Desvincular" (266-270), botón "Vincular con venta" del banner (394), render de `LinkOrderModal` (664-669) y `LinkSaleToOrderModal` (671-674), diálogo de desvincular. El banner informativo del pedido web (370-393) queda visible como dato |
| `Components/Caja/SaleDetail.vue` | Botones Vincular (204-207) / Desvincular (211-212), render de `LinkOrderModal` (461-466), diálogo de desvincular |

Badges pasivos que **NO se ocultan**: `originBadge`, "🛒 Pedido web", "Vinculada al pedido web", "✓ Cumplido · {folio}" en `Workbench.vue` (Sucursal/Caja) e `Historial/Index.vue`, y el estado `fulfilled: Cumplida` del mapa de estados.

### Decisión: Personalización se oculta completa

La página es casi en su totalidad del menú público (colores, `MenuPreview`, imagen default de producto), pero **también es la vía de subida del logo del tenant** que aparece en los sidebars del panel. Se acepta el trade-off: el logo ya almacenado sigue mostrándose (`auth.tenant.logo_url` no depende del flag); para cambiarlo se enciende la bandera temporalmente. Si esto resulta molesto, una iteración futura puede mover la subida de logo a Configuración de empresa.

## Tests

1. `phpunit.xml`: `<env name="FEATURE_WEB_ORDERS" value="true"/>` → las suites existentes del módulo (PendingWebOrdersTest, LinkableSalesTest, LinkOrderTest, UnlinkOrderTest, EndToEndLinkFlowTest, HistorialFulfilledTest, MenuQrTest, PersonalizacionControllerTest, SucursalControllerTest, SaleResourceTest, OrderLinkServiceTest, etc.) corren sin cambios.
2. Test nuevo `tests/Feature/WebOrdersFeatureFlagTest.php`. Nota: `config([...])` en runtime no des-registra rutas ya cargadas, así que el test apaga el flag **antes** de crear la app (`putenv('FEATURE_WEB_ORDERS=false')` + `$this->refreshApplication()`, o un `#[DefineEnvironment]`/app booteada ad hoc). Asserts:
   - `GET /menu/{slug}` → 404 y `GET /api/public/{slug}` → 404.
   - Las rutas de vinculación y `sucursal.menu-online` / `empresa.personalizacion` no existen (404).
   - La prop compartida `features.webOrders` llega `false` (y `true` con el flag encendido).
   - Las rutas no relacionadas (`workbench.update-status`, `whatsapp-link`) siguen existiendo.
3. Smoke manual: sidebar Sucursal/Empresa, Editar Sucursal, Productos, mesa de trabajo con una venta normal y con una venta web histórica.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| `route('...')` huérfano en JS lanza error de Ziggy al no existir la ruta | Todos los llamadores quedan detrás de `v-if`; smoke manual de las pantallas afectadas |
| Submit de Editar Sucursal falla validación con campos ocultos | Los campos siguen en `useForm` con valores del servidor; `validateOnlineOrderingConfig` recibe lo mismo que había |
| Datos históricos web "huérfanos" de acciones (no se pueden desvincular con flag off) | Aceptado: es el comportamiento deseado de "congelar" el módulo |
| Config cacheada en prod ignora el `.env` | Documentar `php artisan config:clear` en la reversión |

## Documentación (parte del definition of done)

- `docs/modulos/pedidos-web.md`: header nuevo → "Implementado · **oculto tras `FEATURE_WEB_ORDERS=false`** desde 2026-07-06" + sección breve "Cómo re-activar".
- `docs/README.md`: fila de Pedidos web en "Estado del sistema" → "✅ Completo · oculto tras flag".
- Este spec: flip del header a "Implementado" al terminar.
