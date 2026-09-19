# Avisos de batería — Plan de implementación (entregas 1 y 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que cada sucursal decida a qué porcentaje de batería se avisa, que exista un estado crítico además del de aviso, y que una franja fija —imposible de cerrar— aparezca en la pantalla del cajero y del administrador mientras un equipo siga descargándose.

**Architecture:** Los umbrales viven en dos columnas de `branches` y se leen a través de un value object `BatteryThresholds`, que es el único que sabe comparar un nivel contra un umbral. Hoy el `20` está escrito a mano en tres sitios (el servicio de avisos, el accessor `Device::status` y `DeviceCard.vue`) y los tres pasan a usarlo. La franja se alimenta de un endpoint ligero que consultan cada 60 s la web y, más adelante, el hub; no hay evento de tiempo real nuevo porque una batería cambia en minutos y el canal `sucursal.{id}` ya lo comparten varios consumidores.

**Tech Stack:** Laravel 13 (PHP 8.5, PostgreSQL 18), Vue 3 + Inertia 2, Tailwind, PHPUnit. Entorno local: Sail sobre OrbStack; Pint con el PHP de Herd.

**Spec:** `docs/superpowers/specs/2026-09-19-avisos-de-bateria-design.md` (aprobado 2026-09-19)

**Alcance:** entregas 1 y 2 del spec, todas dentro de `carniceria-saas`. Las entregas 3 y 4 (Surface, Android y los dos hubs) son otro plan, que se escribe cuando esto esté desplegado y se haya visto la franja en una Surface real.

## Global Constraints

- **La Scale API no admite cambios incompatibles.** Solo se añaden campos opcionales a respuestas existentes. `tests/Feature/Api/ScaleLegacyContractTest.php` no se toca: si cambia, algo se rompió.
- **De fábrica 20 y 10.** Con esos valores el comportamiento debe ser idéntico al actual, literalmente. La comparación es `<=` (hoy `level > 20` rearma).
- **Rangos asimétricos:** `battery_warn_threshold` de 10 a 95, `battery_critical_threshold` de 5 a 90, ambos múltiplos de 5, y `critical < warn` siempre. Con ambos de 5 a 95, un aviso puesto en 5 no admitiría ningún crítico válido.
- **`battery_critical` exige estar en línea**, igual que `battery_low`: se evalúa después de `retired`, `silent` y `stale`. Una lectura vieja de un equipo callado no dice nada del presente.
- **Ningún aviso puede tumbar un latido.** Un fallo al notificar se registra y se sigue (regla ya vigente en `DeviceAlertService`).
- **El cajero ve la franja pero no recibe avisos en la campana.** Los destinatarios de las notificaciones no cambian: `admin-sucursal` de la sucursal del equipo y `admin-empresa` del tenant.
- **Documentación en español**, identificadores en inglés. Cada entrega deja su doc vivo al día (tarea 9).
- Comandos: `sail artisan test --filter=X` para pruebas, `./vendor/bin/pint` antes de cada commit, `npm run build` cuando se toque el frontend.
- Rama de trabajo: `feat/avisos-bateria` (ya creada desde `origin/main`).

---

## Estructura de archivos

**Se crean:**

| Archivo | Responsabilidad |
|---|---|
| `database/migrations/2026_09_19_100000_add_battery_thresholds_to_branches.php` | Las dos columnas nuevas, con sus valores de fábrica |
| `database/migrations/2026_09_19_100001_change_battery_alert_level_to_severity.php` | `devices.battery_alert_level` de número a `warn`/`critical` |
| `app/Services/Devices/BatteryThresholds.php` | Value object: los dos umbrales y la única comparación nivel↔umbral |
| `app/Support/BatteryThresholdRules.php` | Las reglas de validación, en un solo sitio para los dos formularios |
| `app/Services/Devices/BranchDeviceAlertsQuery.php` | Los equipos de una sucursal que están en alerta, en forma mínima |
| `app/Http/Controllers/DeviceAlertsController.php` | La puerta web al endpoint de alertas (cajero, admin-sucursal, superadmin) |
| `app/Http/Controllers/Api/Hub/DeviceAlertsController.php` | La misma consulta para el hub |
| `resources/js/Components/Devices/BatteryThresholdFields.vue` | Los dos controles de 5 en 5, compartidos por las dos pantallas de edición |
| `resources/js/composables/useDeviceAlerts.js` | Consulta cada 60 s, en pausa si la pestaña no está visible |
| `resources/js/Components/Devices/DeviceAlertStrip.vue` | La franja: ámbar o roja, sin botón de cerrar |
| `tests/Unit/BatteryThresholdsTest.php` | El value object |
| `tests/Feature/Devices/DeviceAlertsEndpointTest.php` | Quién ve qué en el endpoint |

**Se modifican:**

| Archivo | Qué cambia |
|---|---|
| `app/Models/Device.php` | `statusFor()` con umbrales; el accessor `status` delega |
| `app/Services/Devices/DeviceAlertService.php` | `checkBattery()` lee los umbrales; la marca pasa a `warn`/`critical` |
| `app/Notifications/DeviceBatteryLow.php` | `severity`, con título y cuerpo distintos |
| `app/Services/Devices/BranchDevicesQuery.php` | `with('branch')`, `severity` en `present()`, `battery_critical` en las alertas |
| `resources/js/Components/Devices/DeviceCard.vue` | Chip y barra por severidad, no por el 20 fijo |
| `app/Http/Controllers/Sucursal/ConfiguracionController.php` | `updateBattery()` |
| `app/Http/Controllers/Empresa/SucursalController.php` | Los dos campos en su validación |
| `resources/js/Pages/Sucursal/Configuracion.vue` | La tarjeta «Aviso de batería» |
| `resources/js/Pages/Empresa/Sucursales/Edit.vue` | Los mismos dos campos |
| `app/Http/Controllers/Api/DeviceHeartbeatController.php` | `battery_alert` en la respuesta |
| `resources/js/Layouts/CajeroLayout.vue`, `SucursalLayout.vue` | Montan la franja |
| `routes/web.php`, `routes/api.php` | Las tres rutas nuevas |

---

### Task 1: Los umbrales en la base y el value object

**Files:**
- Create: `database/migrations/2026_09_19_100000_add_battery_thresholds_to_branches.php`
- Create: `database/migrations/2026_09_19_100001_change_battery_alert_level_to_severity.php`
- Create: `app/Services/Devices/BatteryThresholds.php`
- Test: `tests/Unit/BatteryThresholdsTest.php`

**Interfaces:**
- Consumes: nada (primera tarea).
- Produces: `App\Services\Devices\BatteryThresholds` con `__construct(int $warn = 20, int $critical = 10)`, propiedades públicas de solo lectura `$warn` y `$critical`, `public static function fromBranch(?Branch $branch): self` y `public function severityFor(?int $level, bool $charging): ?string` que devuelve `'critical'`, `'warn'` o `null`. Columnas `branches.battery_warn_threshold` y `branches.battery_critical_threshold`; `devices.battery_alert_level` pasa a `varchar(10)` con valores `warn` / `critical` / `null`.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Unit/BatteryThresholdsTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Services\Devices\BatteryThresholds;
use Tests\TestCase;

/**
 * La única comparación entre un nivel de batería y los umbrales de su sucursal.
 * No toca base de datos: es una función pura sobre dos números.
 */
class BatteryThresholdsTest extends TestCase
{
    public function test_defaults_are_twenty_and_ten(): void
    {
        $t = new BatteryThresholds();

        $this->assertSame(20, $t->warn);
        $this->assertSame(10, $t->critical);
    }

    public function test_a_null_branch_falls_back_to_the_defaults(): void
    {
        $t = BatteryThresholds::fromBranch(null);

        $this->assertSame(20, $t->warn);
        $this->assertSame(10, $t->critical);
    }

    public function test_it_reads_the_thresholds_of_the_branch(): void
    {
        $branch = new Branch(['battery_warn_threshold' => 35, 'battery_critical_threshold' => 15]);

        $t = BatteryThresholds::fromBranch($branch);

        $this->assertSame(35, $t->warn);
        $this->assertSame(15, $t->critical);
    }

    public function test_the_comparison_includes_the_threshold_itself(): void
    {
        $t = new BatteryThresholds(20, 10);

        $this->assertSame('warn', $t->severityFor(20, false));
        $this->assertSame('critical', $t->severityFor(10, false));
        $this->assertNull($t->severityFor(21, false));
    }

    public function test_critical_wins_over_warn(): void
    {
        $t = new BatteryThresholds(20, 10);

        $this->assertSame('critical', $t->severityFor(3, false));
    }

    public function test_charging_or_unknown_is_never_an_alert(): void
    {
        $t = new BatteryThresholds(20, 10);

        $this->assertNull($t->severityFor(5, true));
        $this->assertNull($t->severityFor(null, false));
    }
}
```

- [ ] **Step 2: Correrlo y ver que falla**

Run: `sail artisan test --filter=BatteryThresholdsTest`
Expected: FAIL — `Class "App\Services\Devices\BatteryThresholds" not found`.

- [ ] **Step 3: Escribir el value object**

Crear `app/Services/Devices/BatteryThresholds.php`:

```php
<?php

namespace App\Services\Devices;

use App\Models\Branch;

