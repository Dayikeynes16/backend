# Cobrar sin internet — parte 1: backend

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el backend acepte un turno abierto en el pasado sin que eso pueda duplicar dinero, y que el cajero pueda conocer los métodos de pago de su sucursal sin ser admin.

**Architecture:** Tres cambios pequeños y aislados en la superficie del hub (`/api/v1/hub/*`, Sanctum). `ShiftService::open()` acepta una hora propuesta y la **acota** entre el cierre del turno anterior y ahora, en vez de validar un rango; la idempotencia por `client_reference` evita que un reintento del hub choque con el `409`; y dos índices únicos parciales convierten en invariante de base de datos lo que hoy solo se valida en PHP. Nada de esto toca la Scale API.

**Tech Stack:** Laravel 13, PHP 8.5, PostgreSQL 18 (SQLite en tests), PHPUnit 12, Sail.

## Global Constraints

- **La Scale API (`/api/v1` con `X-Api-Key`) no se toca.** Ni un campo nuevo en `BranchResource`, ni una validación extra en `Api/SaleController`. Hay básculas Windows en producción que no se actualizan. `tests/Feature/Api/ScaleLegacyContractTest.php` lo hace cumplir y **debe seguir pasando sin modificarse**.
- **`ShiftApiTest::test_open_twice_returns_409` debe seguir pasando sin modificarse.** Es el guardrail de que la idempotencia no aflojó la regla para quien no manda `client_reference`.
- **Margen máximo de retroactividad: 6 horas** (`ShiftService::MAX_BACKDATE_HOURS`).
- **Zona horaria:** la app corre en `America/Mexico_City` (`config/app.php`); el hub manda ISO-8601 en UTC. Todo parseo de `opened_at` es explícito.
- Comandos vía Sail: `./vendor/bin/sail artisan test --compact --filter=X`. Formato: `./vendor/bin/sail bin pint --dirty`.
- Cada tarea termina con la suite completa en verde antes de commitear.

---

## File Structure

| Archivo | Responsabilidad |
|---|---|
| `database/migrations/2026_08_21_000001_add_client_reference_to_cash_register_shifts_table.php` | **Nuevo.** Columna + los dos índices únicos parciales |
| `app/Models/CashRegisterShift.php` | Añadir `client_reference` a `Fillable` |
| `app/Services/ShiftService.php` | El clamp y la idempotencia. Es el único sitio donde se decide la hora de un turno |
| `app/Http/Controllers/Api/Hub/ShiftController.php` | Validar y traducir los dos campos nuevos |
| `app/Http/Controllers/Api/Hub/ConfigController.php` | Lectura de métodos de pago abierta a cajero |
| `routes/api.php` | La ruta nueva |
| `tests/Feature/Services/ShiftOpenClampTest.php` | **Nuevo.** Los cuatro casos del clamp, en unidad |
| `tests/Feature/Api/Hub/ShiftApiTest.php` | Idempotencia y campos nuevos end-to-end |
| `tests/Feature/Api/Hub/ConfigApiTest.php` | Que el cajero pueda leer los métodos de pago |
| `docs/api/hub.md` | Documentar los dos campos y la ruta nueva |

---

### Task 1: La invariante "un turno abierto por usuario" baja a la base de datos

Hoy vive en PHP, en tres sitios y sin transacción (`ShiftService.php:38`, `Caja/ShiftController.php:28`, `Sucursal/CashShiftController.php:89`). Dos peticiones simultáneas del hub pueden crear dos turnos abiertos, y entonces `current()` —que no tiene `orderBy`— devuelve uno indefinido.

**Files:**
- Create: `database/migrations/2026_08_21_000001_add_client_reference_to_cash_register_shifts_table.php`
- Modify: `app/Models/CashRegisterShift.php` (bloque `#[Fillable([...])]`, línea 11)
- Test: `tests/Feature/Services/ShiftOpenClampTest.php`

**Interfaces:**
- Produces: columna `cash_register_shifts.client_reference` (string 64, nullable) e índices `shifts_user_open_unique` y `shifts_user_client_reference_unique`.

