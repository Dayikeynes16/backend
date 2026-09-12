# Registro de equipos (nube y web) — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que cada báscula y hub pueda reportar su estado a la nube con un endpoint nuevo y aditivo, y que los administradores lo vean en un panel "Equipos" con avisos de batería baja, silencio, versión atrasada y equipo nuevo.

**Architecture:** Tabla `devices` con `BelongsToTenant`, identidad por `device_id` único por tenant. Un servicio (`DeviceHeartbeatService`) procesa el latido venga de la Scale API (`X-Api-Key`) o de la superficie del hub (Sanctum); otro (`DeviceAlertService`) decide avisos con marcas de deduplicación en la propia fila. Dos comandos programados: `devices:check` (cada 5 min) y `devices:sync-releases` (cada hora). Panel Inertia en Sucursal y Empresa con tarjetas por equipo.

**Tech Stack:** Laravel 13 (PHP 8.5, PostgreSQL 18 vía Sail), Spatie Permission, notificaciones `database`+`broadcast` (Reverb), Inertia 2 + Vue 3 + Tailwind, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-12-registro-de-equipos-design.md`

## Global Constraints

- **Ningún endpoint existente de `/api/v1/*` cambia.** `tests/Feature/Api/ScaleLegacyContractTest.php` no se modifica y debe seguir verde en cada tarea.
- Sin cliente (Surface/Android/hub): esta entrega es solo nube y web.
- Sin acciones remotas, sin histórico de batería, sin correo/push.
- Ventana de "sin reportar": 08:00–20:00 hora de `config('app.timezone')` (`America/Mexico_City`), los siete días.
- Latido: `device_id` requerido `[A-Za-z0-9._-]{1,64}`; `kind` en `scale_android|scale_windows|hub_windows|hub_android`; `name` ≤ 100; `battery.level` 0–100; `battery` puede ser `null` (limpia); `connection` en `cloud|hub`; `local_ip` IP válida. Lo que no viene no pisa lo guardado.
- Estados derivados: `online` < 10 min; `stale` 10–30 min; `silent` > 30 min; `battery_low` = online y `level <= 20` y no cargando; `retired` si `retired_at`.
- Avisos a `admin-sucursal` de la sucursal del equipo + `admin-empresa` del tenant; nunca al cajero. Nivel `important`. Un fallo al notificar se registra y no tumba el latido. Silenciado/baja: las marcas se actualizan, el envío se suprime.
- Batería: sin cargar, `level <= 10` y marca `!= 10` → aviso y marca `10` (un solo aviso aunque venga de `null`); `10 < level <= 20` y marca nula → aviso y marca `20`; cargando o `> 20` → limpia.
- Silencio: solo en ventana; `silencio = now - max(last_seen_at, inicio de ventana de hoy)`; `> 30 min` y `silent_alerted_at` nulo → aviso y marca; cualquier latido limpia la marca.
- Versión atrasada: `device_releases.known_since` hace más de 24 h, `app_version` menor (`version_compare`), `outdated_alert_version != version` → aviso y marca. `known_since` solo cambia cuando cambia `version`.
- Comandos: `./vendor/bin/sail artisan test --filter=X`. Pint antes del PR: `"$HOME/Library/Application Support/Herd/bin/php84" ./vendor/bin/pint --dirty`. Si aparecen fallos masivos con `column does not exist`, la base `testing` quedó de otra rama: `./vendor/bin/sail artisan migrate:fresh --database=pgsql --env=testing`.
- Commits en español, prefijo `feat:`/`test:`/`docs:`; sin `git add -A`. Rama `feat/registro-de-equipos` (ya creada).
- Textos de UI en español con acentos; identificadores en inglés.

---

## Mapa de archivos

| Archivo | Acción | Responsabilidad |
|---|---|---|
| `database/migrations/2026_09_12_100000_create_devices_table.php` | Crear | Tabla `devices` |
| `database/migrations/2026_09_12_100001_create_device_releases_table.php` | Crear | Tabla `device_releases` |
| `app/Models/Device.php` | Crear | Modelo + `BelongsToTenant` + accessor `status` |
| `app/Models/DeviceRelease.php` | Crear | Última versión publicada por `kind` |
| `config/devices.php` | Crear | URL base de feeds, ventana, umbrales |
| `app/Http/Requests/Api/DeviceHeartbeatRequest.php` | Crear | Validación del latido (compartida) |
| `app/Services/Devices/DeviceHeartbeatService.php` | Crear | Registrar/actualizar el equipo |
| `app/Services/Devices/DeviceAlertService.php` | Crear | Avisos con deduplicación |
| `app/Services/Devices/DeviceReleaseSync.php` | Crear | Leer feeds del bucket |
| `app/Services/Devices/BranchDevicesQuery.php` | Crear | Datos del panel + "sin registro" |
| `app/Http/Controllers/Api/DeviceHeartbeatController.php` | Crear | `POST /api/v1/devices/heartbeat` |
| `app/Http/Controllers/Api/Hub/DeviceHeartbeatController.php` | Crear | `POST /api/v1/hub/devices/heartbeat` |
| `app/Notifications/DeviceBatteryLow.php`, `DeviceRegistered.php`, `DeviceSilent.php`, `DeviceOutdated.php` | Crear | Los cuatro avisos |
| `app/Console/Commands/CheckDevicesCommand.php` | Crear | `devices:check` |
| `app/Console/Commands/SyncDeviceReleasesCommand.php` | Crear | `devices:sync-releases` |
| `app/Http/Controllers/Sucursal/DeviceController.php` | Crear | Panel de sucursal + acciones |
| `app/Http/Controllers/Empresa/DeviceController.php` | Crear | Panel de empresa + acciones |
| `resources/js/Components/Devices/DeviceCard.vue` | Crear | Tarjeta |
| `resources/js/Components/Devices/DeviceDetailPanel.vue` | Crear | Panel lateral con acciones |
| `resources/js/Pages/Sucursal/Equipos/Index.vue`, `resources/js/Pages/Empresa/Equipos/Index.vue` | Crear | Páginas |
| `routes/api.php`, `routes/web.php`, `bootstrap/app.php` | Modificar | Rutas y scheduler |
| `resources/js/Layouts/SucursalLayout.vue`, `EmpresaLayout.vue` | Modificar | Enlace "Equipos" |
| `docs/modulos/equipos.md`, `docs/README.md`, `docs/api/endpoints.md`, `docs/api/hub.md`, `docs/modulos/avisos.md`, `docs/arquitectura/ecosistema.md`, `CLAUDE.md` (raíz del workspace) | Crear/Modificar | Docs |

---

### Task 1: Tablas, modelos y configuración

**Files:**
- Create: `database/migrations/2026_09_12_100000_create_devices_table.php`
- Create: `database/migrations/2026_09_12_100001_create_device_releases_table.php`
- Create: `app/Models/Device.php`
- Create: `app/Models/DeviceRelease.php`
- Create: `config/devices.php`
- Test: `tests/Unit/DeviceStatusTest.php`

**Interfaces:**
- Produces: `Device` con `KINDS` (const array), `status` (accessor string), `isMuted()`, `isRetired()`, `displayName()` (`display_name ?? name`), scopes `active()` (sin `retired_at`) y `forBranch($id)`. `DeviceRelease` con pk `kind`. Config `devices.releases_base_url`, `devices.silence_window` (`['start' => '08:00', 'end' => '20:00']`), `devices.silence_minutes` (30), `devices.online_minutes` (10), `devices.outdated_hours` (24).

- [ ] **Step 1: Test del accessor `status`**

`tests/Unit/DeviceStatusTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Device;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El estado de un equipo se deriva de cuándo reportó por última vez y de su
 * batería. No toca base de datos: es una función pura sobre la fila. Extiende
 * el TestCase de Laravel (no el de PHPUnit) porque el accessor lee `config()`.
 */
class DeviceStatusTest extends TestCase
{
    private function device(array $attrs): Device
    {
        return new Device(array_merge([
            'battery_level' => 80,
            'battery_charging' => false,
            'retired_at' => null,
        ], $attrs));
    }

    public function test_online_when_seen_less_than_ten_minutes_ago(): void
    {
        $d = $this->device(['last_seen_at' => Carbon::now()->subMinutes(3)]);
        $this->assertSame('online', $d->status);
    }

    public function test_stale_between_ten_and_thirty_minutes(): void
    {
        $d = $this->device(['last_seen_at' => Carbon::now()->subMinutes(15)]);
        $this->assertSame('stale', $d->status);
    }

    public function test_silent_after_thirty_minutes(): void
    {
        $d = $this->device(['last_seen_at' => Carbon::now()->subMinutes(45)]);
        $this->assertSame('silent', $d->status);
    }

    public function test_battery_low_only_when_online_and_not_charging(): void
    {
        $low = $this->device(['last_seen_at' => Carbon::now(), 'battery_level' => 14]);
        $this->assertSame('battery_low', $low->status);

        $charging = $this->device(['last_seen_at' => Carbon::now(), 'battery_level' => 14, 'battery_charging' => true]);
        $this->assertSame('online', $charging->status);

        $silentLow = $this->device(['last_seen_at' => Carbon::now()->subHour(), 'battery_level' => 14]);
        $this->assertSame('silent', $silentLow->status);
    }

    public function test_retired_wins_over_everything(): void
    {
        $d = $this->device(['last_seen_at' => Carbon::now(), 'retired_at' => Carbon::now()]);
        $this->assertSame('retired', $d->status);
    }

    public function test_display_name_prefers_the_alias(): void
    {
        $this->assertSame('Balanza 1', $this->device(['name' => 'Balanza 1'])->displayName());
        $this->assertSame('Caja Norte', $this->device(['name' => 'Balanza 1', 'display_name' => 'Caja Norte'])->displayName());
    }
}
```

- [ ] **Step 2: Correr y ver que falla**

Run: `./vendor/bin/sail artisan test --filter=DeviceStatusTest`
Expected: FAIL — `Class "App\Models\Device" not found`.

- [ ] **Step 3: Migraciones**

`database/migrations/2026_09_12_100000_create_devices_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 64);
            $table->string('kind', 32);
            $table->string('name', 100);
            $table->string('display_name', 100)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->string('os', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedTinyInteger('battery_level')->nullable();
            $table->boolean('battery_charging')->nullable();
            $table->string('connection', 16)->nullable();
            $table->string('local_ip', 45)->nullable();
            $table->string('via', 16);
            $table->timestamp('last_seen_at');
            $table->timestamp('first_seen_at');
            $table->timestamp('muted_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->unsignedTinyInteger('battery_alert_level')->nullable();
            $table->timestamp('silent_alerted_at')->nullable();
            $table->string('outdated_alert_version', 32)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'device_id']);
            $table->index(['tenant_id', 'branch_id']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
```

`database/migrations/2026_09_12_100001_create_device_releases_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_releases', function (Blueprint $table) {
            $table->string('kind', 32)->primary();
            $table->string('version', 32);
            // Desde cuándo se conoce ESTA versión: solo cambia cuando cambia `version`.
            $table->timestamp('known_since');
            // Última consulta al feed, informativa.
            $table->timestamp('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_releases');
    }
};
```

- [ ] **Step 4: Modelos y config**

`app/Models/Device.php`:

```php
<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un equipo de sucursal (báscula o hub) que se presenta a la nube con su
 * `device_id`. La identidad es ese identificador, no el nombre ni la API key:
 * el nombre lo cambia el administrador desde la web (`display_name`) y la API
 * key es de la sucursal, no del aparato.
 */
#[Fillable([
    'tenant_id', 'branch_id', 'device_id', 'kind', 'name', 'display_name',
    'app_version', 'os', 'model', 'battery_level', 'battery_charging',
    'connection', 'local_ip', 'via', 'last_seen_at', 'first_seen_at',
    'muted_at', 'retired_at', 'battery_alert_level', 'silent_alerted_at',
    'outdated_alert_version',
])]
class Device extends Model
{
    use BelongsToTenant;