/**
 * Los dos porcentajes a los que una sucursal quiere enterarse, y la única
 * comparación entre un nivel de batería y ellos.
 *
 * Existe porque el mismo criterio lo necesitan tres sitios —a quién se avisa,
 * qué estado se pinta y de qué color va la barra— y tres copias del mismo `if`
 * se separan el día que alguien cambia una.
 *
 * Sin sucursal (o sin valores guardados) caen a 20/10, que es lo que estaba
 * escrito a mano antes de que esto existiera.
 */
final class BatteryThresholds
{
    public function __construct(
        public readonly int $warn = 20,
        public readonly int $critical = 10,
    ) {}

    public static function fromBranch(?Branch $branch): self
    {
        return new self(
            (int) ($branch?->battery_warn_threshold ?? 20),
            (int) ($branch?->battery_critical_threshold ?? 10),
        );
    }

    /**
     * `critical`, `warn` o null si no hay nada que avisar.
     *
     * Cargando no es una alerta aunque el número sea bajo: alguien ya se ocupó.
     * Sin lectura tampoco: un equipo de escritorio enchufado a la pared no debe
     * molestar nunca.
     */
    public function severityFor(?int $level, bool $charging): ?string
    {
        if ($level === null || $charging) {
            return null;
        }

        if ($level <= $this->critical) {
            return 'critical';
        }

        return $level <= $this->warn ? 'warn' : null;
    }
}
```

- [ ] **Step 4: Correr el test y verlo pasar**

Run: `sail artisan test --filter=BatteryThresholdsTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Escribir las dos migraciones**

Crear `database/migrations/2026_09_19_100000_add_battery_thresholds_to_branches.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A qué porcentaje quiere enterarse cada sucursal. 20 y 10 de fábrica: los
 * mismos que estaban escritos en el código, para que nadie note un cambio
 * hasta que decida tocarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedTinyInteger('battery_warn_threshold')->default(20);
            $table->unsignedTinyInteger('battery_critical_threshold')->default(10);
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['battery_warn_threshold', 'battery_critical_threshold']);
        });
    }
};
```

Crear `database/migrations/2026_09_19_100001_change_battery_alert_level_to_severity.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La marca que evita repetir el aviso guardaba el número que lo disparó (20 o
 * 10). Con el umbral configurable ese número deja de ser comparable: si una
 * sucursal lo mueve a media tarde, la marca ya no significa nada. Pasa a decir
 * qué severidad se avisó.
 *
 * Se hace con SQL directo porque PostgreSQL necesita el USING para convertir
 * los valores existentes en el mismo paso que el tipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE devices
            ALTER COLUMN battery_alert_level TYPE varchar(10)
            USING CASE battery_alert_level
                WHEN 10 THEN 'critical'
                WHEN 20 THEN 'warn'
                ELSE NULL
            END
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE devices
            ALTER COLUMN battery_alert_level TYPE smallint
            USING CASE battery_alert_level
                WHEN 'critical' THEN 10
                WHEN 'warn' THEN 20
                ELSE NULL
            END
        SQL);
    }
};
```

- [ ] **Step 6: Correr las migraciones y comprobar que aplican y revierten**

Run: `sail artisan migrate`
Expected: las dos migraciones en verde.

Run: `sail artisan migrate:rollback --step=2 && sail artisan migrate`
Expected: revierten y vuelven a aplicar sin error. (Si el rollback falla, el `down` está mal y hay que arreglarlo ahora, no en producción.)

- [ ] **Step 7: Añadir las columnas al modelo Branch**

En `app/Models/Branch.php`, añadir `'battery_warn_threshold'` y `'battery_critical_threshold'` a la lista de campos asignables, junto al resto de flags de sucursal.

Verificar cómo los declara ese modelo antes de escribir: si usa el atributo `#[Fillable([...])]` (como `Device`), se añaden ahí; si usa `protected $fillable = [...]`, ahí.

- [ ] **Step 8: Correr la suite de equipos entera**

Run: `sail artisan test --filter=Device`
Expected: PASS. Nada debería haberse roto todavía: el value object aún no lo usa nadie.

- [ ] **Step 9: Pint y commit**

```bash
./vendor/bin/pint
git add database/migrations/2026_09_19_100000_add_battery_thresholds_to_branches.php database/migrations/2026_09_19_100001_change_battery_alert_level_to_severity.php app/Services/Devices/BatteryThresholds.php app/Models/Branch.php tests/Unit/BatteryThresholdsTest.php
git commit -m "feat(equipos): cada sucursal decide a qué porcentaje quiere enterarse

Dos columnas en branches con 20 y 10 de fábrica, y un value object que es
el único sitio donde se compara un nivel contra un umbral. La marca que
evita repetir el aviso deja de guardar el número, que con umbral
configurable ya no significa nada, y guarda la severidad.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: El estado del equipo deja de tener el 20 escrito

**Files:**
- Modify: `app/Models/Device.php` (accessor `status`, líneas 86-110)
- Test: `tests/Unit/DeviceStatusTest.php`

**Interfaces:**
- Consumes: `BatteryThresholds` de la tarea 1 (`fromBranch()`, `severityFor()`).
- Produces: `Device::statusFor(BatteryThresholds $t): string`, que devuelve `retired` · `silent` · `stale` · `battery_critical` · `battery_low` · `online`. El accessor `status` sigue existiendo con la misma firma de uso (`$device->status`) y resuelve los umbrales por su cuenta.

- [ ] **Step 1: Escribir los tests que fallan**

Añadir a `tests/Unit/DeviceStatusTest.php` (el helper `device()` ya existe en el archivo):

```php
    public function test_battery_critical_below_the_critical_threshold(): void
    {
        $d = $this->device(['last_seen_at' => Carbon::now(), 'battery_level' => 8]);

        $this->assertSame('battery_critical', $d->statusFor(new BatteryThresholds(20, 10)));
    }

    public function test_critical_wins_over_low(): void
    {
        $d = $this->device(['last_seen_at' => Carbon::now(), 'battery_level' => 10]);

        $this->assertSame('battery_critical', $d->statusFor(new BatteryThresholds(20, 10)));
    }

    public function test_it_uses_the_thresholds_it_is_given(): void
    {
        $d = $this->device(['last_seen_at' => Carbon::now(), 'battery_level' => 30]);

        $this->assertSame('online', $d->statusFor(new BatteryThresholds(20, 10)));
        $this->assertSame('battery_low', $d->statusFor(new BatteryThresholds(35, 15)));
    }

    public function test_a_quiet_device_with_an_old_critical_reading_is_still_silent(): void
    {
        // Lo contrario abriría una franja roja imposible de cerrar por una
        // lectura de hace media hora, que no dice nada del presente.
        $d = $this->device(['last_seen_at' => Carbon::now()->subMinutes(45), 'battery_level' => 5]);

        $this->assertSame('silent', $d->statusFor(new BatteryThresholds(20, 10)));
    }

    public function test_retired_still_wins_over_a_critical_battery(): void
    {
        $d = $this->device([
            'last_seen_at' => Carbon::now(),
            'battery_level' => 2,
            'retired_at' => Carbon::now()->subDay(),
        ]);

        $this->assertSame('retired', $d->statusFor(new BatteryThresholds(20, 10)));
    }
```

Añadir el import `use App\Services\Devices\BatteryThresholds;` en la cabecera del archivo.

- [ ] **Step 2: Correrlos y ver que fallan**

Run: `sail artisan test --filter=DeviceStatusTest`
Expected: FAIL — `Call to undefined method App\Models\Device::statusFor()`.

- [ ] **Step 3: Reescribir el accessor**

En `app/Models/Device.php`, sustituir el método `status()` por estos dos, y añadir el import `use App\Services\Devices\BatteryThresholds;`:

```php
    /**
     * Estado derivado de cuándo reportó y de su batería. `retired` gana a todo.
     * Las dos alertas de batería solo cuentan si está en línea: una lectura
     * vieja de un equipo callado no dice nada del presente.
     */
    public function statusFor(BatteryThresholds $thresholds): string
    {
        if ($this->retired_at !== null) {
            return 'retired';
        }

        $seen = $this->last_seen_at instanceof Carbon ? $this->last_seen_at : Carbon::parse($this->last_seen_at);
        $minutes = $seen->diffInMinutes(Carbon::now());

        if ($minutes >= config('devices.silence_minutes', 30)) {
            return 'silent';
        }
        if ($minutes >= config('devices.online_minutes', 10)) {
            return 'stale';
        }

        return match ($thresholds->severityFor($this->battery_level, (bool) $this->battery_charging)) {
            'critical' => 'battery_critical',
            'warn' => 'battery_low',
            default => 'online',
        };
    }

    /**
     * Atajo para todo lo que ya escribía `$device->status`.
     *
     * Carga la sucursal solo si hay una que cargar: así un `new Device([...])`
     * de una prueba unitaria no dispara una consulta, y en producción nadie se
     * queda con los umbrales de reserva por olvidar el eager load. Las consultas
     * que presentan muchos equipos cargan `branch` explícitamente para no caer
     * en un N+1.
     */
    protected function status(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->exists && $this->branch_id !== null) {
                $this->loadMissing('branch');
            }

            return $this->statusFor(BatteryThresholds::fromBranch($this->branch));
        });
    }
```

- [ ] **Step 4: Correr los tests y verlos pasar**

Run: `sail artisan test --filter=DeviceStatusTest`
Expected: PASS (11 tests: los 6 que había más los 5 nuevos).

- [ ] **Step 5: Correr todo lo que toca equipos**

Run: `sail artisan test --filter=Device`
Expected: PASS. Con los valores de fábrica el comportamiento es idéntico, así que ningún test viejo debería cambiar de resultado.

- [ ] **Step 6: Pint y commit**

```bash
./vendor/bin/pint
git add app/Models/Device.php tests/Unit/DeviceStatusTest.php
git commit -m "feat(equipos): el estado del equipo lee el umbral de su sucursal