- [x] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Services/ShiftOpenClampTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftOpenClampTest extends TestCase
{
    use RefreshDatabase;

    private User $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'T', 'slug' => 'tenant-turnos', 'status' => 'active']);
        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Sucursal 1',
            'address' => 'A',
            'status' => 'active',
        ]);

        $this->cajero = User::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Cajero',
            'email' => 'cajero-turnos@test.test',
            'password' => bcrypt('password'),
        ]);
    }

    private function makeShift(array $attrs = []): CashRegisterShift
    {
        return CashRegisterShift::create(array_merge([
            'tenant_id' => $this->cajero->tenant_id,
            'branch_id' => $this->cajero->branch_id,
            'user_id' => $this->cajero->id,
            'opened_at' => now(),
            'opening_amount' => 0,
        ], $attrs));
    }

    public function test_la_base_impide_dos_turnos_abiertos_del_mismo_usuario(): void
    {
        $this->makeShift();

        $this->expectException(QueryException::class);

        $this->makeShift();
    }

    public function test_permite_un_turno_nuevo_cuando_el_anterior_esta_cerrado(): void
    {
        $this->makeShift(['closed_at' => now()->subHour()]);

        $abierto = $this->makeShift();

        $this->assertNull($abierto->closed_at);
        $this->assertSame(2, CashRegisterShift::where('user_id', $this->cajero->id)->count());
    }
}
```

- [x] **Step 2: Correr el test para verificar que falla**

Run: `./vendor/bin/sail artisan test --filter=ShiftOpenClampTest`
Expected: FAIL — `test_la_base_impide_dos_turnos_abiertos_del_mismo_usuario` no lanza `QueryException` porque hoy nada lo impide.

- [x] **Step 3: Escribir la migración**

Crear `database/migrations/2026_08_21_000001_add_client_reference_to_cash_register_shifts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_register_shifts', function (Blueprint $table) {
            $table->string('client_reference', 64)->nullable()->after('opening_amount');
        });

        // "Un turno abierto por usuario" vivía solo en PHP, en tres sitios y sin
        // transacción: dos peticiones simultáneas del hub podían crear dos, y
        // entonces ShiftService::current() devolvía uno indefinido.
        DB::statement(
            'CREATE UNIQUE INDEX shifts_user_open_unique '
            .'ON cash_register_shifts (user_id) '
            .'WHERE closed_at IS NULL'
        );

        // Idempotencia del hub: un reintento de apertura devuelve el mismo turno
        // en vez de crear otro. Parcial, porque la web abre turnos sin referencia.
        DB::statement(
            'CREATE UNIQUE INDEX shifts_user_client_reference_unique '
            .'ON cash_register_shifts (user_id, client_reference) '
            .'WHERE client_reference IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS shifts_user_open_unique');
        DB::statement('DROP INDEX IF EXISTS shifts_user_client_reference_unique');

        Schema::table('cash_register_shifts', function (Blueprint $table) {
            $table->dropColumn('client_reference');
        });
    }
};
```

- [x] **Step 4: Añadir la columna a `Fillable`**

En `app/Models/CashRegisterShift.php`, línea 13, dentro del atributo `#[Fillable([...])]`, cambiar:

```php
    'opened_at', 'opening_amount', 'closed_at',
```

por:

```php
    'opened_at', 'opening_amount', 'client_reference', 'closed_at',
```

- [x] **Step 5: Correr el test**

Run: `./vendor/bin/sail artisan test --filter=ShiftOpenClampTest`
Expected: PASS (2 tests).

- [x] **Step 6: Correr la suite completa**

Run: `./vendor/bin/sail artisan test --compact`
Expected: PASS. **Este paso no es una formalidad:** el índice puede romper tests que construyen dos turnos abiertos del mismo usuario a mano. Si alguno falla, el arreglo va en el test (cerrar el turno anterior o usar otro usuario), nunca quitando el índice — la invariante es correcta.

- [x] **Step 7: Commit**

> **Al ejecutarlo (2026-08-23):** el índice tumbó `ShiftCashOutCalculatorTest::test_ignores_expenses_of_other_shifts`, que fabricaba dos turnos abiertos del mismo cajero solo para tener «otro turno». Se ajustó el test a la secuencia real —el turno anterior, ya cerrado—, no el índice.

```bash
./vendor/bin/sail bin pint --dirty
git add database/migrations/2026_08_21_000001_add_client_reference_to_cash_register_shifts_table.php app/Models/CashRegisterShift.php tests/Feature/Services/ShiftOpenClampTest.php
git commit -m "feat(turnos): un turno abierto por usuario deja de ser solo una regla de PHP"
```

---

### Task 2: El servidor acota la hora de apertura

**Files:**
- Modify: `app/Services/ShiftService.php` (método `open()`, líneas 32-49)
- Test: `tests/Feature/Services/ShiftOpenClampTest.php`