    public const KINDS = ['scale_android', 'scale_windows', 'hub_windows', 'hub_android'];

    public const KIND_LABELS = [
        'scale_android' => 'Báscula Android',
        'scale_windows' => 'Báscula Surface',
        'hub_windows' => 'Hub Windows',
        'hub_android' => 'Hub Android',
    ];

    protected function casts(): array
    {
        return [
            'battery_charging' => 'boolean',
            'last_seen_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'muted_at' => 'datetime',
            'retired_at' => 'datetime',
            'silent_alerted_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    public function isMuted(): bool
    {
        return $this->muted_at !== null;
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    public function displayName(): string
    {
        return $this->display_name ?: $this->name;
    }

    public function kindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? $this->kind;
    }

    /**
     * Estado derivado de cuándo reportó y de su batería. `retired` gana a todo.
     * `battery_low` solo si está en línea: una batería vieja de un equipo callado
     * no dice nada del presente.
     */
    protected function status(): Attribute
    {
        return Attribute::get(function (): string {
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
            if ($this->battery_level !== null && $this->battery_level <= 20 && ! $this->battery_charging) {
                return 'battery_low';
            }

            return 'online';
        });
    }
}
```

`app/Models/DeviceRelease.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Última versión publicada por tipo de equipo, leída de los feeds del bucket. */
#[Fillable(['kind', 'version', 'known_since', 'checked_at'])]
class DeviceRelease extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'kind';

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'known_since' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }
}
```

`config/devices.php`:

```php
<?php

return [
    // Los mismos feeds que consumen los actualizadores de cada app.
    'releases_base_url' => env('DEVICES_RELEASES_BASE_URL', 'https://fls-a24d1ed0-1800-420a-8316-e0bc700348ff.laravel.cloud'),

    'feeds' => [
        'scale_windows' => ['path' => '/bascula/win/latest.yml', 'format' => 'yaml', 'key' => 'version'],
        'scale_android' => ['path' => '/android/latest.json', 'format' => 'json', 'key' => 'versionName'],
        'hub_windows' => ['path' => '/hub/win/latest.yml', 'format' => 'yaml', 'key' => 'version'],
        // hub_android: sin feed todavía.
    ],

    'online_minutes' => 10,
    'silence_minutes' => 30,
    'silence_window' => ['start' => '08:00', 'end' => '20:00'],
    'outdated_hours' => 24,
];
```

- [ ] **Step 5: Migrar y correr**

Run: `./vendor/bin/sail artisan migrate && ./vendor/bin/sail artisan test --filter=DeviceStatusTest`
Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_09_12_100000_create_devices_table.php database/migrations/2026_09_12_100001_create_device_releases_table.php app/Models/Device.php app/Models/DeviceRelease.php config/devices.php tests/Unit/DeviceStatusTest.php
git commit -m "feat(equipos): tabla devices, modelo con estado derivado y releases por tipo"
```

---

### Task 2: El latido por la Scale API

**Files:**
- Create: `app/Http/Requests/Api/DeviceHeartbeatRequest.php`
- Create: `app/Services/Devices/HeartbeatResult.php`
- Create: `app/Services/Devices/DeviceHeartbeatService.php`
- Create: `app/Http/Controllers/Api/DeviceHeartbeatController.php`
- Modify: `routes/api.php` (dentro del grupo `auth.apikey`, después de `transcribe`)
- Test: `tests/Feature/Api/DeviceHeartbeatTest.php`

**Interfaces:**
- Consumes: `Device` (Task 1).
- Produces: `DeviceHeartbeatService::record(int $tenantId, int $branchId, array $data, string $via): HeartbeatResult` donde `HeartbeatResult` es un `readonly class` con `Device $device` y `bool $wasNew`. El servicio **no** notifica: llama a `DeviceAlertService::afterHeartbeat` solo cuando exista (Task 4 lo conecta); en esta tarea deja el hook como llamada a un método vacío. Respuesta JSON: `{ data: { device_id, name, display_name, status, server_time } }` con `201` al crear y `200` al actualizar.

- [ ] **Step 1: Test de característica**

`tests/Feature/Api/DeviceHeartbeatTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El latido de un equipo por la Scale API. Es un endpoint nuevo: las básculas
 * viejas nunca lo llaman y nada de lo que ellas usan cambia (eso lo vigila
 * ScaleLegacyContractTest). Aquí se prueba que el registro sea idempotente,
 * respete el tenant y no pise lo que no viene.
 */
class DeviceHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    private Branch $otherBranch;

    private string $rawKey;

    private string $otherBranchKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Centro', 'address' => 'A', 'status' => 'active']);
        $this->otherBranch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Norte', 'address' => 'B', 'status' => 'active']);

        $this->rawKey = 'csa_test_'.str_repeat('a', 20);
        ApiKey::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'name' => 'k', 'key_hash' => hash('sha256', $this->rawKey)]);

        $this->otherBranchKey = 'csa_test_'.str_repeat('b', 20);
        ApiKey::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->otherBranch->id, 'name' => 'k2', 'key_hash' => hash('sha256', $this->otherBranchKey)]);
    }

    private function beat(array $payload, ?string $key = null)
    {
        return $this->withHeader('X-Api-Key', $key ?? $this->rawKey)
            ->postJson('/api/v1/devices/heartbeat', $payload);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'device_id' => 'surface-001',
            'kind' => 'scale_windows',
            'name' => 'Mostrador Surface',
            'app_version' => '0.4.1',
            'os' => 'Windows 11',
            'model' => 'Surface Go 3',
            'battery' => ['level' => 55, 'charging' => true],
            'connection' => 'cloud',
            'local_ip' => '192.168.1.31',
        ], $overrides);
    }

    public function test_first_heartbeat_creates_the_device_with_201(): void
    {
        $this->beat($this->payload())
            ->assertCreated()
            ->assertJsonPath('data.device_id', 'surface-001')
            ->assertJsonPath('data.status', 'online');

        $device = Device::withoutGlobalScopes()->first();
        $this->assertSame($this->tenant->id, $device->tenant_id);
        $this->assertSame($this->branch->id, $device->branch_id);
        $this->assertSame('cloud', $device->via);
        $this->assertSame(55, $device->battery_level);
        $this->assertTrue($device->battery_charging);
        $this->assertNotNull($device->first_seen_at);
    }

    public function test_second_heartbeat_updates_with_200_and_does_not_duplicate(): void
    {
        $this->beat($this->payload());
        $this->beat($this->payload(['battery' => ['level' => 40, 'charging' => false]]))
            ->assertOk();

        $this->assertSame(1, Device::withoutGlobalScopes()->count());
        $this->assertSame(40, Device::withoutGlobalScopes()->first()->battery_level);
    }

    public function test_fields_not_sent_are_not_overwritten(): void
    {
        $this->beat($this->payload());
        $this->beat(['device_id' => 'surface-001', 'kind' => 'scale_windows'])->assertOk();

        $device = Device::withoutGlobalScopes()->first();
        $this->assertSame('Mostrador Surface', $device->name);
        $this->assertSame('0.4.1', $device->app_version);
        $this->assertSame(55, $device->battery_level);
    }

    public function test_explicit_null_battery_clears_it(): void
    {
        $this->beat($this->payload());
        $this->beat(['device_id' => 'surface-001', 'kind' => 'scale_windows', 'battery' => null])->assertOk();

        $device = Device::withoutGlobalScopes()->first();
        $this->assertNull($device->battery_level);
        $this->assertNull($device->battery_charging);
    }

    public function test_same_device_id_from_another_branch_moves_it(): void
    {
        $this->beat($this->payload());
        $this->beat($this->payload(), $this->otherBranchKey)->assertOk();

        $this->assertSame(1, Device::withoutGlobalScopes()->count());
        $this->assertSame($this->otherBranch->id, Device::withoutGlobalScopes()->first()->branch_id);
    }

    public function test_same_device_id_in_another_tenant_is_another_device(): void
    {
        $other = Tenant::create(['name' => 'O', 'slug' => 'o', 'status' => 'active']);
        $ob = Branch::create(['tenant_id' => $other->id, 'name' => 'X', 'address' => 'C', 'status' => 'active']);
        $key = 'csa_test_'.str_repeat('c', 20);
        ApiKey::create(['tenant_id' => $other->id, 'branch_id' => $ob->id, 'name' => 'k3', 'key_hash' => hash('sha256', $key)]);

        $this->beat($this->payload());
        $this->beat($this->payload(), $key)->assertCreated();

        $this->assertSame(2, Device::withoutGlobalScopes()->count());
    }

    public function test_a_retired_device_that_reports_again_comes_back(): void
    {
        $this->beat($this->payload());
        Device::withoutGlobalScopes()->first()->update(['retired_at' => now()]);

        $this->beat($this->payload())->assertOk();

        $this->assertNull(Device::withoutGlobalScopes()->first()->retired_at);
    }

    public function test_heartbeat_clears_the_silence_mark(): void
    {
        $this->beat($this->payload());
        Device::withoutGlobalScopes()->first()->update(['silent_alerted_at' => now()]);

        $this->beat($this->payload());

        $this->assertNull(Device::withoutGlobalScopes()->first()->silent_alerted_at);
    }

    public function test_validation(): void
    {
        $this->beat(['kind' => 'scale_windows'])->assertUnprocessable()->assertJsonValidationErrors(['device_id']);
        $this->beat(['device_id' => 'x', 'kind' => 'toaster'])->assertUnprocessable()->assertJsonValidationErrors(['kind']);
        $this->beat(['device_id' => 'malo espacio', 'kind' => 'scale_windows'])->assertUnprocessable()->assertJsonValidationErrors(['device_id']);
        $this->beat($this->payload(['battery' => ['level' => 140, 'charging' => false]]))->assertUnprocessable()->assertJsonValidationErrors(['battery.level']);
        $this->beat($this->payload(['local_ip' => 'no-es-ip']))->assertUnprocessable()->assertJsonValidationErrors(['local_ip']);
        $this->beat($this->payload(['connection' => 'wifi']))->assertUnprocessable()->assertJsonValidationErrors(['connection']);
        $this->assertSame(0, Device::withoutGlobalScopes()->count());
    }

    public function test_requires_api_key(): void
    {
        $this->postJson('/api/v1/devices/heartbeat', $this->payload())->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Correr y ver que falla**

Run: `./vendor/bin/sail artisan test --filter=DeviceHeartbeatTest`
Expected: FAIL — 404 en la ruta.

- [ ] **Step 3: Request, servicio, controlador, ruta**

`app/Http/Requests/Api/DeviceHeartbeatRequest.php`:

```php
<?php

namespace App\Http\Requests\Api;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación del latido. La misma para la Scale API y para el hub: la forma
 * del payload es una sola, cambia quién lo firma.
 */
class DeviceHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'kind' => ['required', Rule::in(Device::KINDS)],
            'name' => ['sometimes', 'string', 'max:100'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:32'],
            'os' => ['sometimes', 'nullable', 'string', 'max:100'],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'battery' => ['sometimes', 'nullable', 'array'],
            'battery.level' => ['required_with:battery', 'integer', 'between:0,100'],
            'battery.charging' => ['required_with:battery', 'boolean'],
            'connection' => ['sometimes', 'nullable', Rule::in(['cloud', 'hub'])],
            'local_ip' => ['sometimes', 'nullable', 'ip'],
        ];
    }
}
```

`app/Services/Devices/HeartbeatResult.php` (archivo propio: PSR-4, una clase por archivo):

```php
<?php

namespace App\Services\Devices;

use App\Models\Device;

final readonly class HeartbeatResult
{
    public function __construct(public Device $device, public bool $wasNew) {}
}
```

`app/Services/Devices/DeviceHeartbeatService.php`:

```php
<?php

namespace App\Services\Devices;

use App\Models\Device;
use Illuminate\Support\Facades\DB;