Y aparece battery_critical, que gana a battery_low y exige estar en
línea igual que él: una lectura de hace media hora no justifica una
franja roja que nadie puede cerrar.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: El aviso usa el umbral de la sucursal y distingue las dos urgencias

**Files:**
- Modify: `app/Services/Devices/DeviceAlertService.php` (método `checkBattery`)
- Modify: `app/Notifications/DeviceBatteryLow.php`
- Test: `tests/Feature/Devices/DeviceAlertsTest.php`

**Interfaces:**
- Consumes: `BatteryThresholds` (tarea 1).
- Produces: `DeviceBatteryLow::__construct(Device $device, string $severity)` — el segundo argumento es obligatorio y vale `'warn'` o `'critical'`. El payload gana la clave `severity`. `devices.battery_alert_level` guarda esas mismas dos palabras.

- [ ] **Step 1: Escribir los tests que fallan**

Añadir a `tests/Feature/Devices/DeviceAlertsTest.php`:

```php
    public function test_it_alerts_at_the_branch_threshold_not_at_twenty(): void
    {
        $this->branch->update(['battery_warn_threshold' => 35, 'battery_critical_threshold' => 15]);

        $device = $this->heartbeat(['battery' => ['level' => 30, 'charging' => false]]);

        $this->assertSame('warn', $device->fresh()->battery_alert_level);
        Notification::assertSentTo($this->branchAdmin, DeviceBatteryLow::class);
    }

    public function test_the_two_severities_are_two_alerts(): void
    {
        $this->branch->update(['battery_warn_threshold' => 30, 'battery_critical_threshold' => 15]);

        $this->heartbeat(['battery' => ['level' => 25, 'charging' => false]]);
        $this->heartbeat(['battery' => ['level' => 12, 'charging' => false]]);

        Notification::assertSentToTimes($this->branchAdmin, DeviceBatteryLow::class, 2);
    }

    public function test_raising_the_threshold_over_the_current_level_does_not_alert_again(): void
    {
        // Un equipo ya avisado al que le suben el umbral sigue estando avisado:
        // la marca no se toca y la campana no vuelve a sonar.
        $this->heartbeat(['battery' => ['level' => 18, 'charging' => false]]);
        Notification::assertSentToTimes($this->branchAdmin, DeviceBatteryLow::class, 1);

        $this->branch->update(['battery_warn_threshold' => 40]);
        $this->heartbeat(['battery' => ['level' => 18, 'charging' => false]]);

        Notification::assertSentToTimes($this->branchAdmin, DeviceBatteryLow::class, 1);
    }

    public function test_lowering_the_threshold_below_the_level_rearms_without_alerting(): void
    {
        $this->heartbeat(['battery' => ['level' => 18, 'charging' => false]]);
        $this->branch->update(['battery_warn_threshold' => 10, 'battery_critical_threshold' => 5]);

        $device = $this->heartbeat(['battery' => ['level' => 18, 'charging' => false]]);

        $this->assertNull($device->fresh()->battery_alert_level);
        Notification::assertSentToTimes($this->branchAdmin, DeviceBatteryLow::class, 1);
    }

    public function test_the_critical_alert_says_something_else(): void
    {
        $this->heartbeat(['battery' => ['level' => 6, 'charging' => false]]);

        Notification::assertSentTo($this->branchAdmin, DeviceBatteryLow::class, function ($notification) {
            $payload = $notification->toArray($this->branchAdmin);

            return $payload['severity'] === 'critical' && $payload['title'] === 'Se va a apagar';
        });
    }
```

**Antes de escribirlos, leer el archivo de pruebas:** usa un helper propio para mandar latidos y unos usuarios de prueba montados en `setUp()`. Los nombres de arriba (`$this->heartbeat(...)`, `$this->branch`, `$this->branchAdmin`) deben ajustarse a los que ese archivo ya tenga; si el helper no existe con esa forma, reutilizar el que haya en vez de inventar uno nuevo.

- [ ] **Step 2: Correrlos y ver que fallan**

Run: `sail artisan test --filter=DeviceAlertsTest`
Expected: FAIL en los cinco nuevos — la marca guarda `20`, no `warn`, y el payload no tiene `severity`.

- [ ] **Step 3: Reescribir `checkBattery`**

En `app/Services/Devices/DeviceAlertService.php`, sustituir el método `checkBattery` y añadir el import `use App\Services\Devices\BatteryThresholds;`:

```php
    private function checkBattery(Device $device): void
    {
        // Sin lectura no se sabe nada: ni avisar ni rearmar. Si `null` rearmara,
        // un equipo al 15 % que arranca sin haber leído aún su batería volvería
        // a avisar en cada reinicio (p. ej. al auto-actualizarse).
        if ($device->battery_level === null) {
            return;
        }

        $severity = BatteryThresholds::fromBranch($device->branch)
            ->severityFor($device->battery_level, (bool) $device->battery_charging);

        if ($severity === null) {
            if ($device->battery_alert_level !== null) {
                $device->forceFill(['battery_alert_level' => null])->saveQuietly();
            }

            return;
        }

        // Ya avisado a esta severidad, o a una peor: callar. Bajar del aviso al
        // crítico sí vuelve a sonar; subir del crítico al aviso, no.
        if ($device->battery_alert_level === $severity
            || ($severity === 'warn' && $device->battery_alert_level === 'critical')) {
            return;
        }

        $device->forceFill(['battery_alert_level' => $severity])->saveQuietly();
        $this->notify($device, new DeviceBatteryLow($device, $severity));
    }
```

- [ ] **Step 4: Reescribir la notificación**

En `app/Notifications/DeviceBatteryLow.php`, sustituir el constructor y `toArray`:

```php
    public function __construct(public Device $device, public string $severity) {}

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $level = $this->device->battery_level;
        $name = $this->device->displayName();
        $critical = $this->severity === 'critical';

        return [
            'type' => 'device.battery.low',
            'level' => 'important',
            'severity' => $this->severity,
            'title' => $critical ? 'Se va a apagar' : 'Batería baja',
            'body' => $critical
                ? "{$name} está al {$level} %. Conéctala o se apaga a media venta."
                : "{$name} está al {$level} % y no está cargando.",
            'device_id' => $this->device->device_id,
            'branch_id' => $this->device->branch_id,
            'name' => $name,
            'battery_level' => $level,
        ];
    }
```

- [ ] **Step 5: Correr los tests y verlos pasar**

Run: `sail artisan test --filter=DeviceAlertsTest`
Expected: PASS (los 8 que había más los 5 nuevos). Si falla `test_battery_alerts_once_per_threshold_and_rearm_on_charge`, revisar que espere `warn`/`critical` y no `20`/`10`: ese test hay que actualizarlo, no rodearlo.

- [ ] **Step 6: Buscar cualquier otro sitio que construya la notificación**

Run: `grep -rn "new DeviceBatteryLow" app/ tests/`
Expected: solo `DeviceAlertService` y los tests. Si aparece otro, pasarle la severidad.

- [ ] **Step 7: Pint y commit**

```bash
./vendor/bin/pint
git add app/Services/Devices/DeviceAlertService.php app/Notifications/DeviceBatteryLow.php tests/Feature/Devices/DeviceAlertsTest.php
git commit -m "feat(equipos): el aviso usa el umbral de la sucursal y distingue dos urgencias

«Batería baja» y «Se va a apagar» son el mismo hecho con distinta
urgencia, así que son una sola notificación con severidad. La marca
guarda warn o critical: bajar del aviso al crítico vuelve a sonar,
subir del crítico al aviso no.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Los paneles pintan la severidad

**Files:**
- Modify: `app/Services/Devices/BranchDevicesQuery.php` (líneas 42-50 y el método `present`)
- Modify: `resources/js/Components/Devices/DeviceCard.vue` (bloque `STATUS`, `batteryColor`)
- Test: `tests/Feature/Sucursal/DevicesTest.php`

**Interfaces:**
- Consumes: `Device::statusFor()` / accessor `status` (tarea 2), `BatteryThresholds` (tarea 1).
- Produces: cada array de `BranchDevicesQuery::present()` gana la clave `severity` (`'critical'`, `'warn'` o `null`). La lista `alerts` incluye los equipos en `battery_critical`. Lo consumen los tres paneles (Sucursal, Empresa, Caja) y `DeviceAlertStrip` en la tarea 8.

- [ ] **Step 1: Escribir el test que falla**

Añadir a `tests/Feature/Sucursal/DevicesTest.php`:

```php
    public function test_a_critical_device_is_presented_and_listed_in_the_alerts(): void
    {
        $this->branch->update(['battery_warn_threshold' => 30, 'battery_critical_threshold' => 15]);

        $device = Device::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'battery_level' => 9,
            'battery_charging' => false,
            'last_seen_at' => now(),
        ]);

        $this->actingAs($this->branchAdmin)
            ->get("/{$this->tenant->slug}/sucursal/equipos")
            ->assertInertia(fn ($page) => $page
                ->where('devices.0.status', 'battery_critical')
                ->where('devices.0.severity', 'critical')
                ->where('alerts.0.device_id', $device->device_id)
            );
    }