**Interfaces:**
- Consumes: la columna `client_reference` de la Task 1.
- Produces:
  ```php
  ShiftService::MAX_BACKDATE_HOURS  // int, 6
  ShiftService::open(
      User $user,
      float $openingAmount = 0,
      ?CarbonInterface $requestedOpenedAt = null,
      ?string $clientReference = null
  ): CashRegisterShift
  ```
  Devuelve el turno existente (sin crear otro) si `$clientReference` ya produjo uno.

- [x] **Step 1: Escribir los tests que fallan**

Añadir a `tests/Feature/Services/ShiftOpenClampTest.php` (dentro de la clase; añadir `use App\Services\ShiftService;` y `use Illuminate\Support\Carbon;` arriba):

```php
    private function service(): ShiftService
    {
        return app(ShiftService::class);
    }

    public function test_respeta_una_hora_reciente_propuesta_por_el_hub(): void
    {
        $hace2h = now()->subHours(2);

        $shift = $this->service()->open($this->cajero, 500, $hace2h);

        $this->assertEqualsWithDelta($hace2h->timestamp, $shift->opened_at->timestamp, 2);
    }

    public function test_recorta_una_hora_demasiado_antigua_al_margen(): void
    {
        // Un reloj mal puesto no puede abrir un turno de anteayer: eso permitiría
        // recontar pagos viejos y recuperar permisos sobre comprobantes cerrados.
        $shift = $this->service()->open($this->cajero, 0, now()->subDays(2));

        $this->assertEqualsWithDelta(
            now()->subHours(ShiftService::MAX_BACKDATE_HOURS)->timestamp,
            $shift->opened_at->timestamp,
            5
        );
    }

    public function test_recorta_una_hora_futura_a_ahora(): void
    {
        $shift = $this->service()->open($this->cajero, 0, now()->addHours(3));

        $this->assertEqualsWithDelta(now()->timestamp, $shift->opened_at->timestamp, 5);
    }

    public function test_nunca_se_solapa_con_el_turno_anterior_cerrado(): void
    {
        // El caso que duplica dinero: la ventana del corte es [opened_at, closed_at]
        // filtrada por usuario, sin FK. Si el turno nuevo empieza antes de que
        // cerrara el anterior, los pagos de esa franja se cuentan dos veces.
        $cerradoHace1h = now()->subHour();
        $this->makeShift(['opened_at' => now()->subHours(5), 'closed_at' => $cerradoHace1h]);

        $shift = $this->service()->open($this->cajero, 0, now()->subHours(4));

        $this->assertEqualsWithDelta($cerradoHace1h->timestamp, $shift->opened_at->timestamp, 2);
    }

    public function test_sin_hora_propuesta_se_comporta_como_siempre(): void
    {
        $shift = $this->service()->open($this->cajero, 100);

        $this->assertEqualsWithDelta(now()->timestamp, $shift->opened_at->timestamp, 5);
    }

    public function test_la_misma_referencia_devuelve_el_mismo_turno(): void
    {
        $primero = $this->service()->open($this->cajero, 300, null, 'ref-abc');
        $segundo = $this->service()->open($this->cajero, 300, null, 'ref-abc');

        $this->assertSame($primero->id, $segundo->id);
        $this->assertSame(1, CashRegisterShift::where('user_id', $this->cajero->id)->count());
    }
```

- [x] **Step 2: Correr los tests para verificar que fallan**

Run: `./vendor/bin/sail artisan test --filter=ShiftOpenClampTest`
Expected: FAIL — `open()` hoy solo acepta dos argumentos.

- [x] **Step 3: Implementar el clamp**

En `app/Services/ShiftService.php`, añadir `use Carbon\CarbonInterface;` a los imports y reemplazar el método `open()` completo por:

```php
    /** Margen máximo hacia atrás para la hora que propone el hub. */
    public const MAX_BACKDATE_HOURS = 6;

    /**
     * Abre un turno para el usuario.
     *
     * `$requestedOpenedAt` viene del hub cuando la caja se abrió sin internet.
     * NO se acepta tal cual: se acota entre el cierre del turno anterior y ahora
     * (ver `clampOpenedAt`). `$clientReference` hace la apertura idempotente,
     * para que un reintento del hub no choque contra el 409.
     *
     * @throws ShiftAlreadyOpenException si ya hay uno abierto
     */
    public function open(
        User $user,
        float $openingAmount = 0,
        ?CarbonInterface $requestedOpenedAt = null,
        ?string $clientReference = null
    ): CashRegisterShift {
        if ($clientReference !== null) {
            $existing = CashRegisterShift::where('user_id', $user->id)
                ->where('client_reference', $clientReference)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        if ($this->current($user) !== null) {
            throw new ShiftAlreadyOpenException;
        }

        return CashRegisterShift::create([
            'tenant_id' => $user->tenant_id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'opened_at' => $this->clampOpenedAt($user, $requestedOpenedAt),
            'opening_amount' => $openingAmount,
            'client_reference' => $clientReference,
        ]);
    }

    /**
     * Acota la hora propuesta, en vez de validarla y rechazarla.
     *
     * El corte agrega los pagos por `whereBetween(created_at, [opened_at, closed_at])`
     * filtrando por usuario y sin FK al turno: dos ventanas solapadas cuentan los
     * mismos pagos dos veces. Y `opened_at` es además frontera de autorización para
     * editar comprobantes (Sucursal\PaymentReceiptController), así que una hora
     * retroactiva libre devolvería permisos sobre turnos ya cerrados.
     *
     * Resultado: min( max(propuesta, cierre anterior, ahora − 6 h), ahora ).
     */
    private function clampOpenedAt(User $user, ?CarbonInterface $requested): CarbonInterface
    {
        $now = now();

        if ($requested === null) {
            return $now;
        }

        $floor = $now->copy()->subHours(self::MAX_BACKDATE_HOURS);

        $lastClosed = CashRegisterShift::where('user_id', $user->id)
            ->whereNotNull('closed_at')
            ->orderByDesc('closed_at')
            ->first()?->closed_at;

        if ($lastClosed !== null && $lastClosed->greaterThan($floor)) {
            $floor = $lastClosed;
        }

        if ($requested->lessThan($floor)) {
            return $floor;
        }

        return $requested->greaterThan($now) ? $now : $requested;
    }
```

- [x] **Step 4: Correr los tests**

Run: `./vendor/bin/sail artisan test --filter=ShiftOpenClampTest`
Expected: PASS (8 tests).

- [x] **Step 5: Correr la suite completa**

Run: `./vendor/bin/sail artisan test --compact`
Expected: PASS. Los tres argumentos nuevos son opcionales, así que ninguna llamada existente cambia de comportamiento.

- [x] **Step 6: Commit**

```bash
./vendor/bin/sail bin pint --dirty
git add app/Services/ShiftService.php tests/Feature/Services/ShiftOpenClampTest.php
git commit -m "feat(turnos): el servidor acota la hora de apertura que propone el hub"
```

---

### Task 3: El endpoint acepta los dos campos

**Files:**
- Modify: `app/Http/Controllers/Api/Hub/ShiftController.php` (método `open()`, líneas 35-46)
- Test: `tests/Feature/Api/Hub/ShiftApiTest.php`

**Interfaces:**
- Consumes: `ShiftService::open(User, float, ?CarbonInterface, ?string)` de la Task 2.
- Produces: `POST /api/v1/hub/shift/open` acepta `opened_at` (fecha ISO-8601) y `client_reference` (string ≤64). Responde `201` al crear y `200` cuando la referencia ya produjo un turno.

- [ ] **Step 1: Escribir los tests que fallan**

Añadir a `tests/Feature/Api/Hub/ShiftApiTest.php`:

```php
    public function test_open_acepta_la_hora_real_de_apertura_del_hub(): void
    {
        $hace2h = now()->subHours(2);

        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->postJson('/api/v1/hub/shift/open', [
                'opening_amount' => 500,
                'opened_at' => $hace2h->toIso8601String(),
                'client_reference' => 'hub-ref-1',
            ])
            ->assertCreated();

        $shift = CashRegisterShift::where('user_id', $this->cajero->id)->firstOrFail();

        $this->assertEqualsWithDelta($hace2h->timestamp, $shift->opened_at->timestamp, 5);
        $this->assertSame('hub-ref-1', $shift->client_reference);
    }

    public function test_open_repetido_con_la_misma_referencia_no_crea_otro_turno(): void
    {
        $token = $this->cajero->createToken('hub')->plainTextToken;
        $payload = ['opening_amount' => 500, 'client_reference' => 'hub-ref-2'];

        $this->withToken($token)->postJson('/api/v1/hub/shift/open', $payload)->assertCreated();

        // El reintento del hub tras una respuesta perdida: no puede chocar con el 409.
        $this->withToken($token)->postJson('/api/v1/hub/shift/open', $payload)->assertOk();

        $this->assertSame(1, CashRegisterShift::where('user_id', $this->cajero->id)->count());
    }

    public function test_open_rechaza_una_fecha_invalida(): void
    {
        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->postJson('/api/v1/hub/shift/open', ['opened_at' => 'ayer por la tarde'])
            ->assertStatus(422);
    }
```