/**
 * Registra o actualiza un equipo a partir de su latido.
 *
 * La identidad es (tenant_id, device_id). Lo que el latido no trae no pisa lo
 * guardado; `battery: null` explícito sí limpia. Un equipo dado de baja que
 * vuelve a reportar se reactiva, y uno que reporta desde otra sucursal del
 * mismo tenant se muda de sucursal: la tablet se llevó a otro local.
 */
class DeviceHeartbeatService
{
    public function __construct(private DeviceAlertService $alerts) {}

    /**
     * @param  array<string, mixed>  $data  Payload ya validado.
     * @param  'cloud'|'hub'  $via
     */
    public function record(int $tenantId, int $branchId, array $data, string $via): HeartbeatResult
    {
        return DB::transaction(function () use ($tenantId, $branchId, $data, $via) {
            $device = Device::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('device_id', $data['device_id'])
                ->lockForUpdate()
                ->first();

            $wasNew = $device === null;
            $now = now();

            $attributes = [
                'branch_id' => $branchId,
                'kind' => $data['kind'],
                'via' => $via,
                'last_seen_at' => $now,
                'retired_at' => null,
                'silent_alerted_at' => null,
            ];

            foreach (['name', 'app_version', 'os', 'model', 'connection', 'local_ip'] as $field) {
                if (array_key_exists($field, $data)) {
                    $attributes[$field] = $data[$field];
                }
            }

            if (array_key_exists('battery', $data)) {
                $attributes['battery_level'] = $data['battery']['level'] ?? null;
                $attributes['battery_charging'] = $data['battery']['charging'] ?? null;
            }

            if ($wasNew) {
                $device = Device::withoutGlobalScopes()->create(array_merge($attributes, [
                    'tenant_id' => $tenantId,
                    'name' => $data['name'] ?? $data['device_id'],
                    'first_seen_at' => $now,
                ]));
            } else {
                $device->fill($attributes)->save();
            }

            $this->alerts->afterHeartbeat($device, $wasNew);

            return new HeartbeatResult($device->refresh(), $wasNew);
        });
    }
}
```

`app/Services/Devices/DeviceAlertService.php` (esqueleto; Task 4 lo completa):

```php
<?php

namespace App\Services\Devices;

use App\Models\Device;

/** Decide qué avisos manda un equipo. Se completa en la tarea de avisos. */
class DeviceAlertService
{
    public function afterHeartbeat(Device $device, bool $wasNew): void
    {
        // Task 4.
    }
}
```

`app/Http/Controllers/Api/DeviceHeartbeatController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DeviceHeartbeatRequest;
use App\Services\Devices\DeviceHeartbeatService;
use App\Services\Devices\HeartbeatResult;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/devices/heartbeat — la báscula se presenta y reporta su estado.
 *
 * Endpoint nuevo y aditivo: las básculas viejas no lo llaman y nada de lo que
 * ellas usan cambia. La API key dice la sucursal; el `device_id`, cuál de sus
 * básculas es.
 */
class DeviceHeartbeatController extends Controller
{
    public function store(DeviceHeartbeatRequest $request, DeviceHeartbeatService $service): JsonResponse
    {
        $result = $service->record(
            (int) $request->input('tenant_id'),
            (int) $request->input('branch_id'),
            $request->validated(),
            'cloud',
        );

        return self::respond($result);
    }

    public static function respond(HeartbeatResult $result): JsonResponse
    {
        $device = $result->device;

        return response()->json([
            'data' => [
                'device_id' => $device->device_id,
                'name' => $device->name,
                'display_name' => $device->display_name,
                'status' => $device->status,
                'server_time' => now()->toIso8601String(),
            ],
        ], $result->wasNew ? 201 : 200);
    }
}
```

En `routes/api.php`, dentro del grupo `auth.apikey`, después de la ruta `transcribe`:

```php
        // Latido de equipo (2026-09-12): la báscula se presenta y reporta batería,
        // versión y red. Endpoint NUEVO: las básculas viejas no lo llaman.
        Route::post('devices/heartbeat', [DeviceHeartbeatController::class, 'store'])->name('api.devices.heartbeat');
```

y el `use App\Http\Controllers\Api\DeviceHeartbeatController;` arriba.

Nota sobre el `tenant_id`/`branch_id`: `AuthenticateApiKey` los inyecta con `$request->merge(...)`; `validated()` no los incluye porque no están en las reglas, así que se leen aparte, como hace `Api\SaleController`.

- [ ] **Step 4: Correr**

Run: `./vendor/bin/sail artisan test --filter="DeviceHeartbeatTest|ScaleLegacyContractTest"`
Expected: PASS (10 + 5).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/Api/DeviceHeartbeatRequest.php app/Services/Devices/HeartbeatResult.php app/Services/Devices/DeviceHeartbeatService.php app/Services/Devices/DeviceAlertService.php app/Http/Controllers/Api/DeviceHeartbeatController.php routes/api.php tests/Feature/Api/DeviceHeartbeatTest.php
git commit -m "feat(equipos): latido POST /api/v1/devices/heartbeat, aditivo a la Scale API"
```

---

### Task 3: El latido por la superficie del hub

**Files:**
- Create: `app/Http/Controllers/Api/Hub/DeviceHeartbeatController.php`
- Modify: `routes/api.php` (grupo `v1/hub`)
- Test: `tests/Feature/Hub/DeviceHeartbeatTest.php`

**Interfaces:**
- Consumes: `DeviceHeartbeatRequest`, `DeviceHeartbeatService`, `Api\DeviceHeartbeatController::respond`.

- [ ] **Step 1: Test**

`tests/Feature/Hub/DeviceHeartbeatTest.php`:

```php
<?php

namespace Tests\Feature\Hub;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/** El hub se reporta a sí mismo y reenvía el latido de sus básculas: via = hub. */
class DeviceHeartbeatTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    public function test_cashier_hub_registers_itself_with_via_hub(): void
    {
        Sanctum::actingAs($this->cajero);

        $this->postJson('/api/v1/hub/devices/heartbeat', [
            'device_id' => 'hub-centro', 'kind' => 'hub_windows', 'name' => 'Hub Centro', 'app_version' => '1.2.0',
        ])->assertCreated();

        $device = Device::withoutGlobalScopes()->first();
        $this->assertSame('hub', $device->via);
        $this->assertSame($this->tenant->id, $device->tenant_id);
        $this->assertSame($this->branch->id, $device->branch_id);
    }

    public function test_hub_relays_a_scale_heartbeat(): void
    {
        Sanctum::actingAs($this->adminSucursal);

        $this->postJson('/api/v1/hub/devices/heartbeat', [
            'device_id' => 'tablet-1', 'kind' => 'scale_android', 'name' => 'Balanza 1',
            'battery' => ['level' => 30, 'charging' => false], 'connection' => 'hub',
        ])->assertCreated();

        $device = Device::withoutGlobalScopes()->first();
        $this->assertSame('hub', $device->via);
        $this->assertSame('hub', $device->connection);
    }

    public function test_admin_empresa_cannot_use_the_hub_surface(): void
    {
        Sanctum::actingAs($this->adminEmpresa);
        $this->postJson('/api/v1/hub/devices/heartbeat', ['device_id' => 'x', 'kind' => 'hub_windows'])->assertForbidden();
    }
}
```

- [ ] **Step 2: Correr y ver que falla**

Run: `./vendor/bin/sail artisan test --filter="Tests\\\\Feature\\\\Hub\\\\DeviceHeartbeatTest"`
Expected: FAIL — 404.

- [ ] **Step 3: Controlador y ruta**

`app/Http/Controllers/Api/Hub/DeviceHeartbeatController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Hub;

use App\Http\Controllers\Api\DeviceHeartbeatController as ScaleHeartbeatController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DeviceHeartbeatRequest;
use App\Services\Devices\DeviceHeartbeatService;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/hub/devices/heartbeat — el hub se reporta a sí mismo y reenvía
 * el latido de las básculas emparejadas que no tienen credenciales de nube.
 * La sucursal sale del usuario Sanctum; `via` es siempre `hub`.
 */
class DeviceHeartbeatController extends Controller
{
    public function store(DeviceHeartbeatRequest $request, DeviceHeartbeatService $service): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);

        $result = $service->record((int) $user->tenant_id, (int) $user->branch_id, $request->validated(), 'hub');

        return ScaleHeartbeatController::respond($result);
    }
}
```

En `routes/api.php`, dentro del grupo `Route::prefix('v1/hub')`, junto a `realtime/*`:

```php
        // Latido de equipo (2026-09-12): el hub se reporta y reenvía el de sus básculas.
        Route::post('devices/heartbeat', [HubDeviceHeartbeatController::class, 'store'])->name('api.hub.devices.heartbeat');
```

con `use App\Http\Controllers\Api\Hub\DeviceHeartbeatController as HubDeviceHeartbeatController;`.

- [ ] **Step 4: Correr**

Run: `./vendor/bin/sail artisan test --filter="DeviceHeartbeatTest"`
Expected: PASS (13).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/Hub/DeviceHeartbeatController.php routes/api.php tests/Feature/Hub/DeviceHeartbeatTest.php
git commit -m "feat(equipos): latido por la superficie del hub (via = hub)"
```

---

### Task 4: Avisos de batería y equipo nuevo

**Files:**
- Create: `app/Notifications/DeviceBatteryLow.php`, `app/Notifications/DeviceRegistered.php`, `app/Notifications/DeviceSilent.php`, `app/Notifications/DeviceOutdated.php`
- Modify: `app/Services/Devices/DeviceAlertService.php`
- Test: `tests/Feature/Devices/DeviceAlertsTest.php`

**Interfaces:**
- Produces: `DeviceAlertService::afterHeartbeat(Device, bool)`, `DeviceAlertService::recipients(Device): Collection<User>`, `DeviceAlertService::notify(Device, Notification): void` (respeta silenciado/baja y guarda log en fallo), `DeviceAlertService::checkSilence(Device, Carbon $now): bool` y `checkOutdated(Device, DeviceRelease, Carbon $now): bool` (los usa Task 5; devuelven si avisaron).

- [ ] **Step 1: Test**

`tests/Feature/Devices/DeviceAlertsTest.php`:

```php
<?php

namespace Tests\Feature\Devices;

use App\Models\Device;
use App\Models\DeviceRelease;
use App\Notifications\DeviceBatteryLow;
use App\Notifications\DeviceOutdated;
use App\Notifications\DeviceRegistered;
use App\Notifications\DeviceSilent;
use App\Services\Devices\DeviceAlertService;
use App\Services\Devices\DeviceHeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Los avisos no se repiten: cada uno tiene una marca en la fila del equipo que
 * se pone al avisar y se limpia cuando la situación se resuelve.
 */
class DeviceAlertsTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private DeviceHeartbeatService $heartbeats;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        Notification::fake();
        $this->heartbeats = app(DeviceHeartbeatService::class);
    }

    private function beat(int $level, bool $charging = false, string $id = 'tab-1'): Device
    {
        return $this->heartbeats->record($this->tenant->id, $this->branch->id, [
            'device_id' => $id, 'kind' => 'scale_android', 'name' => 'Balanza 1',
            'battery' => ['level' => $level, 'charging' => $charging],
        ], 'cloud')->device;
    }

    public function test_new_device_notifies_branch_admin_and_company_admin_but_not_cashier(): void
    {
        $this->beat(80);

        Notification::assertSentTo($this->adminSucursal, DeviceRegistered::class);
        Notification::assertSentTo($this->adminEmpresa, DeviceRegistered::class);
        Notification::assertNotSentTo($this->cajero, DeviceRegistered::class);

        $this->beat(80);
        Notification::assertSentToTimes($this->adminSucursal, DeviceRegistered::class, 1);
    }

    public function test_battery_alerts_once_per_threshold_and_rearm_on_charge(): void
    {
        $this->beat(25);
        Notification::assertNotSentTo($this->adminSucursal, DeviceBatteryLow::class);

        $this->beat(14);
        Notification::assertSentToTimes($this->adminSucursal, DeviceBatteryLow::class, 1);

        $this->beat(14);
        Notification::assertSentToTimes($this->adminSucursal, DeviceBatteryLow::class, 1);

        $this->beat(8);
        Notification::assertSentToTimes($this->adminSucursal, DeviceBatteryLow::class, 2);

        $this->beat(9, charging: true);
        $this->assertNull(Device::withoutGlobalScopes()->first()->battery_alert_level);

        $this->beat(14);
        Notification::assertSentToTimes($this->adminSucursal, DeviceBatteryLow::class, 3);
    }

    public function test_battery_arriving_directly_below_ten_gives_a_single_alert(): void
    {
        $this->beat(6);
        $this->beat(6);
        Notification::assertSentToTimes($this->adminSucursal, DeviceBatteryLow::class, 1);
        $this->assertSame(10, Device::withoutGlobalScopes()->first()->battery_alert_level);
    }

    public function test_muted_device_updates_marks_but_sends_nothing(): void
    {
        $device = $this->beat(80);
        $device->update(['muted_at' => now()]);

        $this->beat(5);

        Notification::assertNotSentTo($this->adminSucursal, DeviceBatteryLow::class);
        $this->assertSame(10, Device::withoutGlobalScopes()->first()->battery_alert_level);
    }

    public function test_silence_alerts_once_inside_the_window(): void
    {
        $device = $this->beat(80);
        $service = app(DeviceAlertService::class);

        // Sábado 08:20: lleva 20 min de ventana callado → todavía no.
        $device->update(['last_seen_at' => Carbon::parse('2026-09-11 21:00', config('app.timezone'))]);
        $this->assertFalse($service->checkSilence($device->refresh(), Carbon::parse('2026-09-12 08:20', config('app.timezone'))));

        // 08:35 → avisa.
        $this->assertTrue($service->checkSilence($device->refresh(), Carbon::parse('2026-09-12 08:35', config('app.timezone'))));
        Notification::assertSentToTimes($this->adminSucursal, DeviceSilent::class, 1);

        // Domingo → sigue marcado, no repite.
        $this->assertFalse($service->checkSilence($device->refresh(), Carbon::parse('2026-09-13 09:00', config('app.timezone'))));
        Notification::assertSentToTimes($this->adminSucursal, DeviceSilent::class, 1);

        // Fuera de ventana nunca avisa.
        $device->update(['silent_alerted_at' => null]);
        $this->assertFalse($service->checkSilence($device->refresh(), Carbon::parse('2026-09-13 23:00', config('app.timezone'))));
    }

    public function test_outdated_alerts_once_per_version_after_a_day(): void
    {
        $device = $this->beat(80);
        $device->update(['app_version' => '1.6.1']);
        $service = app(DeviceAlertService::class);

        $fresh = DeviceRelease::create(['kind' => 'scale_android', 'version' => '1.6.2', 'known_since' => now()->subHours(2), 'checked_at' => now()]);
        $this->assertFalse($service->checkOutdated($device->refresh(), $fresh, now()));

        $fresh->update(['known_since' => now()->subHours(30)]);
        $this->assertTrue($service->checkOutdated($device->refresh(), $fresh->refresh(), now()));
        $this->assertFalse($service->checkOutdated($device->refresh(), $fresh->refresh(), now()));
        Notification::assertSentToTimes($this->adminSucursal, DeviceOutdated::class, 1);

        $device->update(['app_version' => '1.6.2']);
        $this->assertFalse($service->checkOutdated($device->refresh(), $fresh->refresh(), now()));
    }

    public function test_alerts_go_only_to_the_devices_branch(): void
    {
        $otherAdmin = $this->makeUser('otra@test.local', 'admin-sucursal', $this->secondBranch->id);
        $this->beat(80);
        Notification::assertNotSentTo($otherAdmin, DeviceRegistered::class);
    }
}
```

- [ ] **Step 2: Correr y ver que falla**

Run: `./vendor/bin/sail artisan test --filter=DeviceAlertsTest`
Expected: FAIL — clases de notificación no existen.

- [ ] **Step 3: Las cuatro notificaciones**

Las cuatro comparten forma. `app/Notifications/DeviceBatteryLow.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Device;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/** Un equipo está por descargarse. Persistente: si nadie estaba mirando, lo encuentra al entrar. */
class DeviceBatteryLow extends Notification
{
    use Queueable;

    public function __construct(public Device $device) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $level = $this->device->battery_level;