```

**Antes de escribirlo, leer el archivo:** no hay `DeviceFactory` en el repo (`database/factories/DeviceFactory.php` no existe). Si `DevicesTest` crea los equipos con `Device::create([...])` o un helper propio, usar eso mismo; si no existe ninguna forma cómoda, crear la factory como parte de esta tarea — pero solo si el archivo de pruebas la necesita de verdad.

- [ ] **Step 2: Correrlo y ver que falla**

Run: `sail artisan test --filter=DevicesTest`
Expected: FAIL — la clave `severity` no existe en el array presentado.

- [ ] **Step 3: Cargar la sucursal y emitir la severidad**

En `app/Services/Devices/BranchDevicesQuery.php`:

1. Añadir el import `use App\Services\Devices\BatteryThresholds;` (mismo namespace, así que no hace falta import; comprobar).
2. En la consulta de equipos, añadir el eager load. Sustituir:

```php
        $devices = Device::query()
            ->active()
            ->forBranch($branchId)
            ->orderBy('name')
```

por:

```php
        // `with('branch')`: el estado de cada equipo se compara contra los
        // umbrales de su sucursal, y el panel de Empresa presenta N sucursales
        // de una vez. Sin esto, una consulta por equipo.
        $devices = Device::query()
            ->active()
            ->forBranch($branchId)
            ->with('branch')
            ->orderBy('name')
```

3. En `present()`, añadir la severidad justo después de `'status' => $d->status,`:

```php
            // La calcula la consulta, no el componente: la consumen tres paneles
            // (Sucursal, Empresa y Caja) y si cada uno la dedujera, la deducirían
            // distinto.
            'severity' => BatteryThresholds::fromBranch($d->branch)
                ->severityFor($d->battery_level, (bool) $d->battery_charging),
```

4. En el filtro de alertas, añadir el estado nuevo:

```php
        $alerts = $devices->filter(fn ($d) => in_array($d['status'], ['battery_low', 'battery_critical', 'silent'], true) || $d['outdated'])->values();
```

- [ ] **Step 4: Correr el test y verlo pasar**

Run: `sail artisan test --filter=DevicesTest`
Expected: PASS.

- [ ] **Step 5: Comprobar que no hay N+1**

Run: `sail artisan test --filter=Empresa\\DevicesTest`
Expected: PASS. Además, revisar a ojo que `Empresa\DeviceController` llame a `BranchDevicesQuery::forBranch()` por sucursal (ya lo hacía) y que ninguna otra consulta de equipos presente sin `with('branch')`:

Run: `grep -rn "Device::query()" app/`
Expected: cada sitio que luego lea `->status` o llame a `present()` debe cargar `branch`.

- [ ] **Step 6: La tarjeta pinta por severidad**

En `resources/js/Components/Devices/DeviceCard.vue`:

1. Añadir el estado nuevo al mapa `STATUS`, justo antes de `battery_low`:

```js
    battery_critical: { label: 'Se va a apagar', chip: 'bg-red-100 text-red-800', dot: 'bg-red-500', ring: 'ring-red-300' },
```

2. Sustituir `batteryColor` para que use la severidad que ya viene calculada:

```js
const batteryColor = computed(() => {
    if (!hasBattery.value) return 'bg-gray-300';
    if (props.device.battery_charging) return 'bg-emerald-500';
    // La severidad la decide el servidor con los umbrales de la sucursal: aquí
    // solo se pinta. El ámbar del 40 % es un degradado visual, no una alerta.
    if (props.device.severity) return 'bg-red-500';
    return props.device.battery_level <= 40 ? 'bg-amber-500' : 'bg-emerald-500';
});
```

- [ ] **Step 7: Construir el frontend**

Run: `npm run build`
Expected: build sin errores.

- [ ] **Step 8: Pint y commit**

```bash
./vendor/bin/pint
git add app/Services/Devices/BranchDevicesQuery.php resources/js/Components/Devices/DeviceCard.vue tests/Feature/Sucursal/DevicesTest.php
git commit -m "feat(equipos): la tarjeta distingue «batería baja» de «se va a apagar»

La severidad la calcula la consulta y la pintan los tres paneles; si
cada uno la dedujera, la deducirían distinto. Y un equipo crítico ya no
se caía de la franja «Requieren atención», que filtraba por el estado
viejo.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Editar los umbrales — dos superficies, una sola regla

**Files:**
- Create: `app/Support/BatteryThresholdRules.php`
- Create: `resources/js/Components/Devices/BatteryThresholdFields.vue`
- Modify: `app/Http/Controllers/Sucursal/ConfiguracionController.php`
- Modify: `app/Http/Controllers/Empresa/SucursalController.php` (método `update`, líneas 110-142)
- Modify: `resources/js/Pages/Sucursal/Configuracion.vue`
- Modify: `resources/js/Pages/Empresa/Sucursales/Edit.vue`
- Modify: `routes/web.php` (grupo `sucursal`, junto a `configuracion.update`)
- Test: `tests/Feature/Sucursal/ConfiguracionTest.php` y `tests/Feature/Empresa/SucursalesTest.php`

**Por qué dos superficies:** el grupo `/{tenant}/sucursal` está bajo `role:admin-sucursal|superadmin` y su controlador resuelve la sucursal con `Auth::user()->branch_id`. El `admin-empresa` ni entra ahí ni tiene `branch_id`, así que su puerta es la pantalla de editar sucursal que ya administra. La validación vive en un solo sitio o el día que alguien cambie el tope de 95 lo cambiará en uno de los dos.

**Interfaces:**
- Consumes: las columnas de la tarea 1.
- Produces: `BatteryThresholdRules::rules(bool $optional = false): array` devuelve las reglas con las claves `battery_warn_threshold` y `battery_critical_threshold`; `BatteryThresholdRules::messages(): array`. Ruta con nombre `sucursal.configuracion.bateria` (`PUT /{tenant}/sucursal/configuracion/bateria`). Componente `BatteryThresholdFields` con props `warn`, `critical`, `errors` y eventos `update:warn` / `update:critical`.

- [ ] **Step 1: Escribir los tests que fallan**

Añadir a `tests/Feature/Sucursal/ConfiguracionTest.php`:

```php
    public function test_branch_admin_saves_the_battery_thresholds(): void
    {
        $this->actingAs($this->branchAdmin)
            ->put("/{$this->tenant->slug}/sucursal/configuracion/bateria", [
                'battery_warn_threshold' => 35,
                'battery_critical_threshold' => 15,
            ])
            ->assertRedirect();

        $this->assertSame(35, $this->branch->fresh()->battery_warn_threshold);
        $this->assertSame(15, $this->branch->fresh()->battery_critical_threshold);
    }

    public function test_the_critical_threshold_can_never_reach_the_warning_one(): void
    {
        $this->actingAs($this->branchAdmin)
            ->put("/{$this->tenant->slug}/sucursal/configuracion/bateria", [
                'battery_warn_threshold' => 20,
                'battery_critical_threshold' => 20,
            ])
            ->assertSessionHasErrors('battery_warn_threshold');
    }

    public function test_it_only_accepts_multiples_of_five_inside_the_range(): void
    {
        $this->actingAs($this->branchAdmin)
            ->put("/{$this->tenant->slug}/sucursal/configuracion/bateria", [
                'battery_warn_threshold' => 33,
                'battery_critical_threshold' => 10,
            ])
            ->assertSessionHasErrors('battery_warn_threshold');

        $this->actingAs($this->branchAdmin)
            ->put("/{$this->tenant->slug}/sucursal/configuracion/bateria", [
                'battery_warn_threshold' => 100,
                'battery_critical_threshold' => 10,
            ])
            ->assertSessionHasErrors('battery_warn_threshold');
    }

    public function test_saving_the_thresholds_does_not_touch_the_payment_methods(): void
    {
        $before = $this->branch->fresh()->payment_methods_enabled;

        $this->actingAs($this->branchAdmin)
            ->put("/{$this->tenant->slug}/sucursal/configuracion/bateria", [
                'battery_warn_threshold' => 30,
                'battery_critical_threshold' => 10,
            ]);

        $this->assertSame($before, $this->branch->fresh()->payment_methods_enabled);
    }
```

Añadir a `tests/Feature/Empresa/SucursalesTest.php`:

```php
    public function test_company_admin_saves_the_battery_thresholds_of_a_branch(): void
    {
        $this->actingAs($this->companyAdmin)
            ->put("/{$this->tenant->slug}/empresa/sucursales/{$this->branch->id}", [
                ...$this->validBranchPayload(),
                'battery_warn_threshold' => 40,
                'battery_critical_threshold' => 20,
            ])
            ->assertRedirect();

        $this->assertSame(40, $this->branch->fresh()->battery_warn_threshold);
    }
```

**Antes de escribirlos, leer los dos archivos de pruebas:** los nombres `$this->branchAdmin`, `$this->companyAdmin`, `$this->validBranchPayload()` son los que se esperan; ajustar a los que cada archivo ya tenga. El payload de editar sucursal exige `name` y `status`, así que hay que mandarlos.

- [ ] **Step 2: Correrlos y ver que fallan**

Run: `sail artisan test --filter=ConfiguracionTest`
Expected: FAIL — 404, la ruta no existe.

- [ ] **Step 3: Escribir las reglas, en un solo sitio**

Crear `app/Support/BatteryThresholdRules.php`:

```php
<?php

namespace App\Support;

/**
 * Las reglas de los dos umbrales de batería, en un solo sitio.
 *
 * Los edita el admin de sucursal desde su configuración y el admin de empresa
 * desde la pantalla de la sucursal. Si cada formulario llevara su copia, el día
 * que alguien cambie el tope lo cambiará en uno de los dos.
 *
 * Los rangos no son simétricos a propósito: con un aviso puesto en 5 no
 * existiría ningún crítico válido, y el control ofrecería un valor que ningún
 * guardado puede aceptar.
 */
final class BatteryThresholdRules
{
    /** @return array<string, array<int, string>> */
    public static function rules(bool $optional = false): array
    {
        $presence = $optional ? 'sometimes' : 'required';

        return [
            'battery_warn_threshold' => [$presence, 'integer', 'multiple_of:5', 'between:10,95', 'gt:battery_critical_threshold'],
            'battery_critical_threshold' => [$presence, 'integer', 'multiple_of:5', 'between:5,90'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'battery_warn_threshold.gt' => 'El aviso urgente tiene que ser menor que el de batería baja.',
            'battery_warn_threshold.multiple_of' => 'El porcentaje va de 5 en 5.',
            'battery_critical_threshold.multiple_of' => 'El porcentaje va de 5 en 5.',
            'battery_warn_threshold.between' => 'El aviso va entre 10 % y 95 %.',
            'battery_critical_threshold.between' => 'El aviso urgente va entre 5 % y 90 %.',
        ];
    }
}
```

- [ ] **Step 4: La puerta del admin de sucursal**

En `app/Http/Controllers/Sucursal/ConfiguracionController.php`, añadir el import `use App\Support\BatteryThresholdRules;` y el método:

```php
    /**
     * Ruta aparte de `update` a propósito: aquella exige
     * `payment_methods_enabled` (`required|array|min:1`) y se caería al mandar
     * solo los umbrales.
     */
    public function updateBattery(Request $request): RedirectResponse
    {
        $branch = Branch::withoutGlobalScopes()->findOrFail(Auth::user()->branch_id);

        $validated = $request->validate(
            BatteryThresholdRules::rules(),
            BatteryThresholdRules::messages(),
        );

        $branch->update($validated);

        return back()->with('success', 'Aviso de batería actualizado.');
    }
```

En `routes/web.php`, junto a las dos rutas de configuración de sucursal:

```php
                Route::put('configuracion/bateria', [SucursalConfiguracionController::class, 'updateBattery'])->name('configuracion.bateria');
```

- [ ] **Step 5: La puerta del admin de empresa**

En `app/Http/Controllers/Empresa/SucursalController.php`, método `update`: añadir el import `use App\Support\BatteryThresholdRules;` y fusionar las reglas dentro del array que ya se valida. Sustituir la línea final del array de `$request->validate([...])` para que quede:

```php
            'sale_item_edit_reason_mode' => 'sometimes|in:disabled,optional,required',
            ...BatteryThresholdRules::rules(optional: true),
        ], BatteryThresholdRules::messages());
```

(El segundo argumento de `validate()` son los mensajes; si esa llamada no tenía segundo argumento, añadirlo.)

- [ ] **Step 6: Correr los tests del backend**

Run: `sail artisan test --filter=ConfiguracionTest`
Expected: PASS.

Run: `sail artisan test --filter=SucursalesTest`
Expected: PASS.

- [ ] **Step 7: El componente de los dos controles**

Crear `resources/js/Components/Devices/BatteryThresholdFields.vue`:

```vue
<script setup>
import { computed } from 'vue';

/**
 * Los dos porcentajes, de 5 en 5. Sin teclado y sin texto libre: esto se toca
 * en una tablet, de pie, detrás del mostrador.
 *
 * El urgente no puede alcanzar al de aviso, así que su tope es el valor del
 * otro menos un paso. La misma regla la vuelve a comprobar el servidor.
 */
const props = defineProps({
    warn: { type: Number, required: true },
    critical: { type: Number, required: true },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:warn', 'update:critical']);

const STEP = 5;
const WARN_MIN = 10;
const WARN_MAX = 95;
const CRITICAL_MIN = 5;

const criticalMax = computed(() => props.warn - STEP);
const warnMin = computed(() => Math.max(WARN_MIN, props.critical + STEP));

const setWarn = (value) => {
    const next = Math.min(WARN_MAX, Math.max(warnMin.value, value));
    emit('update:warn', next);
};

const setCritical = (value) => {
    const next = Math.min(criticalMax.value, Math.max(CRITICAL_MIN, value));
    emit('update:critical', next);
};
</script>

<template>
    <div class="space-y-3">
        <div class="flex items-center justify-between gap-4 rounded-xl p-4 ring-1 ring-gray-100">
            <div>
                <p class="text-sm font-bold text-gray-800">Avisar al bajar de</p>
                <p class="mt-0.5 text-xs text-gray-500">Franja ámbar en la caja y en el propio equipo.</p>
            </div>
            <div class="inline-flex shrink-0 items-center overflow-hidden rounded-xl ring-1 ring-gray-200">
                <button type="button" :disabled="warn <= warnMin" @click="setWarn(warn - STEP)"
                    class="px-3.5 py-2 text-lg font-bold text-gray-500 transition hover:bg-gray-100 disabled:opacity-30" aria-label="Bajar el aviso">−</button>
                <span class="min-w-[4.5rem] border-x border-gray-200 px-2 py-2 text-center text-sm font-bold tabular-nums text-gray-900">{{ warn }} %</span>
                <button type="button" :disabled="warn >= 95" @click="setWarn(warn + STEP)"
                    class="px-3.5 py-2 text-lg font-bold text-gray-500 transition hover:bg-gray-100 disabled:opacity-30" aria-label="Subir el aviso">+</button>
            </div>
        </div>

        <div class="flex items-center justify-between gap-4 rounded-xl p-4 ring-1 ring-gray-100">
            <div>
                <p class="text-sm font-bold text-gray-800">Urgente al bajar de</p>
                <p class="mt-0.5 text-xs text-gray-500">La franja se pone roja: el equipo está por apagarse.</p>
            </div>
            <div class="inline-flex shrink-0 items-center overflow-hidden rounded-xl ring-1 ring-gray-200">
                <button type="button" :disabled="critical <= 5" @click="setCritical(critical - STEP)"
                    class="px-3.5 py-2 text-lg font-bold text-gray-500 transition hover:bg-gray-100 disabled:opacity-30" aria-label="Bajar el aviso urgente">−</button>
                <span class="min-w-[4.5rem] border-x border-gray-200 px-2 py-2 text-center text-sm font-bold tabular-nums text-gray-900">{{ critical }} %</span>
                <button type="button" :disabled="critical >= criticalMax" @click="setCritical(critical + STEP)"
                    class="px-3.5 py-2 text-lg font-bold text-gray-500 transition hover:bg-gray-100 disabled:opacity-30" aria-label="Subir el aviso urgente">+</button>
            </div>
        </div>

        <p v-if="errors.battery_warn_threshold" class="text-sm font-medium text-red-600">{{ errors.battery_warn_threshold }}</p>
        <p v-if="errors.battery_critical_threshold" class="text-sm font-medium text-red-600">{{ errors.battery_critical_threshold }}</p>
    </div>
</template>
```

- [ ] **Step 8: La tarjeta en la configuración de sucursal**

En `resources/js/Pages/Sucursal/Configuracion.vue`:

1. Importar el componente: `import BatteryThresholdFields from '@/Components/Devices/BatteryThresholdFields.vue';`
2. Añadir el formulario propio, junto al de métodos de pago:

```js
const batteryForm = useForm({
    battery_warn_threshold: props.branch.battery_warn_threshold ?? 20,
    battery_critical_threshold: props.branch.battery_critical_threshold ?? 10,
});

const submitBattery = () => {
    batteryForm.put(route('sucursal.configuracion.bateria', props.tenant.slug), { preserveScroll: true });
};
```

3. Añadir la sección después de la de «Métodos de pago» (fuera de su `<form>`, con el suyo propio):

```html
            <form @submit.prevent="submitBattery">
                <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                    <div class="border-b border-gray-100 px-6 py-5">
                        <h2 class="text-base font-bold text-gray-900">Aviso de batería</h2>
                        <p class="mt-1 text-sm text-gray-500">Cuando una báscula o tablet de esta sucursal baja de estos porcentajes sin estar cargando.</p>
                    </div>
                    <div class="p-6">
                        <BatteryThresholdFields
                            :warn="batteryForm.battery_warn_threshold"
                            :critical="batteryForm.battery_critical_threshold"
                            :errors="batteryForm.errors"
                            @update:warn="batteryForm.battery_warn_threshold = $event"
                            @update:critical="batteryForm.battery_critical_threshold = $event" />
                    </div>
                    <div class="flex justify-end border-t border-gray-100 bg-gray-50/50 px-6 py-3">
                        <button type="submit" :disabled="batteryForm.processing"
                            class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-5 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 active:scale-95 disabled:opacity-50">
                            Guardar
                        </button>
                    </div>
                </section>
            </form>
```

- [ ] **Step 9: Los mismos campos en la pantalla de empresa**

En `resources/js/Pages/Empresa/Sucursales/Edit.vue`:

1. Importar el componente igual que arriba.
2. Añadir al `useForm` existente, junto a los demás flags:

```js
    battery_warn_threshold: props.sucursal.battery_warn_threshold ?? 20,
    battery_critical_threshold: props.sucursal.battery_critical_threshold ?? 10,
```

3. Añadir una sección nueva con el mismo marco que «Permisos del cajero»:

```html
            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Aviso de batería</h2>
                    <p class="mt-1 text-sm text-gray-500">A qué porcentaje quieres enterarte de que una báscula o tablet de esta sucursal se está quedando sin pila.</p>
                </div>
                <div class="p-6">
                    <BatteryThresholdFields
                        :warn="form.battery_warn_threshold"
                        :critical="form.battery_critical_threshold"
                        :errors="form.errors"
                        @update:warn="form.battery_warn_threshold = $event"
                        @update:critical="form.battery_critical_threshold = $event" />
                </div>
            </section>
```

- [ ] **Step 10: Construir y mirar las dos pantallas**

Run: `npm run build`
Expected: sin errores.

Comprobar a mano, con `composer run dev` y sesión iniciada:
- `http://localhost/el-toro/sucursal/configuracion` con `sucursal@eltoro.test` — subir el aviso a 35, guardar, recargar y ver que quedó.
- `http://localhost/el-toro/empresa/sucursales/1/edit` con `admin@eltoro.test` — los mismos dos controles.
- Bajar el aviso hasta que el botón «−» se apague al tocar el valor del urgente.

- [ ] **Step 11: Pint y commit**

```bash
./vendor/bin/pint
git add app/Support/BatteryThresholdRules.php app/Http/Controllers/Sucursal/ConfiguracionController.php app/Http/Controllers/Empresa/SucursalController.php resources/js/Components/Devices/BatteryThresholdFields.vue resources/js/Pages/Sucursal/Configuracion.vue resources/js/Pages/Empresa/Sucursales/Edit.vue routes/web.php tests/Feature/Sucursal/ConfiguracionTest.php tests/Feature/Empresa/SucursalesTest.php
git commit -m "feat(equipos): el porcentaje del aviso se pone desde la web

Dos controles de 5 en 5, sin teclado: esto se toca en una tablet detrás
del mostrador. Dos superficies porque el admin de empresa no entra a la
configuración de sucursal, y una sola regla de validación para que los
topes no se separen.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: El umbral viaja en la respuesta del latido

**Files:**
- Modify: `app/Http/Controllers/Api/DeviceHeartbeatController.php` (método estático `respond`)
- Test: `tests/Feature/Api/DeviceHeartbeatTest.php`, `tests/Feature/Hub/DeviceHeartbeatTest.php`

**Por qué aquí:** es lo que permitirá que, en la entrega 3, la báscula se queje de sí misma sin preguntarle a nadie — incluso sin internet. Se hace ahora porque es una línea en la nube y desbloquea a las cuatro apps.

**Interfaces:**
- Consumes: `BatteryThresholds` (tarea 1).
- Produces: la respuesta de `POST /api/v1/devices/heartbeat` y de `POST /api/v1/hub/devices/heartbeat` gana `data.battery_alert = {"warn": int, "critical": int}`. El hub reusa el mismo método estático, así que ambas cambian con una sola edición.

- [ ] **Step 1: Escribir los tests que fallan**

Añadir a `tests/Feature/Api/DeviceHeartbeatTest.php`:

```php
    public function test_the_response_carries_the_branch_thresholds(): void
    {
        $this->branch->update(['battery_warn_threshold' => 35, 'battery_critical_threshold' => 15]);

        $this->postJson('/api/v1/devices/heartbeat', [
            'device_id' => 'surface-1',
            'kind' => 'scale_windows',
            'name' => 'Báscula 1',
        ], ['X-Api-Key' => $this->apiKey])
            ->assertSuccessful()
            ->assertJsonPath('data.battery_alert.warn', 35)
            ->assertJsonPath('data.battery_alert.critical', 15);
    }
```

Añadir a `tests/Feature/Hub/DeviceHeartbeatTest.php` el mismo caso contra `/api/v1/hub/devices/heartbeat` con el token Sanctum que ese archivo ya monta.

**Antes de escribirlos, leer los dos archivos:** copiar la forma exacta en que ya mandan un latido (cabeceras, payload mínimo, nombres de las propiedades del `setUp`).

- [ ] **Step 2: Correrlos y ver que fallan**

Run: `sail artisan test --filter=DeviceHeartbeatTest`
Expected: FAIL — `Property [data.battery_alert.warn] does not exist`.

- [ ] **Step 3: Añadir el campo**

En `app/Http/Controllers/Api/DeviceHeartbeatController.php`, añadir el import `use App\Services\Devices\BatteryThresholds;` y sustituir `respond()`:

```php
    public static function respond(HeartbeatResult $result): JsonResponse
    {
        $device = $result->device;
        $device->loadMissing('branch');
        $thresholds = BatteryThresholds::fromBranch($device->branch);

        return response()->json([
            'data' => [
                'device_id' => $device->device_id,
                'name' => $device->name,
                'display_name' => $device->display_name,
                'status' => $device->status,
                // Para que el equipo pueda quejarse de su propia pila sin
                // preguntarle a nadie, también sin internet. Campo nuevo en una
                // respuesta: quien no lo entienda lo ignora y sigue vendiendo.
                'battery_alert' => [
                    'warn' => $thresholds->warn,
                    'critical' => $thresholds->critical,
                ],
                'server_time' => now()->toIso8601String(),
            ],
        ], $result->wasNew ? 201 : 200);
    }
```

- [ ] **Step 4: Correr los tests y verlos pasar**

Run: `sail artisan test --filter=DeviceHeartbeatTest`
Expected: PASS, los de la Scale API y los del hub.

- [ ] **Step 5: Comprobar que la Scale API legacy sigue intacta**

Run: `sail artisan test --filter=ScaleLegacyContractTest`
Expected: PASS, sin tocar ese archivo. Si falla, se rompió el contrato con las básculas viejas y hay que revertir el paso 3.

- [ ] **Step 6: Pint y commit**

```bash
./vendor/bin/pint
git add app/Http/Controllers/Api/DeviceHeartbeatController.php tests/Feature/Api/DeviceHeartbeatTest.php tests/Feature/Hub/DeviceHeartbeatTest.php
git commit -m "feat(equipos): el latido responde con el umbral de la sucursal

Un campo más dentro de data, en las dos superficies a la vez porque el
hub reusa el mismo método. Con esto la báscula podrá avisar de su propia
pila sin internet, que es justo cuando peor viene quedarse sin ella.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: El endpoint de alertas

**Files:**
- Create: `app/Services/Devices/BranchDeviceAlertsQuery.php`
- Create: `app/Http/Controllers/DeviceAlertsController.php`
- Create: `app/Http/Controllers/Api/Hub/DeviceAlertsController.php`
- Create: `tests/Feature/Devices/DeviceAlertsEndpointTest.php`
- Modify: `routes/web.php` (grupo nuevo bajo el tenant), `routes/api.php` (grupo hub)

**Interfaces:**
- Consumes: el accessor `status` (tarea 2).
- Produces: `BranchDeviceAlertsQuery::forBranch(int $branchId): array` — lista de `['device_id' => string, 'name' => string, 'battery_level' => int, 'severity' => 'warn'|'critical']`. Ambos endpoints responden `{"data": [...]}`. Ruta web con nombre `equipos.alertas`; ruta hub `GET /api/v1/hub/devices/alerts`. Lo consume `useDeviceAlerts` en la tarea 8.

- [ ] **Step 1: Escribir los tests que fallan**

Crear `tests/Feature/Devices/DeviceAlertsEndpointTest.php`:

```php
<?php

namespace Tests\Feature\Devices;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La franja se pinta con esto, cada 60 s y en varias pantallas. Tiene que
 * devolver poco, solo de la sucursal de quien pregunta, y no romperse con un
 * usuario que no tiene sucursal.
 */
class DeviceAlertsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_only_the_devices_in_alert(): void
    {
        [$tenant, $branch, $cashier] = $this->branchWithCashier();

        $low = $this->device($tenant, $branch, ['device_id' => 'baja', 'battery_level' => 14]);
        $this->device($tenant, $branch, ['device_id' => 'sana', 'battery_level' => 90]);
        $this->device($tenant, $branch, ['device_id' => 'cargando', 'battery_level' => 5, 'battery_charging' => true]);

        $this->actingAs($cashier)
            ->getJson("/{$tenant->slug}/equipos/alertas")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.device_id', $low->device_id)
            ->assertJsonPath('data.0.severity', 'warn');
    }

    public function test_a_muted_or_retired_device_never_shows_up(): void
    {
        [$tenant, $branch, $cashier] = $this->branchWithCashier();

        $this->device($tenant, $branch, ['device_id' => 'callado', 'battery_level' => 8, 'muted_at' => now()]);
        $this->device($tenant, $branch, ['device_id' => 'de-baja', 'battery_level' => 8, 'retired_at' => now()]);

        $this->actingAs($cashier)
            ->getJson("/{$tenant->slug}/equipos/alertas")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_critical_severity_travels(): void
    {
        [$tenant, $branch, $cashier] = $this->branchWithCashier();
        $branch->update(['battery_warn_threshold' => 30, 'battery_critical_threshold' => 15]);

        $this->device($tenant, $branch, ['device_id' => 'critica', 'battery_level' => 9]);

        $this->actingAs($cashier)
            ->getJson("/{$tenant->slug}/equipos/alertas")
            ->assertJsonPath('data.0.severity', 'critical');
    }

    public function test_a_cashier_never_sees_another_branch(): void
    {
        [$tenant, $branch, $cashier] = $this->branchWithCashier();
        $other = $this->otherBranchOf($tenant);

        $this->device($tenant, $other, ['device_id' => 'de-la-otra', 'battery_level' => 8]);

        $this->actingAs($cashier)
            ->getJson("/{$tenant->slug}/equipos/alertas")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_user_without_branch_gets_an_empty_list_not_a_403(): void
    {
        // El superadmin entra a los paneles y no tiene sucursal. La franja vive
        // en el layout: un 403 sería un error cada 60 segundos.
        [$tenant, $branch, ] = $this->branchWithCashier();
        $superadmin = $this->superadminOf($tenant);

        $this->device($tenant, $branch, ['device_id' => 'baja', 'battery_level' => 8]);

        $this->actingAs($superadmin)
            ->getJson("/{$tenant->slug}/equipos/alertas")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
```