Añadir `use App\Models\CashRegisterShift;` a los imports del test si no está.

- [ ] **Step 2: Correr los tests para verificar que fallan**

Run: `./vendor/bin/sail artisan test --filter=ShiftApiTest`
Expected: FAIL — los campos se ignoran y el segundo `open` devuelve `409`.

- [ ] **Step 3: Implementar**

En `app/Http/Controllers/Api/Hub/ShiftController.php`, añadir `use Illuminate\Support\Carbon;` a los imports y reemplazar el método `open()` por:

```php
    public function open(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'opening_amount' => 'nullable|numeric|min:0',
            // La hora real de apertura cuando la caja se abrió sin internet. El
            // servicio la acota; aquí solo se comprueba que sea una fecha.
            'opened_at' => 'nullable|date',
            'client_reference' => 'nullable|string|max:64',
        ]);

        $clientReference = $validated['client_reference'] ?? null;

        // El hub manda ISO-8601 en UTC y la app trabaja en America/Mexico_City:
        // se convierte explícitamente para que no se cuelen 6 h de desfase en la
        // columna que define la ventana del dinero.
        $openedAt = isset($validated['opened_at'])
            ? Carbon::parse($validated['opened_at'])->setTimezone(config('app.timezone'))
            : null;

        $yaExistia = $clientReference !== null
            && CashRegisterShift::where('user_id', $request->user()->id)
                ->where('client_reference', $clientReference)
                ->exists();

        try {
            $shift = $this->shifts->open(
                $request->user(),
                (float) ($validated['opening_amount'] ?? 0),
                $openedAt,
                $clientReference,
            );
        } catch (ShiftAlreadyOpenException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return ShiftResource::make($shift)
            ->response()
            ->setStatusCode($yaExistia ? 200 : 201);
    }
```

Añadir `use App\Models\CashRegisterShift;` a los imports del controlador.

- [ ] **Step 4: Correr los tests**

Run: `./vendor/bin/sail artisan test --filter=ShiftApiTest`
Expected: PASS, **incluido `test_open_twice_returns_409`** — quien no manda `client_reference` sigue chocando con el 409.

- [ ] **Step 5: Correr la suite completa**

Run: `./vendor/bin/sail artisan test --compact`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/sail bin pint --dirty
git add app/Http/Controllers/Api/Hub/ShiftController.php tests/Feature/Api/Hub/ShiftApiTest.php
git commit -m "feat(hub-api): abrir turno acepta su hora real y es idempotente"
```

---

### Task 4: El cajero puede leer los métodos de pago de su sucursal

Hoy `GET /api/v1/hub/config` exige admin-sucursal (`ConfigController::ensureAdminSucursal`), y el hub solo conoce los métodos habilitados dentro de la respuesta de `sales.show` — una llamada de red que sin internet no existe. Sin esta ruta, el cobro offline solo podría ofrecer efectivo.

**Files:**
- Modify: `app/Http/Controllers/Api/Hub/ConfigController.php`
- Modify: `routes/api.php` (grupo `hub`, junto a la línea 80)
- Test: `tests/Feature/Api/Hub/ConfigApiTest.php`

**Interfaces:**
- Produces: `GET /api/v1/hub/config/payment-methods` → `{"payment_methods_enabled": ["cash","card","transfer"]}`, accesible a `cajero` y `admin-sucursal`.

- [ ] **Step 1: Escribir el test que falla**

Añadir a `tests/Feature/Api/Hub/ConfigApiTest.php`:

```php
    public function test_el_cajero_puede_leer_los_metodos_de_pago_de_su_sucursal(): void
    {
        // El hub los cachea para poder cobrar sin internet: sin esto, el cobro
        // offline solo podría ofrecer efectivo.
        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/config/payment-methods')
            ->assertOk()
            ->assertJsonStructure(['payment_methods_enabled']);
    }

    public function test_la_config_completa_sigue_siendo_solo_de_admin(): void
    {
        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/config')
            ->assertForbidden();
    }
