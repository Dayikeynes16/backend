# Carnicería SaaS

Sistema de gestión multi-tenant para carnicerías. Es la aplicación web y el backend del ecosistema: las ventas nacen en una báscula del mostrador, llegan aquí por API, y el cajero las cobra en tiempo real.

> **¿Primera vez en el proyecto?** Lee **[docs/arquitectura/ecosistema.md](docs/arquitectura/ecosistema.md)** — explica las cuatro aplicaciones que componen el sistema y cómo se comunican. Este README solo cubre este repositorio.

## Qué hace

- **Ventas / POS** — la venta nace en la báscula, llega por WebSocket a la cola del cajero, con máquina de estados y bloqueo de edición concurrente.
- **Corte de caja** — turnos con apertura, cierre y conciliación por método de pago.
- **Productos** — venta por peso, pieza o presentación, con categorías.
- **Clientes** — crédito ("fiado"), precios preferenciales y cobro global con distribución FIFO.
- **Gastos** — categorías y subcategorías, con captura asistida por IA (foto + voz + texto).
- **Compras y proveedores** — cuentas por pagar y pagos FIFO, separado de Gastos (CMV vs OPEX).
- **Pedidos web** — menú público por QR; el pedido no es una venta contable, se empareja con la venta real de la báscula. Tras `FEATURE_WEB_ORDERS`, apagado por defecto.
- **Métricas** — nueve ejes más un resumen de utilidad.
- **Agenda** — pendientes y recordatorios compartidos por los cuatro roles.
- **Asistente IA** — chat con texto y voz; toda escritura pasa por borrador y confirmación humana.

## Stack

Laravel 13 · PHP 8.5 · PostgreSQL 18 · Redis · Laravel Reverb (WebSockets) · Sanctum · Spatie Permission
Vue 3 (Composition API) · Inertia.js 2 · Tailwind · Vite
OpenAI (cliente HTTP propio, sin SDK) para el asistente y la captura con IA

## Requisitos

- Docker con [OrbStack](https://orbstack.dev/) o Docker Desktop — el proyecto corre en contenedores vía Laravel Sail.
- PHP 8.2+ y Composer en el anfitrión, solo para instalar dependencias la primera vez.

## Arrancar en local

```bash
# 1. Dependencias e imagen de Sail
composer install

# 2. Configuración
cp .env.example .env
php artisan key:generate

# 3. Levantar los contenedores (PostgreSQL 18, Redis, Mailpit)
./vendor/bin/sail up -d

# 4. Base de datos con datos de demostración
./vendor/bin/sail artisan migrate:fresh --seed

# 5. Entorno de desarrollo (servidor, colas, logs y Vite en paralelo)
./vendor/bin/sail composer run dev
```

La aplicación queda en **http://localhost**.

### El tiempo real necesita un paso más

Sin Reverb corriendo, **el cajero no recibe las ventas de la báscula** y no aparece ningún error que lo explique. En otra terminal:

```bash
./vendor/bin/sail artisan reverb:start
```

Las credenciales van en el bloque `REVERB_*` del `.env` (en desarrollo sirve cualquier valor). Si el `.env` no las trae, cópialas de `.env.example`.

> Guía paso a paso, con solución a los tropiezos habituales: **[docs/guias/entorno-local.md](docs/guias/entorno-local.md)**.

### Credenciales de demostración

Tras `migrate:fresh --seed`, todas las cuentas usan la contraseña `password`:

| Correo | Rol | Entra en |
|--------|-----|----------|
| `superadmin@carniceria.test` | superadmin | `/admin` |
| `admin@eltoro.test` | admin-empresa | `/el-toro/empresa` |
| `sucursal@eltoro.test` | admin-sucursal | `/el-toro/sucursal` |
| `cajero@eltoro.test` | cajero | `/el-toro/caja` |

API Key de báscula para pruebas: `csa_demo_test_key_for_development_only_1234`

## Comandos del día a día

```bash
./vendor/bin/sail composer run dev        # servidor + colas + logs + Vite
./vendor/bin/sail artisan reverb:start    # WebSockets (terminal aparte)
./vendor/bin/sail composer run test       # toda la suite
./vendor/bin/sail artisan test --compact --filter=NombreDelTest
./vendor/bin/sail bin pint                # formato PSR-12
./vendor/bin/sail artisan migrate:fresh --seed
```

Todos los comandos van prefijados con `sail`: PHP, Artisan, Composer y Node viven dentro del contenedor.

## Pruebas

193 archivos de prueba (PHPUnit) sobre una base `testing` que el contenedor de PostgreSQL crea sola.

```bash
./vendor/bin/sail composer run test
```

Se ejecutan también en GitHub Actions en cada push y cada pull request (`.github/workflows/tests.yml`).

## Estructura

```
app/
  Http/Controllers/     Admin · Empresa · Sucursal · Caja · Api · Api/Hub · Public · Asistente
  Services/             lógica de dominio (SalePaymentService, ShiftService, Metrics/…)
  Models/               39 modelos; la mayoría con BelongsToTenant
  Events/               5 eventos de broadcast
resources/js/
  Pages/                páginas Inertia, una carpeta por rol
  Components/           componentes compartidos
  composables/          useSaleQueue, useSaleLock, …
routes/
  web.php               rutas por rol, bajo /{tenant}
  api.php               Scale API · API del hub · API pública
docs/                   documentación viva (ver abajo)
```

## Documentación

Toda en `docs/`, indexada en **[docs/README.md](docs/README.md)**.

| Empieza por | Para |
|-------------|------|
| [ecosistema.md](docs/arquitectura/ecosistema.md) | entender las cuatro aplicaciones y cómo se comunican |
| [guias/entorno-local.md](docs/guias/entorno-local.md) | levantar el proyecto del clon al primer login |
| [multitenant.md](docs/arquitectura/multitenant.md) · [roles-permisos.md](docs/arquitectura/roles-permisos.md) | cómo se aíslan las empresas y qué puede hacer cada rol |
| [api/endpoints.md](docs/api/endpoints.md) | el contrato con las básculas ⚠️ **no admite cambios incompatibles** |
| [api/hub.md](docs/api/hub.md) | el contrato con la app de escritorio |
| [docs/modulos/](docs/modulos/) | cómo funciona cada módulo de negocio hoy |

## Contribuir

La regla que define este proyecto: **la documentación se actualiza en el mismo cambio que el código.** El detalle está en [CONTRIBUTING.md](CONTRIBUTING.md) y en `CLAUDE.md`.