**Los helpers (`branchWithCashier`, `device`, `otherBranchOf`, `superadminOf`) no existen:** reutilizar los que ya use `tests/Feature/Caja/DevicesTest.php` para montar tenant, sucursal, usuarios y equipos — ese archivo ya monta exactamente este escenario. Copiar su forma en vez de inventar helpers nuevos.

- [ ] **Step 2: Correrlos y ver que fallan**

Run: `sail artisan test --filter=DeviceAlertsEndpointTest`
Expected: FAIL — 404.

- [ ] **Step 3: La consulta**

Crear `app/Services/Devices/BranchDeviceAlertsQuery.php`:

```php
<?php

namespace App\Services\Devices;

use App\Models\Device;

/**
 * Los equipos de una sucursal que están pidiendo un cargador, en la forma más
 * corta posible: esto se pinta en una franja, no en un panel, y lo consultan
 * varias pantallas cada 60 segundos.
 *
 * El filtro va en PHP y no en SQL porque `status` es un estado derivado, no una
 * columna. Son pocos equipos por sucursal; es la misma decisión que ya tomó
 * `BranchDevicesQuery`.
 */
class BranchDeviceAlertsQuery
{
    /** @return array<int, array<string, mixed>> */
    public function forBranch(int $branchId): array
    {
        return Device::query()
            ->active()
            ->forBranch($branchId)
            ->whereNull('muted_at')
            ->with('branch')
            ->orderBy('name')
            ->get()
            ->filter(fn (Device $d) => in_array($d->status, ['battery_low', 'battery_critical'], true))
            ->map(fn (Device $d) => [
                'device_id' => $d->device_id,
                'name' => $d->displayName(),
                'battery_level' => $d->battery_level,
                'severity' => $d->status === 'battery_critical' ? 'critical' : 'warn',
            ])
            ->values()
            ->all();
    }
}
```

- [ ] **Step 4: Las dos puertas**

Crear `app/Http/Controllers/DeviceAlertsController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Services\Devices\BranchDeviceAlertsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lo que la franja pregunta cada 60 segundos, en la caja y en la sucursal.
 *
 * Un usuario sin sucursal —el superadmin— recibe la lista vacía y no un 403:
 * la franja vive en el layout y estaría disparando un error contra una pantalla
 * que él sí puede ver.
 */
class DeviceAlertsController extends Controller
{
    public function index(Request $request, BranchDeviceAlertsQuery $query): JsonResponse
    {
        $branchId = $request->user()?->branch_id;

        return response()->json([
            'data' => $branchId ? $query->forBranch((int) $branchId) : [],
        ]);
    }
}
```

Crear `app/Http/Controllers/Api/Hub/DeviceAlertsController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Hub;

use App\Http\Controllers\Controller;
use App\Services\Devices\BranchDeviceAlertsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La misma lista para el hub, con la sucursal de su token. El hub la pinta en
 * su propia franja, con el componente que la web ya define.
 */
class DeviceAlertsController extends Controller
{
    public function index(Request $request, BranchDeviceAlertsQuery $query): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);

        return response()->json([
            'data' => $user->branch_id ? $query->forBranch((int) $user->branch_id) : [],
        ]);
    }
}
```

En `routes/web.php`, junto al grupo de agenda (mismo patrón: multirrol, dentro del tenant, fuera de los prefijos de rol):

```php
        // Alertas de equipos: lo que pinta la franja de batería. Compartido por
        // caja y sucursal, porque el layout de las dos la monta.
        Route::middleware('role:admin-sucursal|cajero|superadmin')
            ->prefix('equipos')
            ->name('equipos.')
            ->group(function () {
                Route::get('alertas', [DeviceAlertsController::class, 'index'])->name('alertas');
            });
```

En `routes/api.php`, en el grupo del hub, junto a `devices/heartbeat`:

```php
        Route::get('devices/alerts', [HubDeviceAlertsController::class, 'index'])->name('api.hub.devices.alerts');
```

(Importar los dos controladores arriba del archivo, con el alias `HubDeviceAlertsController` en `api.php` si ya hay otro `DeviceAlertsController` importado.)

- [ ] **Step 5: Correr los tests y verlos pasar**

Run: `sail artisan test --filter=DeviceAlertsEndpointTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Comprobar que el hub también responde**

Run: `sail artisan test --filter=Hub`
Expected: PASS. Si el grupo del hub exige `hub.role`, un token de admin-empresa debe recibir 403 — comprobar que la ruta quedó dentro del grupo correcto y no fuera.

- [ ] **Step 7: Pint y commit**

```bash
./vendor/bin/pint
git add app/Services/Devices/BranchDeviceAlertsQuery.php app/Http/Controllers/DeviceAlertsController.php app/Http/Controllers/Api/Hub/DeviceAlertsController.php routes/web.php routes/api.php tests/Feature/Devices/DeviceAlertsEndpointTest.php
git commit -m "feat(equipos): una consulta corta para saber qué equipo pide cargador

La misma para la web y para el hub. Un usuario sin sucursal recibe la
lista vacía y no un 403: la franja vive en el layout y sería un error
cada minuto contra una pantalla que sí puede ver.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: La franja

**Files:**
- Create: `resources/js/composables/useDeviceAlerts.js`
- Create: `resources/js/Components/Devices/DeviceAlertStrip.vue`
- Modify: `resources/js/Layouts/CajeroLayout.vue` (entre `</header>` y `<main>`)
- Modify: `resources/js/Layouts/SucursalLayout.vue` (mismo sitio)

**Interfaces:**
- Consumes: `GET /{tenant}/equipos/alertas` (tarea 7), que devuelve `{data: [{device_id, name, battery_level, severity}]}`.
- Produces: `useDeviceAlerts()` devuelve `{ alerts, worst }`, donde `alerts` es un `ref` con la lista y `worst` un `computed` con `'critical'`, `'warn'` o `null`. `DeviceAlertStrip` no recibe props.

- [ ] **Step 1: El composable**

Crear `resources/js/composables/useDeviceAlerts.js`:

```js
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Qué equipos de esta sucursal están pidiendo un cargador.
 *
 * Se pregunta cada 60 s y no por WebSocket a propósito: una batería cambia en
 * minutos, y meter un evento más en el canal `sucursal.{id}` —que ya comparten
 * varios consumidores— cuesta más de lo que ahorra.
 *
 * En pausa mientras la pestaña no está visible, como el tablero de equipos: una
 * caja con diez pestañas abiertas no tiene por qué preguntar diez veces.
 */
export function useDeviceAlerts() {
    const page = usePage();
    const alerts = ref([]);
    let timer = null;

    const slug = computed(() => page.props.auth?.tenant_slug ?? null);
    const hasBranch = computed(() => !!page.props.auth?.branch?.id);

    const worst = computed(() => {
        if (alerts.value.some((a) => a.severity === 'critical')) return 'critical';
        return alerts.value.length ? 'warn' : null;
    });

    async function load() {
        if (!slug.value || !hasBranch.value) return;
        if (document.visibilityState !== 'visible') return;

        try {
            const res = await fetch(route('equipos.alertas', slug.value), {
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) return;
            const body = await res.json();
            alerts.value = body.data ?? [];
        } catch {
            // Una franja que no se pudo refrescar no puede tumbar la caja: se
            // queda con lo último que supo y lo intenta dentro de un minuto.
        }
    }

    function onVisible() {
        if (document.visibilityState === 'visible') load();
    }

    onMounted(() => {
        load();
        timer = setInterval(load, 60000);
        document.addEventListener('visibilitychange', onVisible);
    });

    onUnmounted(() => {
        clearInterval(timer);
        document.removeEventListener('visibilitychange', onVisible);
    });

    return { alerts, worst };
}
```

- [ ] **Step 2: La franja**

Crear `resources/js/Components/Devices/DeviceAlertStrip.vue`:

```vue
<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useDeviceAlerts } from '@/composables/useDeviceAlerts';

/**
 * Un equipo se está quedando sin pila y alguien tiene que enchufarlo.
 *
 * No se puede cerrar: se va cuando lo enchufan, no cuando alguien la descarta.
 * Un aviso que se puede quitar de en medio se quita de en medio, y el equipo se
 * apaga igual a media venta.
 */
const { alerts, worst } = useDeviceAlerts();
const page = usePage();

const critical = computed(() => worst.value === 'critical');

const message = computed(() => {
    if (alerts.value.length === 0) return '';
    if (alerts.value.length > 1) {
        return `${alerts.value.length} equipos con poca batería`;
    }

    const a = alerts.value[0];

    return a.severity === 'critical'
        ? `${a.name} está al ${a.battery_level} % y se va a apagar`
        : `${a.name} va al ${a.battery_level} % y no está cargando`;
});

// El cajero tiene su propio panel de equipos, en solo lectura.
const target = computed(() => {
    const slug = page.props.auth?.tenant_slug;
    if (!slug) return null;

    return page.props.auth?.role === 'cajero'
        ? route('caja.devices.index', slug)
        : route('sucursal.devices.index', slug);
});
</script>

<template>
    <div v-if="alerts.length" role="status"
        class="flex items-center gap-2.5 border-b px-5 py-2.5 text-sm font-semibold lg:px-8"
        :class="critical ? 'border-red-200 bg-red-50 text-red-800' : 'border-amber-200 bg-amber-50 text-amber-800'">
        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
        </svg>
        <span class="min-w-0 flex-1 truncate">{{ message }}</span>
        <Link v-if="target" :href="target"
            class="shrink-0 rounded-full bg-white/70 px-3 py-1 text-xs font-bold ring-1 transition hover:bg-white"
            :class="critical ? 'ring-red-200' : 'ring-amber-200'">
            Ver equipos
        </Link>
    </div>
</template>
```