        return [
            'type' => 'device.battery.low',
            'level' => 'important',
            'title' => 'Batería baja',
            'body' => "{$this->device->displayName()} está al {$level} % y no está cargando.",
            'device_id' => $this->device->device_id,
            'branch_id' => $this->device->branch_id,
            'name' => $this->device->displayName(),
            'battery_level' => $level,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
```

`app/Notifications/DeviceRegistered.php` — igual, con:

```php
        return [
            'type' => 'device.registered',
            'level' => 'important',
            'title' => 'Equipo nuevo',
            'body' => "Un equipo nuevo reporta en {$this->device->branch->name}: {$this->device->displayName()} ({$this->device->kindLabel()}, {$this->device->app_version}).",
            'device_id' => $this->device->device_id,
            'branch_id' => $this->device->branch_id,
            'name' => $this->device->displayName(),
        ];
```

`app/Notifications/DeviceSilent.php` — igual, con:

```php
        return [
            'type' => 'device.silent',
            'level' => 'important',
            'title' => 'Equipo sin reportar',
            'body' => "{$this->device->displayName()} no reporta desde las {$this->device->last_seen_at->timezone(config('app.timezone'))->format('H:i')}.",
            'device_id' => $this->device->device_id,
            'branch_id' => $this->device->branch_id,
            'name' => $this->device->displayName(),
            'last_seen_at' => $this->device->last_seen_at->toIso8601String(),
        ];
```

`app/Notifications/DeviceOutdated.php` — constructor `(public Device $device, public string $latest)`, con:

```php
        return [
            'type' => 'device.outdated',
            'level' => 'important',
            'title' => 'Versión atrasada',
            'body' => "{$this->device->displayName()} sigue en {$this->device->app_version}; la {$this->latest} lleva un día publicada.",
            'device_id' => $this->device->device_id,
            'branch_id' => $this->device->branch_id,
            'name' => $this->device->displayName(),
            'app_version' => $this->device->app_version,
            'latest_version' => $this->latest,
        ];
```

- [ ] **Step 4: El servicio de avisos completo**

Reemplazar `app/Services/Devices/DeviceAlertService.php`:

```php
<?php

namespace App\Services\Devices;

use App\Models\Device;
use App\Models\DeviceRelease;
use App\Models\User;
use App\Notifications\DeviceBatteryLow;
use App\Notifications\DeviceOutdated;
use App\Notifications\DeviceRegistered;
use App\Notifications\DeviceSilent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Qué avisos manda un equipo y a quién.
 *
 * Cada aviso tiene una marca en la fila del equipo (`battery_alert_level`,
 * `silent_alerted_at`, `outdated_alert_version`) que se pone al avisar y se
 * limpia cuando la situación se resuelve: así no se repite. Un equipo
 * silenciado o dado de baja actualiza las marcas igual pero no envía nada; de
 * lo contrario, al reactivarlo llegaría de golpe todo lo que pasó.
 *
 * Nada de esto puede tumbar un latido: un fallo al notificar se registra y se
 * sigue.
 */
class DeviceAlertService
{
    public function afterHeartbeat(Device $device, bool $wasNew): void
    {
        if ($wasNew) {
            $this->notify($device, new DeviceRegistered($device));
        }

        $this->checkBattery($device);
    }

    private function checkBattery(Device $device): void
    {
        $level = $device->battery_level;

        if ($level === null || $device->battery_charging || $level > 20) {
            if ($device->battery_alert_level !== null) {
                $device->forceFill(['battery_alert_level' => null])->saveQuietly();
            }

            return;
        }

        $threshold = $level <= 10 ? 10 : 20;
        if ($device->battery_alert_level === $threshold || ($threshold === 20 && $device->battery_alert_level === 10)) {
            return;
        }

        $device->forceFill(['battery_alert_level' => $threshold])->saveQuietly();
        $this->notify($device, new DeviceBatteryLow($device));
    }

    /**
     * Silencio medido dentro de la ventana: una tablet apagada de noche no avisa
     * a las 08:00, avisa cuando lleva 30 min de ventana sin reportar. Una vez por
     * episodio; cualquier latido limpia la marca.
     *
     * @return bool si avisó
     */
    public function checkSilence(Device $device, Carbon $now): bool
    {
        if ($device->silent_alerted_at !== null || $device->isRetired()) {
            return false;
        }

        $tz = config('app.timezone');
        $local = $now->copy()->timezone($tz);
        $start = $local->copy()->setTimeFromTimeString(config('devices.silence_window.start'));
        $end = $local->copy()->setTimeFromTimeString(config('devices.silence_window.end'));

        if ($local->lt($start) || $local->gt($end)) {
            return false;
        }

        $since = $device->last_seen_at->copy()->timezone($tz)->max($start);
        if ($since->diffInMinutes($local) < config('devices.silence_minutes')) {
            return false;
        }

        $device->forceFill(['silent_alerted_at' => $now])->saveQuietly();
        $this->notify($device, new DeviceSilent($device));

        return true;
    }

    /** @return bool si avisó */
    public function checkOutdated(Device $device, DeviceRelease $release, Carbon $now): bool
    {
        if ($device->isRetired() || $device->app_version === null) {
            return false;
        }
        if (version_compare($device->app_version, $release->version, '>=')) {
            return false;
        }
        if ($release->known_since->diffInHours($now) < config('devices.outdated_hours')) {
            return false;
        }
        if ($device->outdated_alert_version === $release->version) {
            return false;
        }

        $device->forceFill(['outdated_alert_version' => $release->version])->saveQuietly();
        $this->notify($device, new DeviceOutdated($device, $release->version));

        return true;
    }

    /**
     * Administradores de la sucursal del equipo más los de la empresa. Nunca el
     * cajero: al mostrador no se le interrumpe por esto.
     *
     * @return Collection<int, User>
     */
    public function recipients(Device $device): Collection
    {
        return User::query()
            ->where('tenant_id', $device->tenant_id)
            ->where(function ($q) use ($device) {
                $q->where(function ($b) use ($device) {
                    $b->where('branch_id', $device->branch_id)
                        ->whereHas('roles', fn ($r) => $r->where('name', 'admin-sucursal'));
                })->orWhereHas('roles', fn ($r) => $r->where('name', 'admin-empresa'));
            })
            ->get();
    }

    public function notify(Device $device, Notification $notification): void
    {
        if ($device->isMuted() || $device->isRetired()) {
            return;
        }

        try {
            foreach ($this->recipients($device) as $user) {
                $user->notify($notification);
            }
        } catch (Throwable $e) {
            Log::warning('No se pudo avisar sobre un equipo', [
                'device_id' => $device->device_id,
                'notification' => $notification::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

- [ ] **Step 5: Correr**

Run: `./vendor/bin/sail artisan test --filter="DeviceAlertsTest|DeviceHeartbeatTest|NotificationInboxTest"`
Expected: PASS. Si `NotificationInboxTest` falla por el `Notification::fake()`, no aplica: cada test tiene su propio proceso; debe seguir verde.

- [ ] **Step 6: Commit**

```bash
git add app/Notifications/DeviceBatteryLow.php app/Notifications/DeviceRegistered.php app/Notifications/DeviceSilent.php app/Notifications/DeviceOutdated.php app/Services/Devices/DeviceAlertService.php tests/Feature/Devices/DeviceAlertsTest.php
git commit -m "feat(equipos): avisos de batería baja, equipo nuevo, silencio y versión atrasada sin repetirse"
```

---

### Task 5: Comandos programados

**Files:**
- Create: `app/Services/Devices/DeviceReleaseSync.php`
- Create: `app/Console/Commands/SyncDeviceReleasesCommand.php`
- Create: `app/Console/Commands/CheckDevicesCommand.php`
- Modify: `bootstrap/app.php` (`withSchedule`)
- Test: `tests/Feature/Console/DeviceCommandsTest.php`

**Interfaces:**
- Consumes: `DeviceAlertService::checkSilence/checkOutdated` (Task 4), `DeviceRelease`, `config('devices.feeds')`.
- Produces: `devices:sync-releases` y `devices:check`.

- [ ] **Step 1: Test**

`tests/Feature/Console/DeviceCommandsTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Models\Device;
use App\Models\DeviceRelease;
use App\Notifications\DeviceOutdated;
use App\Notifications\DeviceSilent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class DeviceCommandsTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        Notification::fake();
    }

    private function device(array $attrs = []): Device
    {
        return Device::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'device_id' => 'd-'.uniqid(), 'kind' => 'scale_android', 'name' => 'Balanza',
            'app_version' => '1.6.1', 'via' => 'cloud',
            'last_seen_at' => now(), 'first_seen_at' => now(),
        ], $attrs));
    }

    public function test_sync_releases_reads_yaml_and_json_feeds(): void
    {
        Http::fake([
            '*/bascula/win/latest.yml' => Http::response("version: 0.4.1\nfiles:\n  - url: x.exe\n"),
            '*/android/latest.json' => Http::response(['versionName' => '1.6.2', 'versionCode' => 10602]),
            '*/hub/win/latest.yml' => Http::response('', 500),
        ]);

        $this->artisan('devices:sync-releases')->assertSuccessful();

        $this->assertSame('0.4.1', DeviceRelease::find('scale_windows')->version);
        $this->assertSame('1.6.2', DeviceRelease::find('scale_android')->version);
        $this->assertNull(DeviceRelease::find('hub_windows'));
    }

    public function test_sync_releases_keeps_known_since_when_version_is_unchanged(): void
    {
        $old = Carbon::now()->subDays(3);
        DeviceRelease::create(['kind' => 'scale_android', 'version' => '1.6.2', 'known_since' => $old, 'checked_at' => $old]);
        Http::fake(['*/android/latest.json' => Http::response(['versionName' => '1.6.2']), '*' => Http::response('', 500)]);

        $this->artisan('devices:sync-releases');

        $release = DeviceRelease::find('scale_android');
        $this->assertTrue($release->known_since->equalTo($old));
        $this->assertTrue($release->checked_at->gt($old));

        Http::fake(['*/android/latest.json' => Http::response(['versionName' => '1.7.0']), '*' => Http::response('', 500)]);
        $this->artisan('devices:sync-releases');
        $this->assertTrue(DeviceRelease::find('scale_android')->known_since->gt($old));
    }

    public function test_broken_feed_keeps_the_previous_version(): void
    {
        DeviceRelease::create(['kind' => 'scale_windows', 'version' => '0.4.0', 'known_since' => now(), 'checked_at' => now()]);
        Http::fake(['*' => Http::response('', 500)]);

        $this->artisan('devices:sync-releases')->assertSuccessful();

        $this->assertSame('0.4.0', DeviceRelease::find('scale_windows')->version);
    }

    public function test_check_alerts_silent_and_outdated_devices_once(): void
    {
        $tz = config('app.timezone');
        Carbon::setTestNow(Carbon::parse('2026-09-12 08:35', $tz));

        $silent = $this->device(['last_seen_at' => Carbon::parse('2026-09-11 21:00', $tz)]);
        $outdated = $this->device(['app_version' => '1.6.1']);
        DeviceRelease::create(['kind' => 'scale_android', 'version' => '1.6.2', 'known_since' => now()->subDays(2), 'checked_at' => now()]);

        $this->artisan('devices:check')->assertSuccessful();
        $this->artisan('devices:check')->assertSuccessful();

        Notification::assertSentToTimes($this->adminSucursal, DeviceSilent::class, 1);
        Notification::assertSentToTimes($this->adminSucursal, DeviceOutdated::class, 2); // silent también está atrasado
        $this->assertNotNull($silent->refresh()->silent_alerted_at);
        $this->assertSame('1.6.2', $outdated->refresh()->outdated_alert_version);

        Carbon::setTestNow();
    }

    public function test_check_ignores_retired_devices(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-12 10:00', config('app.timezone')));
        $this->device(['last_seen_at' => now()->subHours(3), 'retired_at' => now()]);

        $this->artisan('devices:check');

        Notification::assertNothingSent();
        Carbon::setTestNow();
    }
}
```

- [ ] **Step 2: Correr y ver que falla**

Run: `./vendor/bin/sail artisan test --filter=DeviceCommandsTest`
Expected: FAIL — comandos no definidos.

- [ ] **Step 3: Sincronizador de feeds**

`app/Services/Devices/DeviceReleaseSync.php`:

```php
<?php

namespace App\Services\Devices;

use App\Models\DeviceRelease;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lee la última versión publicada de cada tipo de equipo de los feeds del
 * bucket (los mismos que consumen los actualizadores). Un feed caído no borra
 * lo que ya se sabía. `known_since` solo se mueve cuando cambia la versión:
 * es "desde cuándo se conoce esta versión", no "cuándo se consultó".
 *
 * @return array<string, string|null> versión por kind (null si falló)
 */
class DeviceReleaseSync
{
    public function run(): array
    {
        $base = rtrim(config('devices.releases_base_url'), '/');
        $result = [];

        foreach (config('devices.feeds') as $kind => $feed) {
            $version = $this->fetch($base.$feed['path'], $feed['format'], $feed['key']);
            $result[$kind] = $version;

            if ($version === null) {
                continue;
            }

            $current = DeviceRelease::find($kind);
            $now = now();

            if ($current === null) {
                DeviceRelease::create(['kind' => $kind, 'version' => $version, 'known_since' => $now, 'checked_at' => $now]);
            } elseif ($current->version !== $version) {
                $current->update(['version' => $version, 'known_since' => $now, 'checked_at' => $now]);
            } else {
                $current->update(['checked_at' => $now]);
            }
        }

        return $result;
    }

    private function fetch(string $url, string $format, string $key): ?string
    {
        try {
            $response = Http::timeout(10)->get($url);
            if (! $response->successful()) {
                Log::info('Feed de versiones no disponible', ['url' => $url, 'status' => $response->status()]);

                return null;
            }

            if ($format === 'json') {
                $value = $response->json($key);
            } else {
                // latest.yml de electron-builder: la primera línea "version: X.Y.Z".
                preg_match('/^'.preg_quote($key, '/').':\s*([^\s#]+)/m', $response->body(), $m);
                $value = $m[1] ?? null;
            }

            return is_string($value) && $value !== '' ? trim($value, "\"'") : null;
        } catch (Throwable $e) {
            Log::info('Feed de versiones con error', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
```

- [ ] **Step 4: Comandos y scheduler**

`app/Console/Commands/SyncDeviceReleasesCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\Devices\DeviceReleaseSync;
use Illuminate\Console\Command;

/** Cada hora: qué versión está publicada para cada tipo de equipo. */
class SyncDeviceReleasesCommand extends Command
{
    protected $signature = 'devices:sync-releases';

    protected $description = 'Lee de los feeds del bucket la última versión publicada por tipo de equipo.';

    public function handle(DeviceReleaseSync $sync): int
    {
        foreach ($sync->run() as $kind => $version) {
            $this->line(sprintf('%-14s %s', $kind, $version ?? '(sin respuesta)'));
        }

        return self::SUCCESS;
    }
}
```

`app/Console/Commands/CheckDevicesCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\DeviceRelease;
use App\Services\Devices\DeviceAlertService;
use Illuminate\Console\Command;

/**
 * Cada 5 minutos: equipos callados dentro del horario y versiones atrasadas.
 * Las marcas de la fila garantizan un aviso por episodio; aquí solo se recorre.
 */
class CheckDevicesCommand extends Command
{
    protected $signature = 'devices:check';

    protected $description = 'Avisa de equipos sin reportar y de versiones atrasadas.';

    public function handle(DeviceAlertService $alerts): int
    {
        $now = now();
        $releases = DeviceRelease::all()->keyBy('kind');
        $silent = 0;
        $outdated = 0;

        Device::withoutGlobalScopes()
            ->whereNull('retired_at')
            ->with('branch')
            ->chunkById(200, function ($devices) use ($alerts, $releases, $now, &$silent, &$outdated) {
                foreach ($devices as $device) {
                    if ($alerts->checkSilence($device, $now)) {
                        $silent++;
                    }
                    $release = $releases->get($device->kind);
                    if ($release && $alerts->checkOutdated($device, $release, $now)) {
                        $outdated++;
                    }
                }
            });

        $this->line("Sin reportar: {$silent} · Versión atrasada: {$outdated}");

        return self::SUCCESS;
    }
}
```

En `bootstrap/app.php`, dentro de `withSchedule`, después de `ExpireAiDraftsCommand`:

```php
        // Registro de equipos (2026-09-12): silencio y versión atrasada cada 5 min;
        // la última versión publicada por tipo, cada hora.
        $schedule->command(CheckDevicesCommand::class)->everyFiveMinutes();
        $schedule->command(SyncDeviceReleasesCommand::class)->hourly();
```

con los `use App\Console\Commands\CheckDevicesCommand;` y `use App\Console\Commands\SyncDeviceReleasesCommand;`.

Nota: en `checkSilence` la notificación `DeviceSilent` usa `$device->branch` para nada, pero `DeviceRegistered` sí (`branch->name`); el `with('branch')` evita N+1 en el comando.

- [ ] **Step 5: Correr**

Run: `./vendor/bin/sail artisan test --filter="DeviceCommandsTest|DeviceAlertsTest"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Devices/DeviceReleaseSync.php app/Console/Commands/SyncDeviceReleasesCommand.php app/Console/Commands/CheckDevicesCommand.php bootstrap/app.php tests/Feature/Console/DeviceCommandsTest.php
git commit -m "feat(equipos): devices:check cada 5 min y devices:sync-releases cada hora"
```

---

### Task 6: Panel de sucursal (backend)

**Files:**
- Create: `app/Services/Devices/BranchDevicesQuery.php`
- Create: `app/Http/Controllers/Sucursal/DeviceController.php`
- Modify: `routes/web.php` (grupo `sucursal`, después de `api-keys`)
- Test: `tests/Feature/Sucursal/DevicesTest.php`

**Interfaces:**
- Produces: `BranchDevicesQuery::forBranch(int $branchId): array{devices: array, alerts: array, unregistered: array}` — cada device como array con `id, device_id, kind, kind_label, name, display_name, shown_name, app_version, os, model, battery_level, battery_charging, connection, via, local_ip, status, last_seen_at, first_seen_at, last_sale_at, muted, outdated, release_version`. Rutas `sucursal.devices.index|update|mute|destroy`.

- [ ] **Step 1: Test**

`tests/Feature/Sucursal/DevicesTest.php`:

```php
<?php

namespace Tests\Feature\Sucursal;

use App\Models\Device;
use App\Models\DeviceRelease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class DevicesTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    private function device(array $attrs = []): Device
    {
        return Device::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'device_id' => 'd-'.uniqid(), 'kind' => 'scale_android', 'name' => 'Balanza 1',
            'app_version' => '1.6.1', 'via' => 'cloud', 'battery_level' => 14, 'battery_charging' => false,
            'last_seen_at' => now(), 'first_seen_at' => now(),
        ], $attrs));
    }

    public function test_branch_admin_sees_only_own_devices_with_status_and_release(): void
    {
        $mine = $this->device();
        $this->device(['branch_id' => $this->secondBranch->id, 'name' => 'Ajena']);
        DeviceRelease::create(['kind' => 'scale_android', 'version' => '1.6.2', 'known_since' => now(), 'checked_at' => now()]);

        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.devices.index', $this->tenant->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sucursal/Equipos/Index')
                ->has('devices', 1)
                ->where('devices.0.id', $mine->id)
                ->where('devices.0.status', 'battery_low')
                ->where('devices.0.outdated', true)
                ->where('devices.0.release_version', '1.6.2')
                ->has('alerts', 1)
            );
    }

    public function test_unregistered_scales_come_from_sales(): void
    {
        $this->makeCompletedSale(['origin_name' => 'Bascula vieja']);
        $this->makeCompletedSale(['origin_name' => 'Balanza 1']);
        $this->device(['name' => 'Balanza 1']);

        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.devices.index', $this->tenant->slug))
            ->assertInertia(fn (Assert $page) => $page
                ->has('unregistered', 1)
                ->where('unregistered.0.name', 'Bascula vieja')
                ->where('devices.0.last_sale_at', fn ($v) => $v !== null)
            );
    }

    public function test_rename_mute_and_retire(): void
    {
        $device = $this->device();
        $slug = $this->tenant->slug;

        $this->actingAs($this->adminSucursal)
            ->patch(route('sucursal.devices.update', [$slug, $device]), ['display_name' => 'Caja Norte'])
            ->assertRedirect();
        $this->assertSame('Caja Norte', $device->refresh()->display_name);

        $this->actingAs($this->adminSucursal)
            ->patch(route('sucursal.devices.update', [$slug, $device]), ['display_name' => ''])
            ->assertRedirect();
        $this->assertNull($device->refresh()->display_name);

        $this->actingAs($this->adminSucursal)->patch(route('sucursal.devices.mute', [$slug, $device]))->assertRedirect();
        $this->assertNotNull($device->refresh()->muted_at);
        $this->actingAs($this->adminSucursal)->patch(route('sucursal.devices.mute', [$slug, $device]))->assertRedirect();
        $this->assertNull($device->refresh()->muted_at);

        $this->actingAs($this->adminSucursal)->delete(route('sucursal.devices.destroy', [$slug, $device]))->assertRedirect();
        $this->assertNotNull($device->refresh()->retired_at);
    }

    public function test_another_branch_device_is_not_found(): void
    {
        $foreign = $this->device(['branch_id' => $this->secondBranch->id]);

        $this->actingAs($this->adminSucursal)
            ->patch(route('sucursal.devices.update', [$this->tenant->slug, $foreign]), ['display_name' => 'x'])
            ->assertNotFound();
    }

    public function test_cashier_cannot_open_the_panel(): void
    {
        $this->actingAs($this->cajero)->get(route('sucursal.devices.index', $this->tenant->slug))->assertForbidden();
    }
}
```

Nota: `makeCompletedSale` está en `SeedsMetricsData`; acepta `$attrs` que se mezclan en `Sale::create`, así que `origin_name` entra.

- [ ] **Step 2: Correr y ver que falla**

Run: `./vendor/bin/sail artisan test --filter="Tests\\\\Feature\\\\Sucursal\\\\DevicesTest"`
Expected: FAIL — ruta no definida.

- [ ] **Step 3: La consulta del panel**

`app/Services/Devices/BranchDevicesQuery.php`:

```php
<?php

namespace App\Services\Devices;

use App\Models\Device;
use App\Models\DeviceRelease;
use App\Models\Sale;
use Illuminate\Support\Collection;

/**
 * Todo lo que el panel de una sucursal necesita, ya en forma de arrays.
 *
 * "Sin registro" son básculas viejas que no mandan latido: se deducen de los
 * `origin_name` de las ventas de 30 días que no coinciden con ningún equipo
 * registrado. Es una deducción, no una identidad.
 */
class BranchDevicesQuery
{
    /** @return array{devices: array<int, array<string, mixed>>, alerts: array<int, array<string, mixed>>, unregistered: array<int, array<string, mixed>>} */
    public function forBranch(int $branchId): array
    {
        $releases = DeviceRelease::all()->keyBy('kind');

        $lastSales = Sale::query()
            ->accountable()
            ->where('branch_id', $branchId)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('origin_name')
            ->selectRaw('origin_name, max(created_at) as last_sale_at')
            ->groupBy('origin_name')
            ->pluck('last_sale_at', 'origin_name');

        $devices = Device::query()
            ->active()
            ->forBranch($branchId)
            ->orderBy('name')
            ->get()
            ->map(fn (Device $d) => $this->present($d, $releases->get($d->kind), $lastSales->get($d->name)))
            ->values();

        $alerts = $devices->filter(fn ($d) => in_array($d['status'], ['battery_low', 'silent'], true) || $d['outdated'])->values();

        $registeredNames = $devices->pluck('name')->all();
        $unregistered = $lastSales
            ->reject(fn ($at, $name) => in_array($name, $registeredNames, true))
            ->map(fn ($at, $name) => ['name' => $name, 'last_sale_at' => (string) $at])
            ->values();

        return ['devices' => $devices->all(), 'alerts' => $alerts->all(), 'unregistered' => $unregistered->all()];
    }

    /** @return array<string, mixed> */
    public function present(Device $d, ?DeviceRelease $release, ?string $lastSaleAt): array
    {
        $outdated = $release !== null && $d->app_version !== null
            && version_compare($d->app_version, $release->version, '<');

        return [
            'id' => $d->id,
            'device_id' => $d->device_id,
            'kind' => $d->kind,
            'kind_label' => $d->kindLabel(),
            'name' => $d->name,
            'display_name' => $d->display_name,
            'shown_name' => $d->displayName(),
            'app_version' => $d->app_version,
            'os' => $d->os,
            'model' => $d->model,
            'battery_level' => $d->battery_level,
            'battery_charging' => $d->battery_charging,
            'connection' => $d->connection,
            'via' => $d->via,
            'local_ip' => $d->local_ip,
            'status' => $d->status,
            'last_seen_at' => $d->last_seen_at->toIso8601String(),
            'first_seen_at' => $d->first_seen_at->toIso8601String(),
            'last_sale_at' => $lastSaleAt,
            'muted' => $d->isMuted(),
            'outdated' => $outdated,
            'release_version' => $release?->version,
        ];
    }
}
```

- [ ] **Step 4: Controlador y rutas**

`app/Http/Controllers/Sucursal/DeviceController.php`:

```php
<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Devices\BranchDevicesQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/** Equipos de la sucursal del administrador: ver, renombrar, silenciar, dar de baja. */
class DeviceController extends Controller
{
    public function index(BranchDevicesQuery $query): Response
    {
        $data = $query->forBranch((int) Auth::user()->branch_id);

        return Inertia::render('Sucursal/Equipos/Index', array_merge($data, [
            'tenant' => app('tenant'),
            'branchName' => Auth::user()->branch?->name,
        ]));
    }

    public function update(Request $request, Device $device): RedirectResponse
    {
        $this->own($device);
        $validated = $request->validate(['display_name' => ['nullable', 'string', 'max:100']]);
        $device->update(['display_name' => trim((string) ($validated['display_name'] ?? '')) ?: null]);

        return back();
    }

    public function mute(Device $device): RedirectResponse
    {
        $this->own($device);
        $device->update(['muted_at' => $device->isMuted() ? null : now()]);

        return back();
    }

    public function destroy(Device $device): RedirectResponse
    {
        $this->own($device);
        $device->update(['retired_at' => now()]);

        return back();
    }

    private function own(Device $device): void
    {
        abort_unless($device->branch_id === Auth::user()->branch_id, 404);
    }
}
```

En `routes/web.php`, grupo `sucursal`, después de las rutas `api-keys`:

```php
                // Equipos de la sucursal (básculas y hubs que reportan su estado).
                Route::get('equipos', [SucursalDeviceController::class, 'index'])->name('devices.index');
                Route::patch('equipos/{device}', [SucursalDeviceController::class, 'update'])->name('devices.update');
                Route::patch('equipos/{device}/silencio', [SucursalDeviceController::class, 'mute'])->name('devices.mute');
                Route::delete('equipos/{device}', [SucursalDeviceController::class, 'destroy'])->name('devices.destroy');
```

con `use App\Http\Controllers\Sucursal\DeviceController as SucursalDeviceController;`.

El binding `{device}` resuelve `Device` con el `TenantScope` activo (el tenant ya está resuelto por `resolve.tenant`), así que un equipo de otro tenant da 404 sin código extra; el de otra sucursal lo corta `own()`.

- [ ] **Step 5: Correr**

Run: `./vendor/bin/sail artisan test --filter="Tests\\\\Feature\\\\Sucursal\\\\DevicesTest"`
Expected: PASS (5).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Devices/BranchDevicesQuery.php app/Http/Controllers/Sucursal/DeviceController.php routes/web.php tests/Feature/Sucursal/DevicesTest.php
git commit -m "feat(equipos): panel de sucursal con renombrar, silenciar y dar de baja"
```

---

### Task 7: Panel de empresa (backend)

**Files:**
- Create: `app/Http/Controllers/Empresa/DeviceController.php`
- Modify: `routes/web.php` (grupo `empresa`, después de `sucursales`)
- Test: `tests/Feature/Empresa/DevicesTest.php`

**Interfaces:**
- Consumes: `BranchDevicesQuery::forBranch`.
- Produces: rutas `empresa.devices.index|update|mute|destroy`; prop `branches: [{id, name, devices, alerts, unregistered}]`.

- [ ] **Step 1: Test**

`tests/Feature/Empresa/DevicesTest.php`:

```php
<?php

namespace Tests\Feature\Empresa;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class DevicesTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    private function device(int $branchId, string $name): Device
    {
        return Device::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $branchId,
            'device_id' => 'd-'.uniqid(), 'kind' => 'hub_windows', 'name' => $name, 'via' => 'hub',
            'last_seen_at' => now(), 'first_seen_at' => now(),
        ]);
    }

    public function test_company_admin_sees_all_branches_grouped(): void
    {
        $this->device($this->branch->id, 'Hub Centro');
        $this->device($this->secondBranch->id, 'Hub Norte');

        $this->actingAs($this->adminEmpresa)
            ->get(route('empresa.devices.index', $this->tenant->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Empresa/Equipos/Index')
                ->has('branches', 2)
                ->where('branches.0.name', 'Sucursal 1')
                ->has('branches.0.devices', 1)
                ->where('branches.1.devices.0.name', 'Hub Norte')
            );
    }

    public function test_company_admin_can_rename_any_device_of_the_tenant(): void
    {
        $device = $this->device($this->secondBranch->id, 'Hub Norte');

        $this->actingAs($this->adminEmpresa)
            ->patch(route('empresa.devices.update', [$this->tenant->slug, $device]), ['display_name' => 'Norte'])
            ->assertRedirect();

        $this->assertSame('Norte', $device->refresh()->display_name);
    }

    public function test_branch_admin_cannot_open_the_company_panel(): void
    {
        $this->actingAs($this->adminSucursal)->get(route('empresa.devices.index', $this->tenant->slug))->assertForbidden();
    }
}
```

- [ ] **Step 2: Correr y ver que falla**

Run: `./vendor/bin/sail artisan test --filter="Tests\\\\Feature\\\\Empresa\\\\DevicesTest"`
Expected: FAIL — ruta no definida.

- [ ] **Step 3: Controlador y rutas**

`app/Http/Controllers/Empresa/DeviceController.php`:

```php
<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Device;
use App\Services\Devices\BranchDevicesQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Todos los equipos de la empresa, agrupados por sucursal. Mismas acciones que en Sucursal. */
class DeviceController extends Controller
{
    public function index(BranchDevicesQuery $query): Response
    {
        $branches = Branch::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $b) => array_merge(['id' => $b->id, 'name' => $b->name], $query->forBranch($b->id)))
            ->values();

        return Inertia::render('Empresa/Equipos/Index', [
            'branches' => $branches,
            'tenant' => app('tenant'),
        ]);
    }

    public function update(Request $request, Device $device): RedirectResponse
    {
        $validated = $request->validate(['display_name' => ['nullable', 'string', 'max:100']]);
        $device->update(['display_name' => trim((string) ($validated['display_name'] ?? '')) ?: null]);

        return back();
    }

    public function mute(Device $device): RedirectResponse
    {
        $device->update(['muted_at' => $device->isMuted() ? null : now()]);

        return back();
    }

    public function destroy(Device $device): RedirectResponse
    {
        $device->update(['retired_at' => now()]);

        return back();
    }
}
```

En `routes/web.php`, grupo `empresa`, después de `Route::resource('sucursales', …)`:

```php
                // Equipos de todas las sucursales (básculas y hubs que reportan su estado).
                Route::get('equipos', [EmpresaDeviceController::class, 'index'])->name('devices.index');
                Route::patch('equipos/{device}', [EmpresaDeviceController::class, 'update'])->name('devices.update');
                Route::patch('equipos/{device}/silencio', [EmpresaDeviceController::class, 'mute'])->name('devices.mute');
                Route::delete('equipos/{device}', [EmpresaDeviceController::class, 'destroy'])->name('devices.destroy');
```

con `use App\Http\Controllers\Empresa\DeviceController as EmpresaDeviceController;`.

- [ ] **Step 4: Correr**

Run: `./vendor/bin/sail artisan test --filter="DevicesTest"`
Expected: PASS (8).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Empresa/DeviceController.php routes/web.php tests/Feature/Empresa/DevicesTest.php
git commit -m "feat(equipos): panel de empresa agrupado por sucursal"
```

---

### Task 8: Páginas Vue

**Files:**
- Create: `resources/js/Components/Devices/DeviceCard.vue`
- Create: `resources/js/Components/Devices/DeviceDetailPanel.vue`
- Create: `resources/js/Components/Devices/DevicesBoard.vue` (franja de avisos + rejilla + sin registro; lo usan las dos páginas)
- Create: `resources/js/Pages/Sucursal/Equipos/Index.vue`
- Create: `resources/js/Pages/Empresa/Equipos/Index.vue`
- Modify: `resources/js/Layouts/SucursalLayout.vue:37` (antes de "Configuracion") y `resources/js/Layouts/EmpresaLayout.vue:17` (después de "Sucursales")

**Interfaces:**
- Consumes: props de Tasks 6 y 7. Rutas `sucursal.devices.*` / `empresa.devices.*` con `route(name, [tenant.slug, id])`.
- Produces: `DevicesBoard` con props `devices`, `alerts`, `unregistered`, `routes: { update, mute, destroy }` (nombres de ruta), `tenantSlug`.

- [ ] **Step 1: Tarjeta**

`resources/js/Components/Devices/DeviceCard.vue`:

```vue
<script setup>
import { computed } from 'vue';

const props = defineProps({
    device: { type: Object, required: true },
});

const emit = defineEmits(['open']);

const STATUS = {
    online: { label: 'En línea', chip: 'bg-emerald-100 text-emerald-800', ring: 'ring-slate-200' },
    battery_low: { label: 'Batería baja', chip: 'bg-amber-100 text-amber-800', ring: 'ring-amber-300' },
    stale: { label: 'Hace un rato', chip: 'bg-amber-100 text-amber-800', ring: 'ring-slate-200' },
    silent: { label: 'Sin reportar', chip: 'bg-red-100 text-red-800', ring: 'ring-red-200' },
    retired: { label: 'De baja', chip: 'bg-slate-200 text-slate-700', ring: 'ring-slate-200' },
};

const status = computed(() => STATUS[props.device.status] ?? STATUS.online);

const relative = (iso) => {
    if (!iso) return '—';
    const mins = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (mins < 1) return 'ahora mismo';
    if (mins < 60) return `hace ${mins} min`;
    const h = Math.floor(mins / 60);
    if (h < 24) return `hace ${h} h ${mins % 60} min`;
    return `hace ${Math.floor(h / 24)} d`;
};

const batteryColor = computed(() => {
    const l = props.device.battery_level;
    if (l === null || l === undefined) return 'bg-slate-300';
    if (l <= 20) return 'bg-red-500';
    if (l <= 40) return 'bg-amber-500';
    return 'bg-emerald-500';
});

const energy = computed(() => {
    const d = props.device;
    if (d.battery_level === null || d.battery_level === undefined) return 'sin batería';
    return `${d.battery_level} % · ${d.battery_charging ? 'cargando' : 'sin cargar'}`;
});
</script>

<template>
    <button
        type="button"
        class="w-full rounded-xl bg-white p-4 text-left shadow-sm ring-1 transition hover:shadow-md"
        :class="[status.ring, device.status === 'silent' ? 'opacity-80' : '']"
        @click="emit('open', device)"
    >
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="truncate text-sm font-bold text-slate-800">
                    {{ device.shown_name }}
                    <span v-if="device.muted" class="ml-1 text-xs font-normal text-slate-400" title="Avisos silenciados">🔕</span>
                </p>
                <p class="text-xs text-slate-500">{{ device.kind_label }}</p>
            </div>
            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold" :class="status.chip">{{ status.label }}</span>
        </div>

        <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
            <dt class="text-slate-400">Versión</dt>
            <dd class="text-slate-700">
                {{ device.app_version ?? '—' }}
                <span v-if="device.outdated" class="ml-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800">atrasada · hay {{ device.release_version }}</span>
                <span v-else-if="device.release_version" class="ml-1 rounded-full bg-blue-100 px-1.5 py-0.5 text-[10px] font-semibold text-blue-800">al día</span>
            </dd>
            <dt class="text-slate-400">Energía</dt>
            <dd class="flex items-center gap-2 text-slate-700">
                <span class="relative inline-block h-3 w-8 rounded-sm border border-slate-500">
                    <span class="block h-full rounded-sm" :class="batteryColor" :style="{ width: (device.battery_level ?? 0) + '%' }" />
                </span>
                {{ energy }}
            </dd>
            <dt class="text-slate-400">Reportó</dt>
            <dd class="text-slate-700">
                {{ relative(device.last_seen_at) }}
                <span v-if="device.connection" class="text-slate-400"> · vende contra {{ device.connection === 'hub' ? 'el hub' : 'la nube' }}</span>
            </dd>
            <dt class="text-slate-400">Última venta</dt>
            <dd class="text-slate-700">{{ relative(device.last_sale_at) }}</dd>
        </dl>
    </button>
</template>
```

- [ ] **Step 2: Panel lateral de detalle**

`resources/js/Components/Devices/DeviceDetailPanel.vue`:

```vue
<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';

const props = defineProps({
    device: { type: Object, default: null },
    routes: { type: Object, required: true }, // { update, mute, destroy }
    tenantSlug: { type: String, required: true },
});

const emit = defineEmits(['close']);

const alias = ref('');
const confirmRetire = ref(false);

watch(() => props.device, (d) => { alias.value = d?.display_name ?? ''; }, { immediate: true });

const url = (name) => route(name, [props.tenantSlug, props.device.id]);

const rename = () => router.patch(url(props.routes.update), { display_name: alias.value }, { preserveScroll: true });
const toggleMute = () => router.patch(url(props.routes.mute), {}, { preserveScroll: true });
const retire = () => {
    confirmRetire.value = false;
    router.delete(url(props.routes.destroy), { preserveScroll: true, onSuccess: () => emit('close') });
};

const fmt = (iso) => (iso ? new Date(iso).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' }) : '—');
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition duration-150" leave-active-class="transition duration-100" enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="device" class="fixed inset-0 z-40 flex justify-end bg-black/30" @click.self="emit('close')">
                <aside class="h-full w-full max-w-md overflow-y-auto bg-white p-6 shadow-2xl">
                    <div class="flex items-start justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-slate-800">{{ device.shown_name }}</h2>
                            <p class="text-sm text-slate-500">{{ device.kind_label }} · {{ device.device_id }}</p>
                        </div>
                        <button type="button" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100" @click="emit('close')">✕</button>
                    </div>

                    <dl class="mt-6 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                        <dt class="text-slate-400">Nombre en el equipo</dt><dd class="text-slate-700">{{ device.name }}</dd>
                        <dt class="text-slate-400">Versión</dt><dd class="text-slate-700">{{ device.app_version ?? '—' }}<span v-if="device.release_version" class="text-slate-400"> · publicada {{ device.release_version }}</span></dd>
                        <dt class="text-slate-400">Sistema</dt><dd class="text-slate-700">{{ device.os ?? '—' }}</dd>
                        <dt class="text-slate-400">Modelo</dt><dd class="text-slate-700">{{ device.model ?? '—' }}</dd>
                        <dt class="text-slate-400">Batería</dt><dd class="text-slate-700">{{ device.battery_level === null ? 'sin batería' : device.battery_level + ' % · ' + (device.battery_charging ? 'cargando' : 'sin cargar') }}</dd>
                        <dt class="text-slate-400">Vende contra</dt><dd class="text-slate-700">{{ device.connection === 'hub' ? 'el hub' : device.connection === 'cloud' ? 'la nube' : '—' }}</dd>
                        <dt class="text-slate-400">Reporta por</dt><dd class="text-slate-700">{{ device.via === 'hub' ? 'el hub' : 'la nube' }}</dd>
                        <dt class="text-slate-400">IP local</dt><dd class="font-mono text-slate-700">{{ device.local_ip ?? '—' }}</dd>
                        <dt class="text-slate-400">Último reporte</dt><dd class="text-slate-700">{{ fmt(device.last_seen_at) }}</dd>
                        <dt class="text-slate-400">Primer reporte</dt><dd class="text-slate-700">{{ fmt(device.first_seen_at) }}</dd>
                        <dt class="text-slate-400">Última venta</dt><dd class="text-slate-700">{{ fmt(device.last_sale_at) }}</dd>
                    </dl>

                    <div class="mt-6 border-t border-slate-100 pt-5">
                        <label class="block text-sm font-medium text-slate-700">Nombre en la web</label>
                        <div class="mt-1.5 flex gap-2">
                            <input v-model="alias" type="text" maxlength="100" :placeholder="device.name" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            <button type="button" class="rounded-lg bg-indigo-600 px-4 text-sm font-bold text-white hover:bg-indigo-500" @click="rename">Guardar</button>
                        </div>
                        <p class="mt-1 text-xs text-slate-400">Vacío vuelve al nombre que manda el equipo.</p>
                    </div>

                    <div class="mt-6 flex flex-col gap-2">
                        <button type="button" class="rounded-lg border-2 border-slate-200 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50" @click="toggleMute">
                            {{ device.muted ? 'Reactivar avisos' : 'Silenciar avisos' }}
                        </button>
                        <button type="button" class="rounded-lg border-2 border-red-200 py-2 text-sm font-bold text-red-700 hover:bg-red-50" @click="confirmRetire = true">
                            Dar de baja
                        </button>
                        <p class="text-xs text-slate-400">Dar de baja lo oculta y deja de avisar. Si vuelve a reportar, reaparece solo.</p>
                    </div>
                </aside>
            </div>
        </Transition>

        <ConfirmDialog
            v-if="confirmRetire"
            title="¿Dar de baja este equipo?"
            :message="`${device?.shown_name} desaparecerá del panel y dejará de generar avisos. Si vuelve a reportar, reaparece.`"
            confirm-label="Dar de baja"
            @confirm="retire"
            @cancel="confirmRetire = false"
        />
    </Teleport>
</template>
```

Antes de usar `ConfirmDialog`, leer `resources/js/Components/ConfirmDialog.vue` y ajustar los nombres de props/eventos a los que realmente expone (si sus props se llaman distinto, usar los suyos; no crear otro diálogo).

- [ ] **Step 3: Tablero compartido**

`resources/js/Components/Devices/DevicesBoard.vue`:

```vue
<script setup>
import { ref } from 'vue';
import DeviceCard from '@/Components/Devices/DeviceCard.vue';
import DeviceDetailPanel from '@/Components/Devices/DeviceDetailPanel.vue';

const props = defineProps({
    devices: { type: Array, default: () => [] },
    alerts: { type: Array, default: () => [] },
    unregistered: { type: Array, default: () => [] },
    routes: { type: Object, required: true },
    tenantSlug: { type: String, required: true },
});

const open = ref(null);

const alertText = (d) => {
    if (d.status === 'battery_low') return `${d.shown_name} está al ${d.battery_level} % y no está cargando.`;
    if (d.status === 'silent') return `${d.shown_name} no reporta desde hace rato.`;
    if (d.outdated) return `${d.shown_name} sigue en ${d.app_version}; hay ${d.release_version}.`;
    return d.shown_name;
};

const fmt = (iso) => (iso ? new Date(iso).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' }) : '—');
</script>

<template>
    <div class="space-y-6">
        <div v-if="alerts.length" class="rounded-xl border border-amber-200 bg-amber-50 px-5 py-4">
            <p class="text-sm font-bold text-amber-800">Requieren atención</p>
            <ul class="mt-1 space-y-1 text-sm text-amber-700">
                <li v-for="d in alerts" :key="d.id">
                    <button type="button" class="text-left hover:underline" @click="open = d">⚠ {{ alertText(d) }}</button>
                </li>
            </ul>
        </div>

        <div v-if="devices.length === 0 && unregistered.length === 0" class="rounded-xl bg-white p-10 text-center text-sm text-slate-400 ring-1 ring-slate-200">
            Todavía no hay equipos reportando. Las básculas y hubs actualizados aparecen aquí solos.
        </div>

        <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <DeviceCard v-for="d in devices" :key="d.id" :device="d" @open="open = $event" />
        </div>

        <div v-if="unregistered.length" class="rounded-xl bg-white p-5 ring-1 ring-slate-200">
            <p class="text-sm font-bold text-slate-700">Sin registro</p>
            <p class="mt-0.5 text-xs text-slate-400">Básculas que solo mandan ventas y no reportan su estado (versiones antiguas). Se deducen del nombre que escriben en cada venta.</p>
            <ul class="mt-3 divide-y divide-slate-100 text-sm">
                <li v-for="u in unregistered" :key="u.name" class="flex items-center justify-between py-2">
                    <span class="font-medium text-slate-700">{{ u.name }}</span>
                    <span class="text-xs text-slate-400">última venta {{ fmt(u.last_sale_at) }}</span>
                </li>
            </ul>
        </div>

        <DeviceDetailPanel :device="open" :routes="routes" :tenant-slug="tenantSlug" @close="open = null" />
    </div>
</template>
```

- [ ] **Step 4: Las dos páginas y el menú**

`resources/js/Pages/Sucursal/Equipos/Index.vue`:

```vue
<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import DevicesBoard from '@/Components/Devices/DevicesBoard.vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    devices: Array,
    alerts: Array,
    unregistered: Array,
    tenant: Object,
    branchName: String,
});

const routes = { update: 'sucursal.devices.update', mute: 'sucursal.devices.mute', destroy: 'sucursal.devices.destroy' };
</script>

<template>
    <Head title="Equipos" />
    <SucursalLayout>
        <div class="mx-auto max-w-6xl space-y-6 p-6">
            <div class="flex items-end justify-between">
                <div>
                    <h1 class="text-xl font-bold text-slate-800">Equipos</h1>
                    <p class="text-sm text-slate-500">{{ branchName }} · {{ devices.length }} registrados</p>
                </div>
            </div>
            <DevicesBoard :devices="devices" :alerts="alerts" :unregistered="unregistered" :routes="routes" :tenant-slug="tenant.slug" />
        </div>
    </SucursalLayout>
</template>
```

`resources/js/Pages/Empresa/Equipos/Index.vue`:

```vue
<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import DevicesBoard from '@/Components/Devices/DevicesBoard.vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    branches: Array,
    tenant: Object,
});

const routes = { update: 'empresa.devices.update', mute: 'empresa.devices.mute', destroy: 'empresa.devices.destroy' };
</script>

<template>
    <Head title="Equipos" />
    <EmpresaLayout>
        <div class="mx-auto max-w-6xl space-y-10 p-6">
            <h1 class="text-xl font-bold text-slate-800">Equipos</h1>
            <section v-for="b in branches" :key="b.id" class="space-y-4">
                <h2 class="text-sm font-bold uppercase tracking-wider text-slate-400">{{ b.name }} · {{ b.devices.length }} registrados</h2>
                <DevicesBoard :devices="b.devices" :alerts="b.alerts" :unregistered="b.unregistered" :routes="routes" :tenant-slug="tenant.slug" />
            </section>
        </div>
    </EmpresaLayout>
</template>
```

Menús. En `resources/js/Layouts/SucursalLayout.vue`, antes de la entrada `Configuracion`:

```js
    { label: 'Equipos', route: 'sucursal.devices.index', match: 'sucursal.devices', icon: 'config' },
```

En `resources/js/Layouts/EmpresaLayout.vue`, después de `Sucursales`:

```js
    { label: 'Equipos', route: 'empresa.devices.index', match: 'empresa.devices', icon: 'config' },
```

(Si el layout tiene un mapa de iconos con un nombre más adecuado que `config`, como `dispositivos` o `devices`, usarlo; si no, `config` está garantizado que existe.)

- [ ] **Step 5: Compilar y ver a mano**

Run: `./vendor/bin/sail npm run build`
Expected: compila sin errores. Luego, con `./vendor/bin/sail artisan migrate:fresh --seed` y sesión como `sucursal@eltoro.test`, abrir `/el-toro/sucursal/equipos`: sin equipos, se ve el vacío y (si hay ventas con `origin_name`) la lista "Sin registro". Insertar un equipo con tinker:

```bash
./vendor/bin/sail artisan tinker --execute="App\Models\Device::withoutGlobalScopes()->create(['tenant_id'=>1,'branch_id'=>1,'device_id'=>'demo-1','kind'=>'scale_android','name'=>'Balanza 1','app_version'=>'1.6.1','battery_level'=>14,'battery_charging'=>false,'connection'=>'cloud','via'=>'cloud','last_seen_at'=>now(),'first_seen_at'=>now()]);"
```

y comprobar tarjeta ámbar "Batería baja", franja de avisos, panel lateral, renombrar, silenciar, dar de baja. Repetir como `admin@eltoro.test` en `/el-toro/empresa/equipos`.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/Devices resources/js/Pages/Sucursal/Equipos resources/js/Pages/Empresa/Equipos resources/js/Layouts/SucursalLayout.vue resources/js/Layouts/EmpresaLayout.vue
git commit -m "feat(equipos): panel de tarjetas en Sucursal y Empresa con detalle y acciones"
```

---

### Task 9: Documentación y verificación final

**Files:**
- Create: `docs/modulos/equipos.md`
- Modify: `docs/README.md` (índice + "Estado del sistema"), `docs/api/endpoints.md`, `docs/api/hub.md`, `docs/modulos/avisos.md`, `docs/arquitectura/ecosistema.md`, `docs/superpowers/specs/2026-09-12-registro-de-equipos-design.md` (`Estado`), `/Users/sebas/Documents/version 2/CLAUDE.md` (lista `BelongsToTenant`)

- [ ] **Step 1: `docs/modulos/equipos.md`**

```markdown
# Equipos (registro de básculas y hubs)

Qué equipos hay en cada sucursal, si están encendidos, qué versión tienen y cuánta batería les queda. Y avisos cuando algo de eso va mal.

## Por qué existe

La nube solo conocía la API key (la sucursal) y el `origin_name` de cada venta. Para saber qué versión tenía cada Surface había que preguntar equipo por equipo. El 2026-09-12 se pidió control desde la web y aviso al bajar del 20 % de batería.

## Cómo funciona

1. Cada equipo manda un **latido** `POST /api/v1/devices/heartbeat` (misma `X-Api-Key`) o, los hubs, `POST /api/v1/hub/devices/heartbeat` (Sanctum). Es un endpoint **nuevo y aditivo**: las básculas viejas no lo llaman y nada de lo que usan cambia (`ScaleLegacyContractTest`).
2. La identidad es `(tenant_id, device_id)`; el `device_id` lo genera la app una vez. El nombre es un dato más; desde la web se le pone un alias (`display_name`).
3. `DeviceHeartbeatService` registra/actualiza (lo que no viene no pisa; `battery: null` limpia; un dado de baja que vuelve se reactiva; si reporta desde otra sucursal, se muda).
4. `DeviceAlertService` decide avisos con marcas en la fila (`battery_alert_level`, `silent_alerted_at`, `outdated_alert_version`) para no repetir. Silenciado o de baja: marcas sí, envío no.
5. `devices:check` (cada 5 min) revisa silencio y versión atrasada; `devices:sync-releases` (cada hora) lee los feeds del bucket (`config/devices.php`).

## Estados

| Estado | Regla |
|---|---|
| `online` | reportó hace < 10 min |
| `battery_low` | online y ≤ 20 % sin cargar |
| `stale` | 10–30 min |
| `silent` | > 30 min |
| `retired` | dado de baja |

El panel muestra la realidad siempre; la ventana 08:00–20:00 solo decide cuándo se **notifica** el silencio.

## Avisos

| Aviso | `type` | Cuándo |
|---|---|---|
| Batería baja | `device.battery.low` | ≤ 20 % sin cargar; otra vez al ≤ 10 %; se rearma al cargar |
| Equipo nuevo | `device.registered` | primer latido de un `device_id` |
| Sin reportar | `device.silent` | > 30 min de silencio dentro de la ventana, una vez por episodio |
| Versión atrasada | `device.outdated` | versión menor a la publicada hace > 24 h, una vez por versión |

Destinatarios: `admin-sucursal` de la sucursal + `admin-empresa`. Nunca el cajero.

## Panel

`/{tenant}/sucursal/equipos` y `/{tenant}/empresa/equipos` (agrupado por sucursal). Tarjetas por equipo; panel lateral con renombrar, silenciar y dar de baja. Abajo, "sin registro": `origin_name` de ventas de 30 días sin equipo registrado con ese nombre.

## Entregas siguientes

Surface (`bascula`), Android (`bascula-android`) y hubs mandan el latido: al arrancar, cada 5 min, al cruzar 20 %/10 % y al cambiar la carga. Ver el spec `docs/superpowers/specs/2026-09-12-registro-de-equipos-design.md`.
```

- [ ] **Step 2: Los demás docs**

- `docs/README.md`: entrada `modulos/equipos.md` en el índice de módulos y una fila en "Estado del sistema": `| Equipos (registro de básculas/hubs, batería, avisos) | ✅ Nube y web (2026-09-12); clientes pendientes ([doc](modulos/equipos.md)) |`.
- `docs/api/endpoints.md`: sección "Latido de equipo — `POST /api/v1/devices/heartbeat`" con el payload del spec, la respuesta y la nota: "Añadido el 2026-09-12. **Aditivo:** las básculas que no lo llaman no cambian en nada."
- `docs/api/hub.md`: fila `POST devices/heartbeat` en la tabla de endpoints y actualizar el conteo (112 → 113).
- `docs/modulos/avisos.md`: las cuatro filas nuevas en "Avisos existentes".
- `docs/arquitectura/ecosistema.md`: en la Scale API y en la superficie del hub, el latido como capacidad nueva con la regla de que es aditivo; y que los clientes lo mandan en sus propias entregas.
- Spec: `**Estado:** Implementado en nube y web (plan: docs/superpowers/plans/2026-09-12-registro-de-equipos.md); clientes pendientes`.
- `/Users/sebas/Documents/version 2/CLAUDE.md`: en la lista `BelongsToTenant` añadir `Device` y cambiar "(28, verified 2026-08-23)" por "(29, verified 2026-09-12)". Ese archivo no es de este repo: editarlo pero **no** commitearlo aquí (no está en el repo).

- [ ] **Step 3: Verificación final**

```bash
"$HOME/Library/Application Support/Herd/bin/php84" ./vendor/bin/pint --dirty
./vendor/bin/sail artisan test
./vendor/bin/sail npm run build
```

Expected: Pint sin cambios pendientes (o commitear los que haga), toda la suite verde, build OK.

- [ ] **Step 4: Commit**

```bash
git add docs/modulos/equipos.md docs/README.md docs/api/endpoints.md docs/api/hub.md docs/modulos/avisos.md docs/arquitectura/ecosistema.md docs/superpowers/specs/2026-09-12-registro-de-equipos-design.md
git commit -m "docs: registro de equipos (módulo, endpoints, avisos, ecosistema)"
```