```

Si el test no tiene un `$this->cajero`, crearlo en `setUp()` con el mismo patrón que `ShiftApiTest`.

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `./vendor/bin/sail artisan test --filter=ConfigApiTest`
Expected: FAIL con 404 — la ruta no existe.

- [ ] **Step 3: Añadir el método al controlador**

En `app/Http/Controllers/Api/Hub/ConfigController.php`, después de `index()`:

```php
    /**
     * Solo los métodos de pago habilitados, para **ambos roles**.
     *
     * El hub guarda esto en su snapshot local para poder cobrar sin internet. La
     * config completa (API keys, datos de la empresa) sigue siendo de admin: aquí
     * se expone el mínimo, no se afloja `index()`.
     *
     * NO se añade a BranchResource: ese payload lo consumen las básculas que
     * hablan directo con la nube y su forma está congelada.
     */
    public function paymentMethods(Request $request): JsonResponse
    {
        $branch = Branch::withoutGlobalScopes()->findOrFail($request->user()->branch_id);

        return response()->json([
            'payment_methods_enabled' => $branch->enabledPaymentMethods(),
        ]);
    }
```

- [ ] **Step 4: Añadir la ruta**

En `routes/api.php`, justo después de la línea 80 (`Route::get('config', ...)`):

```php
        // Lectura mínima para ambos roles: el hub la cachea para cobrar sin red.
        Route::get('config/payment-methods', [HubConfigController::class, 'paymentMethods'])->name('api.hub.config.payment-methods.index');
```

- [ ] **Step 5: Correr el test**

Run: `./vendor/bin/sail artisan test --filter=ConfigApiTest`
Expected: PASS.

- [ ] **Step 6: Correr la suite completa**

Run: `./vendor/bin/sail artisan test --compact`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/sail bin pint --dirty
git add app/Http/Controllers/Api/Hub/ConfigController.php routes/api.php tests/Feature/Api/Hub/ConfigApiTest.php
git commit -m "feat(hub-api): el cajero puede leer los metodos de pago de su sucursal"
```

---

### Task 5: Documentación

**Files:**
- Modify: `docs/api/hub.md`
- Modify: `docs/superpowers/specs/…` — no aplica; el spec vive en `carniceria-hub`

- [ ] **Step 1: Documentar los campos y la ruta**

En `docs/api/hub.md`, en la tabla del grupo de turno, añadir bajo `shift/open`:

```markdown
`POST shift/open` acepta dos campos opcionales para el hub que abrió caja sin internet: **`opened_at`** (ISO-8601, la hora real) y **`client_reference`** (≤64, idempotencia). El servidor **acota** `opened_at` entre el cierre del turno anterior y ahora, con un margen máximo de 6 h — no lo acepta tal cual, porque la ventana del corte es `[opened_at, closed_at]` sin FK y dos ventanas solapadas contarían los mismos pagos dos veces. Un reintento con la misma referencia devuelve `200` con el turno existente; sin referencia, dos aperturas siguen chocando con `409`. Ver `carniceria-hub/docs/superpowers/specs/2026-08-21-cobrar-sin-internet-design.md` §4.7.

`GET config/payment-methods` — métodos habilitados de la sucursal, para **ambos roles** (a diferencia de `GET config`, que es solo de admin). El hub lo cachea para poder cobrar sin red.
```

- [ ] **Step 2: Commit**

```bash
git add docs/api/hub.md
git commit -m "docs(api): opened_at acotado, idempotencia de turno y metodos de pago para cajero"
```

---

## Self-Review

**Cobertura del spec (§4.7 y §4.10):** el clamp con su fórmula exacta (Task 2), la idempotencia por `client_reference` (Tasks 2 y 3), la conversión de zona horaria explícita (Task 3), los dos índices parciales (Task 1), la revisión de los dos sitios que crean turnos sin pasar por el servicio (Task 1, Step 6), y la lectura de métodos de pago abierta al cajero (Task 4). Lo que el spec pide del backend queda cubierto.

**Fuera de alcance de este plan, y a propósito:** todo lo del hub (§4.1–§4.6, §4.8–§4.12) va en los planes 2 y 3. El backend no sabe que existe una cola.

**Consistencia de tipos:** `open()` mantiene la misma firma en las Tasks 2 y 3 (`User, float, ?CarbonInterface, ?string`); `clampOpenedAt` devuelve `CarbonInterface`; `MAX_BACKDATE_HOURS` se usa con el mismo nombre en el servicio y en su test.

**Nota para quien ejecute:** el paso que más probabilidad tiene de descubrir sorpresas es el Step 6 de la Task 1 — el índice único puede tumbar tests que construyen turnos a mano. Es información, no un problema: significa que la invariante no se estaba respetando en algún sitio.