- [ ] **Step 3: Montarla en los dos layouts**

En `resources/js/Layouts/CajeroLayout.vue`, importar el componente y colocarlo entre el cierre del `<header>` y el `<VerifyEmailBanner />`:

```html
            </header>
            <DeviceAlertStrip />
            <VerifyEmailBanner />
            <main class="p-5 lg:p-8"><slot /></main>
```

En `resources/js/Layouts/SucursalLayout.vue`, lo mismo: justo después de `</header>`, antes del `<main>`.

Import en ambos: `import DeviceAlertStrip from '@/Components/Devices/DeviceAlertStrip.vue';`

- [ ] **Step 4: Construir**

Run: `npm run build`
Expected: sin errores.

- [ ] **Step 5: Verlo funcionar de verdad**

Con `composer run dev` y la base sembrada:

1. Poner un equipo en batería baja a mano:

```bash
sail artisan tinker --execute="\$d = App\Models\Device::withoutGlobalScopes()->first(); \$d->forceFill(['battery_level' => 12, 'battery_charging' => false, 'last_seen_at' => now()])->saveQuietly(); echo \$d->device_id;"
```

2. Entrar como `cajero@eltoro.test` (contraseña `password`) a `http://localhost/el-toro/caja/mesa-de-trabajo` y comprobar que la franja ámbar aparece bajo el encabezado, empuja el contenido y no tiene botón de cerrar.
3. Bajar el nivel a 6 y recargar: la franja tiene que ponerse roja y cambiar el texto.
4. Poner `battery_charging = true`: en menos de un minuto la franja se va sola, sin recargar.
5. Entrar como `sucursal@eltoro.test` y ver la misma franja en su layout.
6. Entrar como `superadmin@carniceria.test`: no debe aparecer ninguna franja ni ningún error en la consola del navegador.

- [ ] **Step 6: Commit**

```bash
git add resources/js/composables/useDeviceAlerts.js resources/js/Components/Devices/DeviceAlertStrip.vue resources/js/Layouts/CajeroLayout.vue resources/js/Layouts/SucursalLayout.vue
git commit -m "feat(equipos): la franja que no se puede cerrar

Se va cuando enchufan el equipo, no cuando alguien la descarta: un aviso
que se puede quitar de en medio se quita de en medio, y la báscula se
apaga igual a media venta. Ámbar al bajar del aviso, roja al bajar del
urgente, en la caja y en la sucursal.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: Los docs al día

**Files:**
- Modify: `docs/modulos/equipos.md`
- Modify: `docs/modulos/avisos.md`
- Modify: `docs/api/endpoints.md`
- Modify: `docs/api/hub.md`
- Modify: `docs/superpowers/specs/2026-09-19-avisos-de-bateria-design.md` (cabecera de estado)

**Por qué es una tarea y no un paso suelto:** el doc vivo de equipos lleva desde el 2026-09-17 diciendo que los clientes no mandan el latido, cuando se publicó ese mismo día. Así es como se desactualiza: dejándolo para el final de otra cosa.

- [ ] **Step 1: `equipos.md`**

- Cabecera de estado: quitar «Los clientes (Surface, Android, hubs) todavía no mandan el latido» — las cuatro entregas se publicaron el 2026-09-17 (Surface 0.4.2, Android 1.6.3, hub 1.2.1, hub Android). Decir qué hay hoy y qué falta probar en hardware.
- Sección «Estados»: añadir `battery_critical` a la tabla y sustituir «≤ 20 %» por «≤ el umbral de aviso de la sucursal».
- Sección «Avisos»: la fila de batería baja pasa a nombrar los dos umbrales configurables y las dos severidades.
- Sección «Panel»: la tarjeta «Aviso de batería» en *Sucursal → Configuración* y en *Empresa → Sucursales*, y la franja en los layouts de caja y sucursal.
- Tabla de rutas: `PUT …/configuracion/bateria` y `GET /{tenant}/equipos/alertas`.
- «Entregas siguientes»: reescribir — lo que queda es la franja en el hub y en las propias apps (entregas 3 y 4 del spec nuevo).

- [ ] **Step 2: `avisos.md`**

En la tabla «Avisos existentes», la fila de `DeviceBatteryLow` dice «Un equipo baja del 20 % sin cargar (otra vez al 10 %)». Pasa a: «Un equipo baja del umbral de su sucursal sin cargar; otra vez al umbral urgente. El payload lleva `severity`».

- [ ] **Step 3: `api/endpoints.md` y `api/hub.md`**

En la sección de `POST /api/v1/devices/heartbeat` (y su gemela del hub), añadir `battery_alert` a la respuesta de ejemplo, con una línea diciendo que es aditivo y para qué sirve. En `hub.md`, documentar además `GET /api/v1/hub/devices/alerts` con su forma de respuesta.

- [ ] **Step 4: La cabecera del spec**

En `docs/superpowers/specs/2026-09-19-avisos-de-bateria-design.md`, cambiar `**Estado:** diseño aprobado (2026-09-19) — sin implementar.` por el estado real: entregas 1 y 2 implementadas, con enlace al doc vivo, y las entregas 3 y 4 pendientes. **No dejar «sin implementar» sobre trabajo entregado**, que es la regla del CLAUDE.md que este mismo módulo ya incumplió una vez.

- [ ] **Step 5: Revisar que no quede ningún 20 escrito en los docs**

Run: `grep -rn "20 %\|20%" docs/modulos/equipos.md docs/modulos/avisos.md`
Expected: solo apariciones donde el 20 es el valor **de fábrica**, nunca como regla fija.

- [ ] **Step 6: Commit**

```bash
git add docs/modulos/equipos.md docs/modulos/avisos.md docs/api/endpoints.md docs/api/hub.md docs/superpowers/specs/2026-09-19-avisos-de-bateria-design.md
git commit -m "docs(equipos): el umbral ya no es 20, y el latido lleva dos años de retraso menos

El doc vivo decía desde el 17 de septiembre que los clientes no mandaban
el latido, el mismo día en que se publicaron las cuatro apps que lo
mandan. Al día, con los umbrales por sucursal, las dos severidades, la
franja y las rutas nuevas.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Cierre

- [ ] **La suite entera, no solo lo tocado**

Run: `composer run test`
Expected: PASS. La última corrida conocida del módulo eran 1557 tests en verde.

- [ ] **Pint sobre todo lo escrito**

Run: `./vendor/bin/pint --test`
Expected: sin diferencias.

- [ ] **Build de producción**

Run: `npm run build`
Expected: sin errores ni avisos nuevos.

- [ ] **Antes de abrir el PR: comprobar que en producción llega batería**

El registro de equipos se publicó sin prueba de campo. Antes de anunciar esto como terminado, comprobar contra la base de producción que hay filas de `devices` con `battery_level` no nulo y `last_seen_at` reciente. Si no las hay, la franja es correcta pero no se va a encender nunca, y el problema está en las apps, no aquí.

- [ ] **PR, sin fusionar**

Abrir el PR contra `main` describiendo las dos entregas. **No fusionar sin confirmación explícita.**

---

## Autorrevisión del plan

**Cobertura del spec:**

| Sección del spec | Tarea |
|---|---|
| Modelo (dos columnas, `battery_alert_level` a texto) | 1 |
| Reglas (`<=`, orden del accessor, `battery_critical` en línea) | 2 |
| Nube: `BatteryThresholds`, los tres sitios con el 20, `severity` en la notificación | 1, 2, 3 |
| Nube: `BranchDevicesQuery` con `with('branch')`, `severity` en `present()`, alertas | 4 |
| Scale API: `battery_alert` dentro de `data` | 6 |
| Nube: `BranchDeviceAlertsQuery` y las dos rutas | 7 |
| Web: la franja en los dos layouts | 8 |
| Web: las dos superficies de edición con una sola regla | 5 |
| Documentación (los cinco docs) | 9 |
| Pruebas | en cada tarea, más el cierre |

**Fuera de este plan, a propósito:** todo lo que vive en las apps cliente (entregas 3 y 4 del spec): guardar el umbral recibido, la franja local, el endpoint LAN del hub con `battery_alert` en su 202, la tabla de últimas lecturas del hub y el caso de la suite de conformidad. Son otro plan, después de ver esto funcionando en una Surface.

**Coherencia de nombres** (los mismos en todas las tareas): `BatteryThresholds::fromBranch()` / `severityFor()`; `Device::statusFor()`; severidades `warn` y `critical`; estados `battery_low` y `battery_critical`; columnas `battery_warn_threshold` y `battery_critical_threshold`; rutas `sucursal.configuracion.bateria` y `equipos.alertas`.
