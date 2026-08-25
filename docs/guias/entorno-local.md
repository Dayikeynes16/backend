# Levantar el entorno local

Del clon al primer login. Está escrita para alguien que nunca ha tocado el proyecto: si algún paso no funciona, la sección [Cuando algo falla](#cuando-algo-falla) cubre los tropiezos conocidos.

## Antes de empezar

| Necesitas | Para qué | Nota |
|-----------|----------|------|
| Docker | Levantar PostgreSQL, Redis y Mailpit | En este proyecto se usa **OrbStack**, no Docker Desktop |
| PHP 8.2+ y Composer | Instalar dependencias la primera vez | Solo en el anfitrión; después todo corre dentro del contenedor |
| Node 20+ | Instalar dependencias de frontend | Igual: solo la primera vez |

Todo lo demás vive en contenedores. **Una vez arrancado, cada comando va prefijado con `./vendor/bin/sail`** — el PHP del anfitrión no es el que usa la aplicación.

## Los pasos

### 1. Dependencias

```bash
cd carniceria-saas
composer install
```

### 2. Configuración

```bash
cp .env.example .env
php artisan key:generate
```

El `.env.example` viene listo para Sail: PostgreSQL en el host `pgsql`, Redis en `redis`, correo a Mailpit. No hace falta tocar nada para arrancar.

### 3. Contenedores

```bash
./vendor/bin/sail up -d
```

La primera vez descarga las imágenes y tarda varios minutos. Levanta:

| Servicio | Puerto | Qué es |
|----------|--------|--------|
| `laravel.test` | 80 | La aplicación |
| `pgsql` | 5432 | PostgreSQL 18 — crea sola la base `testing` que usan las pruebas |
| `redis` | 6379 | Caché y sesiones |
| `mailpit` | 8025 | Bandeja de correo de desarrollo |
| Vite | 5173 | Servidor de assets |
| Reverb | 8080 | WebSockets |

### 4. Base de datos con datos de demostración

```bash
./vendor/bin/sail artisan migrate:fresh --seed
```

Crea el esquema completo y una empresa de prueba con sucursal, usuarios de los cuatro roles, productos, clientes y proveedores. Detalle de lo que siembra cada seeder: [seeders-demo.md](../seeders-demo.md).

### 5. Arrancar

```bash
./vendor/bin/sail composer run dev
```

Levanta en paralelo el servidor, el worker de colas, los logs en vivo (Pail) y Vite. La aplicación queda en **http://localhost**.

### 6. El tiempo real — no te saltes este paso

En **otra terminal**:

```bash
./vendor/bin/sail artisan reverb:start
```

> **Sin Reverb corriendo, el cajero no recibe las ventas de la báscula y no aparece ningún error que lo explique.** La cola simplemente se queda vacía. Es el tropiezo número uno de quien llega nuevo al proyecto.

Reverb necesita las credenciales del bloque `REVERB_*` del `.env`. En desarrollo valen cualesquiera; si tu `.env` no las trae, cópialas de `.env.example` y pon lo que quieras:

```env
REVERB_APP_ID=123456
REVERB_APP_KEY=local
REVERB_APP_SECRET=local
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

Las cuatro `VITE_REVERB_*` deben espejear a esas: el frontend las lee al compilar. Si cambias alguna, **reinicia Vite** o los valores viejos siguen dentro del bundle.

## Entrar

Todas las cuentas de demostración usan la contraseña `password`:

| Correo | Rol | Aterriza en |
|--------|-----|-------------|
| `superadmin@carniceria.test` | superadmin | `/admin` — gestión de empresas |
| `admin@eltoro.test` | admin-empresa | `/el-toro/empresa` — configuración, sucursales, métricas |
| `sucursal@eltoro.test` | admin-sucursal | `/el-toro/sucursal` — productos, mesa de trabajo, clientes |
| `cajero@eltoro.test` | cajero | `/el-toro/caja` — cola de ventas y cobro |

`/dashboard` redirige solo, según el rol de quien entra.

## Ver el sistema funcionando de punta a punta

El flujo que da sentido al proyecto es una venta que nace en la báscula y llega al cajero. Se puede simular sin hardware:

1. Entra como **cajero** y abre turno.
2. Deja la cola de ventas abierta.
3. Desde otra terminal, crea una venta con la API de básculas:

```bash
curl -X POST http://localhost/api/v1/sales \
  -H "X-Api-Key: csa_demo_test_key_for_development_only_1234" \
  -H "Content-Type: application/json" \
  -d '{
    "items": [{"product_id": 1, "quantity": 1.5}],
    "payment_method": "cash",
    "origin_name": "Bascula de prueba"
  }'
```

La venta debe aparecer **sola** en la pantalla del cajero, sin recargar. Si no aparece, Reverb no está corriendo (paso 6).

## El día a día

```bash
./vendor/bin/sail composer run dev              # servidor + colas + logs + Vite
./vendor/bin/sail artisan reverb:start          # WebSockets, terminal aparte
./vendor/bin/sail composer run test             # toda la suite
./vendor/bin/sail artisan test --compact --filter=NombreDelTest
./vendor/bin/sail bin pint                      # formato, antes de cada commit
./vendor/bin/sail artisan migrate:fresh --seed  # base limpia
./vendor/bin/sail artisan config:clear          # tras cambiar el .env
./vendor/bin/sail down                          # apagar
```

## Cuando algo falla

**La cola del cajero no recibe ventas.**
Reverb no está corriendo, o faltan las `REVERB_*` en el `.env`. Arranca `reverb:start` y comprueba el bloque. Si cambiaste las `VITE_REVERB_*`, reinicia Vite.

**Cambié algo en el `.env` y no surte efecto.**
`./vendor/bin/sail artisan config:clear`. Laravel cachea la configuración.

**`Unable to locate file in Vite manifest`.**
Vite no está corriendo o no se compilaron los assets: `./vendor/bin/sail npm run dev`, o `npm run build` si quieres el bundle de producción.

**`could not find driver` o la base no conecta.**
Estás ejecutando el comando fuera del contenedor. Prefija con `./vendor/bin/sail`.

**El puerto 80 está ocupado.**
Otro servicio lo tiene tomado. Cambia `APP_PORT` en el `.env` y vuelve a `sail up -d`.

**Las pruebas fallan por la base de datos.**
Usan la base `testing`, que el contenedor de PostgreSQL crea al levantarse por primera vez. Si el contenedor es anterior a ese cambio: `./vendor/bin/sail down -v` y `up -d` de nuevo (esto **borra** los datos locales).

**El asistente IA devuelve 502.**
Falta `OPENAI_API_KEY` en el `.env`. El resto de la aplicación funciona con normalidad sin ella.

**El menú QR no aparece por ningún lado.**
Está detrás de `FEATURE_WEB_ORDERS`, apagado por defecto. Ponlo en `true` y limpia la configuración.

## Siguiente paso

- **[El ecosistema](../arquitectura/ecosistema.md)** — las otras tres aplicaciones y cómo se conectan con esta.
- **[Índice de documentación](../README.md)** — todo lo demás.
- **[CONTRIBUTING.md](../../CONTRIBUTING.md)** — cómo se trabaja aquí, incluida la regla de documentación.
