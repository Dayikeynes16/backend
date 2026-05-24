# Agenda y recordatorios (v1) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir el módulo "Agenda": tareas/eventos/notas manuales (una tabla) + alertas derivadas (cuentas por pagar, fiados, turno sin cerrar), con vistas Hoy/Calendario/Alertas, recordatorio in-app, WhatsApp manual y export `.ics`. Sin cron en v1.

**Architecture:** Una tabla `agenda_items` con `BelongsToTenant`. Visibilidad por rol vía un scope `visibleTo` + `AgendaItemPolicy`. Servicios sin estado: `AgendaCalendarService` (expande recurrencia en memoria), `AgendaAlertService` (lee compras/cobranza, no escribe), `IcsBuilder` (.ics con VALARM). Un `AgendaController` compartido bajo `/{tenant}/agenda` para los 3 roles. Frontend Inertia/Vue con pestañas Hoy/Calendario/Alertas + widget de dashboard. Reverb para toast al asignar.

**Tech Stack:** Laravel 13, PostgreSQL 18, Inertia v2 + Vue 3, Reverb/Echo, Tailwind v3, PHPUnit 12, Laravel Sail.

Referencia de diseño: `docs/superpowers/specs/2026-05-24-agenda-recordatorios-design.md`.

**Convenciones a respetar (verificadas en el repo):**
- Modelos: `use BelongsToTenant, SoftDeletes;` + atributo `#[Fillable([...])]` + `protected function casts(): array`.
- Tests: extienden `Tests\TestCase`, usan `RefreshDatabase` y el concern `Tests\Concerns\SeedsMetricsData` (expone `$this->tenant`, `$this->branch`, `$this->secondBranch`, `$this->adminEmpresa`, `$this->adminSucursal`, `$this->cajero`, `makeUser()`, `makeCreditSale()`).
- Rutas tenant: grupos con `role:<roles>|superadmin`, prefijo `{tenant}`, middleware `resolve.tenant`,`ensure.tenant`.
- Eventos broadcast: `implements ShouldBroadcastNow`, `broadcastOn(): Channel` → `new PrivateChannel("...")`.
- Correr todo con Sail: `vendor/bin/sail ...`. Tras tocar PHP: `vendor/bin/sail bin pint --dirty --format agent`.

---

## File Structure

**Backend (crear):**
- `database/migrations/2026_05_24_120000_create_agenda_items_table.php` — tabla.
- `app/Enums/AgendaItemType.php` · `AgendaScope.php` · `AgendaRecurrence.php` · `AgendaPriority.php`.
- `app/Models/AgendaItem.php` — modelo + scope `visibleTo`.
- `app/Policies/AgendaItemPolicy.php` — autorización.
- `app/Services/Agenda/AgendaCalendarService.php` — expansión de recurrencia.
- `app/Services/Agenda/AgendaAlertService.php` — alertas derivadas.
- `app/Services/Agenda/IcsBuilder.php` — generación `.ics`.
- `app/Events/AgendaItemAssigned.php` — broadcast Reverb.
- `app/Http/Requests/Agenda/StoreAgendaItemRequest.php` · `UpdateAgendaItemRequest.php`.
- `app/Http/Controllers/Agenda/AgendaController.php`.

**Backend (modificar):**
- `routes/web.php` — grupo `/{tenant}/agenda` + import.
- `routes/channels.php` — canal `agenda.user.{userId}`.

**Frontend (crear):**
- `resources/js/Pages/Agenda/Index.vue` — pestañas Hoy/Calendario/Alertas.
- `resources/js/Components/Agenda/AgendaItemModal.vue` — crear/editar.
- `resources/js/Components/Agenda/AgendaCalendar.vue` — grid mensual.
- `resources/js/Components/Agenda/AgendaTodayWidget.vue` — widget dashboard.

**Frontend (modificar):**
- `resources/js/Layouts/CajeroLayout.vue`, `SucursalLayout.vue`, `EmpresaLayout.vue` (o equivalente) — link "Agenda".
- Dashboards de los 3 roles — montar `AgendaTodayWidget`.

**Tests (crear):**
- `tests/Feature/Agenda/AgendaCrudTest.php`
- `tests/Feature/Agenda/AgendaVisibilityTest.php`
- `tests/Feature/Agenda/AgendaRecurrenceTest.php`
- `tests/Feature/Agenda/AgendaAlertServiceTest.php`
- `tests/Feature/Agenda/AgendaIcsTest.php`

---

## Task 1: Enums

**Files:**
- Create: `app/Enums/AgendaItemType.php`, `app/Enums/AgendaScope.php`, `app/Enums/AgendaRecurrence.php`, `app/Enums/AgendaPriority.php`

- [ ] **Step 1: Crear los 4 enums**

`app/Enums/AgendaItemType.php`:
```php
<?php

namespace App\Enums;

enum AgendaItemType: string
{
    case Task = 'task';
    case Event = 'event';
    case Note = 'note';
}
```

`app/Enums/AgendaScope.php`:
```php
<?php

namespace App\Enums;

enum AgendaScope: string
{
    case Company = 'company';
    case Branch = 'branch';
    case Personal = 'personal';
}
```

`app/Enums/AgendaRecurrence.php`:
```php
<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

enum AgendaRecurrence: string
{
    case None = 'none';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    /** Avanza una fecha a la siguiente ocurrencia según la recurrencia. */
    public function advance(Carbon $date): Carbon
    {
        return match ($this) {
            self::None => $date->copy(),
            self::Daily => $date->copy()->addDay(),
            self::Weekly => $date->copy()->addWeek(),
            self::Monthly => $date->copy()->addMonthNoOverflow(),
        };
    }
}
```

`app/Enums/AgendaPriority.php`:
```php
<?php

namespace App\Enums;

enum AgendaPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Enums/Agenda*.php
git commit -m "feat(agenda): enums de tipo, alcance, recurrencia y prioridad"
```

---

## Task 2: Migración `agenda_items`

**Files:**
- Create: `database/migrations/2026_05_24_120000_create_agenda_items_table.php`

- [ ] **Step 1: Crear la migración**

Crear el archivo con:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agenda_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type');                 // task | event | note
            $table->string('title', 160);
            $table->text('body')->nullable();
            $table->string('scope');                // company | branch | personal
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->timestamp('remind_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('priority')->nullable(); // low | normal | high
            $table->string('recurrence')->default('none');
            $table->date('recurrence_until')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'scope', 'branch_id']);
            $table->index(['tenant_id', 'starts_at']);
            $table->index(['tenant_id', 'type', 'completed_at']);
            $table->index('assigned_to_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agenda_items');
    }
};
```

- [ ] **Step 2: Migrar**

Run: `vendor/bin/sail artisan migrate`
Expected: `... create_agenda_items_table ... DONE`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/*_create_agenda_items_table.php
git commit -m "feat(agenda): migración agenda_items"
```

---

## Task 3: Modelo `AgendaItem` + factory + scope `visibleTo`

**Files:**
- Create: `app/Models/AgendaItem.php`
- Create: `database/factories/AgendaItemFactory.php`
- Test: `tests/Feature/Agenda/AgendaVisibilityTest.php`

- [ ] **Step 1: Escribir test de visibilidad (falla)**

`tests/Feature/Agenda/AgendaVisibilityTest.php`:
```php
<?php

namespace Tests\Feature\Agenda;

use App\Enums\AgendaScope;
use App\Models\AgendaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AgendaVisibilityTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function make(array $attrs): AgendaItem
    {
        return AgendaItem::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'type' => 'task',
            'title' => 'X',
            'scope' => 'personal',
            'user_id' => $this->adminSucursal->id,
        ], $attrs));
    }

    public function test_admin_empresa_sees_all_branches(): void
    {
        $this->make(['scope' => 'branch', 'branch_id' => $this->branch->id, 'user_id' => $this->adminSucursal->id]);
        $this->make(['scope' => 'branch', 'branch_id' => $this->secondBranch->id, 'user_id' => $this->adminSucursal->id]);

        $visible = AgendaItem::visibleTo($this->adminEmpresa)->get();

        $this->assertCount(2, $visible);
    }

    public function test_admin_sucursal_sees_company_branch_personal_assigned(): void
    {
        $company = $this->make(['scope' => 'company', 'branch_id' => null, 'user_id' => $this->adminEmpresa->id]);
        $mine = $this->make(['scope' => 'branch', 'branch_id' => $this->branch->id]);
        $other = $this->make(['scope' => 'branch', 'branch_id' => $this->secondBranch->id]);
        $personalOther = $this->make(['scope' => 'personal', 'user_id' => $this->adminEmpresa->id]);
        $assigned = $this->make(['scope' => 'personal', 'user_id' => $this->adminEmpresa->id, 'assigned_to_user_id' => $this->adminSucursal->id]);

        $ids = AgendaItem::visibleTo($this->adminSucursal)->pluck('id');

        $this->assertTrue($ids->contains($company->id));
        $this->assertTrue($ids->contains($mine->id));
        $this->assertTrue($ids->contains($assigned->id));
        $this->assertFalse($ids->contains($other->id));
        $this->assertFalse($ids->contains($personalOther->id));
    }
}
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `vendor/bin/sail artisan test --filter=AgendaVisibilityTest`
Expected: FAIL (`Class "App\Models\AgendaItem" not found`).

- [ ] **Step 3: Crear el modelo**

`app/Models/AgendaItem.php`:
```php
<?php

namespace App\Models;

use App\Enums\AgendaItemType;
use App\Enums\AgendaPriority;
use App\Enums\AgendaRecurrence;
use App\Enums\AgendaScope;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id', 'type', 'title', 'body', 'scope', 'branch_id', 'user_id',
    'assigned_to_user_id', 'starts_at', 'ends_at', 'all_day', 'remind_at',
    'completed_at', 'priority', 'recurrence', 'recurrence_until',
])]
class AgendaItem extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => AgendaItemType::class,
            'scope' => AgendaScope::class,
            'priority' => AgendaPriority::class,
            'recurrence' => AgendaRecurrence::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'remind_at' => 'datetime',
            'completed_at' => 'datetime',
            'recurrence_until' => 'date',
            'all_day' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * Acota la consulta a lo que el usuario puede ver:
     * company del tenant + branch de su sucursal + personales propios + asignadas.
     * admin-empresa ve todas las sucursales.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $isCompanyAdmin = $user->hasRole('admin-empresa') || $user->hasRole('superadmin');

        return $query->where(function (Builder $q) use ($user, $isCompanyAdmin) {
            $q->where('scope', AgendaScope::Company->value)
                ->orWhere('assigned_to_user_id', $user->id)
                ->orWhere(function (Builder $q2) use ($user) {
                    $q2->where('scope', AgendaScope::Personal->value)
                        ->where('user_id', $user->id);
                });

            if ($isCompanyAdmin) {
                $q->orWhere('scope', AgendaScope::Branch->value);
            } else {
                $q->orWhere(function (Builder $q2) use ($user) {
                    $q2->where('scope', AgendaScope::Branch->value)
                        ->where('branch_id', $user->branch_id);
                });
            }
        });
    }
}
```

- [ ] **Step 4: Crear la factory**

`database/factories/AgendaItemFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\AgendaItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class AgendaItemFactory extends Factory
{
    protected $model = AgendaItem::class;

    public function definition(): array
    {
        return [
            'type' => 'task',
            'title' => $this->faker->sentence(3),
            'scope' => 'personal',
            'recurrence' => 'none',
            'all_day' => false,
        ];
    }
}
```

- [ ] **Step 5: Correr y verificar que pasa**

Run: `vendor/bin/sail artisan test --filter=AgendaVisibilityTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Models/AgendaItem.php database/factories/AgendaItemFactory.php tests/Feature/Agenda/AgendaVisibilityTest.php
git commit -m "feat(agenda): modelo AgendaItem con scope visibleTo + factory"
```

---

## Task 4: Policy

**Files:**
- Create: `app/Policies/AgendaItemPolicy.php`
- Test: agrega métodos a `tests/Feature/Agenda/AgendaVisibilityTest.php`

- [ ] **Step 1: Test de policy (falla)**

Agregar a `AgendaVisibilityTest`:
```php
    public function test_only_company_admin_creates_company_scope(): void
    {
        $this->assertTrue($this->adminEmpresa->can('createScope', [\App\Models\AgendaItem::class, 'company']));
        $this->assertFalse($this->adminSucursal->can('createScope', [\App\Models\AgendaItem::class, 'company']));
        $this->assertTrue($this->adminSucursal->can('createScope', [\App\Models\AgendaItem::class, 'personal']));
    }

    public function test_cannot_update_others_personal_item(): void
    {
        $item = $this->make(['scope' => 'personal', 'user_id' => $this->adminEmpresa->id]);
        $this->assertFalse($this->cajero->can('update', $item));
        $this->assertTrue($this->adminEmpresa->can('update', $item));
    }
```

- [ ] **Step 2: Verificar que falla**

Run: `vendor/bin/sail artisan test --filter=AgendaVisibilityTest`
Expected: FAIL (policy/ability no existe).

- [ ] **Step 3: Crear la policy**

`app/Policies/AgendaItemPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Enums\AgendaScope;
use App\Models\AgendaItem;
use App\Models\User;

class AgendaItemPolicy
{
    private function isCompanyAdmin(User $user): bool
    {
        return $user->hasRole('admin-empresa') || $user->hasRole('superadmin');
    }

    public function view(User $user, AgendaItem $item): bool
    {
        if ($item->tenant_id !== $user->tenant_id) {
            return false;
        }
        if ($item->scope === AgendaScope::Company) {
            return true;
        }
        if ($item->assigned_to_user_id === $user->id) {
            return true;
        }
        if ($item->scope === AgendaScope::Personal) {
            return $item->user_id === $user->id;
        }
        // branch
        return $this->isCompanyAdmin($user) || $item->branch_id === $user->branch_id;
    }

    public function update(User $user, AgendaItem $item): bool
    {
        if ($item->tenant_id !== $user->tenant_id) {
            return false;
        }
        if ($item->user_id === $user->id) {
            return true;
        }
        if ($this->isCompanyAdmin($user)) {
            return true;
        }
        if ($item->scope === AgendaScope::Branch
            && $user->hasRole('admin-sucursal')
            && $item->branch_id === $user->branch_id) {
            return true;
        }

        return false;
    }

    public function delete(User $user, AgendaItem $item): bool
    {
        return $this->update($user, $item);
    }

    public function complete(User $user, AgendaItem $item): bool
    {
        return $item->tenant_id === $user->tenant_id
            && ($item->user_id === $user->id || $item->assigned_to_user_id === $user->id);
    }

    /** Ability sin modelo: ¿puede crear un ítem con este scope? */
    public function createScope(User $user, string $scope): bool
    {
        return match ($scope) {
            AgendaScope::Company->value => $this->isCompanyAdmin($user),
            AgendaScope::Branch->value => true, // controlador valida que sea su sucursal
            default => true, // personal
        };
    }
}
```

- [ ] **Step 4: Registrar la policy**

En `app/Providers/AppServiceProvider.php`, dentro de `boot()`, agrega:
```php
\Illuminate\Support\Facades\Gate::policy(\App\Models\AgendaItem::class, \App\Policies\AgendaItemPolicy::class);
```
(Si el proyecto usa auto-discovery de policies, este paso puede omitirse; verifícalo corriendo el test del paso 5. Añádelo solo si falla por policy no encontrada.)

- [ ] **Step 5: Verificar que pasa**

Run: `vendor/bin/sail artisan test --filter=AgendaVisibilityTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Policies/AgendaItemPolicy.php app/Providers/AppServiceProvider.php tests/Feature/Agenda/AgendaVisibilityTest.php
git commit -m "feat(agenda): AgendaItemPolicy (view/update/delete/complete/createScope)"
```

---

## Task 5: `AgendaCalendarService` (expansión de recurrencia)

**Files:**
- Create: `app/Services/Agenda/AgendaCalendarService.php`
- Test: `tests/Feature/Agenda/AgendaRecurrenceTest.php`

- [ ] **Step 1: Test (falla)**

`tests/Feature/Agenda/AgendaRecurrenceTest.php`:
```php
<?php

namespace Tests\Feature\Agenda;

use App\Models\AgendaItem;
use App\Services\Agenda\AgendaCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AgendaRecurrenceTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_weekly_event_expands_into_range(): void
    {
        AgendaItem::create([
            'tenant_id' => $this->tenant->id,
            'type' => 'event',
            'title' => 'Conteo',
            'scope' => 'branch',
            'branch_id' => $this->branch->id,
            'user_id' => $this->adminSucursal->id,
            'starts_at' => Carbon::parse('2026-06-07 10:00:00'), // domingo
            'recurrence' => 'weekly',
        ]);

        $occurrences = app(AgendaCalendarService::class)->expand(
            AgendaItem::query(),
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-30'),
        );

        // 7, 14, 21, 28 de junio = 4 ocurrencias
        $this->assertCount(4, $occurrences);
        $this->assertEquals('2026-06-07', $occurrences[0]['starts_at']->toDateString());
        $this->assertEquals('2026-06-28', $occurrences[3]['starts_at']->toDateString());
    }

    public function test_non_recurring_item_appears_once_if_in_range(): void
    {
        AgendaItem::create([
            'tenant_id' => $this->tenant->id,
            'type' => 'event', 'title' => 'Único', 'scope' => 'company',
            'user_id' => $this->adminEmpresa->id,
            'starts_at' => Carbon::parse('2026-06-10 09:00:00'),
            'recurrence' => 'none',
        ]);

        $occ = app(AgendaCalendarService::class)->expand(
            AgendaItem::query(), Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'));

        $this->assertCount(1, $occ);
    }
}
```

- [ ] **Step 2: Verificar que falla**

Run: `vendor/bin/sail artisan test --filter=AgendaRecurrenceTest`
Expected: FAIL (`AgendaCalendarService` no existe).

- [ ] **Step 3: Implementar el servicio**

`app/Services/Agenda/AgendaCalendarService.php`:
```php
<?php

namespace App\Services\Agenda;

use App\Enums\AgendaRecurrence;
use App\Models\AgendaItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AgendaCalendarService
{
    /**
     * Expande los ítems con fecha (y sus recurrencias) en ocurrencias dentro
     * del rango [from, to]. NO materializa filas: trabaja en memoria.
     *
     * @return array<int, array{item: AgendaItem, starts_at: Carbon}>
     */
    public function expand(Builder $query, Carbon $from, Carbon $to): array
    {
        $items = (clone $query)->whereNotNull('starts_at')->get();
        $occurrences = [];

        foreach ($items as $item) {
            foreach ($this->occurrencesFor($item, $from, $to) as $date) {
                $occurrences[] = ['item' => $item, 'starts_at' => $date];
            }
        }

        usort($occurrences, fn ($a, $b) => $a['starts_at'] <=> $b['starts_at']);

        return $occurrences;
    }

    /**
     * @return array<int, Carbon>
     */
    private function occurrencesFor(AgendaItem $item, Carbon $from, Carbon $to): array
    {
        $base = $item->starts_at->copy();
        $recurrence = $item->recurrence ?? AgendaRecurrence::None;

        if ($recurrence === AgendaRecurrence::None) {
            return ($base->betweenIncluded($from, $to)) ? [$base] : [];
        }

        $until = $item->recurrence_until?->copy()->endOfDay();
        $cursor = $base->copy();
        $dates = [];
        $guard = 0;

        // Avanza hasta entrar al rango.
        while ($cursor->lt($from) && $guard++ < 1000) {
            if ($until && $cursor->gt($until)) {
                return [];
            }
            $cursor = $recurrence->advance($cursor);
        }

        // Recolecta dentro del rango.
        while ($cursor->lte($to) && $guard++ < 1000) {
            if ($until && $cursor->gt($until)) {
                break;
            }
            $dates[] = $cursor->copy();
            $cursor = $recurrence->advance($cursor);
        }

        return $dates;
    }
}
```

- [ ] **Step 4: Verificar que pasa**

Run: `vendor/bin/sail artisan test --filter=AgendaRecurrenceTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Services/Agenda/AgendaCalendarService.php tests/Feature/Agenda/AgendaRecurrenceTest.php
git commit -m "feat(agenda): AgendaCalendarService expande recurrencia en memoria"
```

---

## Task 6: `AgendaAlertService` (alertas derivadas)

**Files:**
- Create: `app/Services/Agenda/AgendaAlertService.php`
- Test: `tests/Feature/Agenda/AgendaAlertServiceTest.php`

**Contexto:** reusa `App\Services\Metrics\CollectionMetrics::receivablesTable(?int $branchId, int $tenantId)` (devuelve filas con `id, name, phone, balance, pending_sales, last_sale, last_payment, days_oldest`). Para cuentas por pagar lee `Purchase` con `amount_pending > 0`. El concern de tests `SeedsMetricsData` ofrece `makeCreditSale()` para generar saldo de cliente.

- [ ] **Step 1: Test (falla)**

`tests/Feature/Agenda/AgendaAlertServiceTest.php`:
```php
<?php

namespace Tests\Feature\Agenda;

use App\Models\Provider;
use App\Models\Purchase;
use App\Services\Agenda\AgendaAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AgendaAlertServiceTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_lists_accounts_payable_for_visible_branch(): void
    {
        Purchase::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'provider_id' => Provider::create(['name' => 'Don Pedro', 'type' => 'mayorista_carne'])->id,
            'folio' => 'C-1', 'purchased_at' => now()->subDays(5),
            'status' => 'received', 'subtotal' => 1000, 'total' => 1000,
            'amount_paid' => 0, 'amount_pending' => 1000,
            'created_by' => $this->adminSucursal->id,
        ]);

        $alerts = app(AgendaAlertService::class)->for($this->adminSucursal);

        $payable = array_values(array_filter($alerts, fn ($a) => $a['source'] === 'accounts_payable'));
        $this->assertNotEmpty($payable);
        $this->assertEquals(1000.0, $payable[0]['amount']);
    }

    public function test_does_not_write_to_database(): void
    {
        app(AgendaAlertService::class)->for($this->adminEmpresa);
        $this->assertDatabaseCount('agenda_items', 0);
    }
}
```

- [ ] **Step 2: Verificar que falla**

Run: `vendor/bin/sail artisan test --filter=AgendaAlertServiceTest`
Expected: FAIL (`AgendaAlertService` no existe).

- [ ] **Step 3: Implementar el servicio**

`app/Services/Agenda/AgendaAlertService.php`:
```php
<?php

namespace App\Services\Agenda;

use App\Models\ApiKey;
use App\Models\CashRegisterShift;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Metrics\CollectionMetrics;

class AgendaAlertService
{
    public function __construct(private readonly CollectionMetrics $collection) {}

    /**
     * Alertas derivadas (solo lectura) para el usuario, acotadas a su(s)
     * sucursal(es) visibles. NO escribe en BD.
     *
     * @return array<int, array<string, mixed>>
     */
    public function for(User $user): array
    {
        $isCompanyAdmin = $user->hasRole('admin-empresa') || $user->hasRole('superadmin');
        $branchId = $isCompanyAdmin ? null : $user->branch_id;
        $tenantId = $user->tenant_id;

        return array_merge(
            $this->accountsPayable($tenantId, $branchId),
            $this->overdueCredit($tenantId, $branchId),
            $this->openStaleShifts($tenantId, $branchId),
            $this->expiringApiKeys($tenantId, $branchId),
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function accountsPayable(int $tenantId, ?int $branchId): array
    {
        return Purchase::query()
            ->where('amount_pending', '>', 0)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with('provider:id,name')
            ->orderBy('purchased_at')
            ->limit(50)
            ->get()
            ->map(fn (Purchase $p) => [
                'key' => "payable-{$p->id}",
                'source' => 'accounts_payable',
                'title' => 'Pago a proveedor: '.($p->provider?->name ?? 'proveedor'),
                'detail' => "Saldo de la compra {$p->folio}",
                'amount' => (float) $p->amount_pending,
                'due_at' => optional($p->purchased_at)->toIso8601String(),
                'severity' => $p->purchased_at && $p->purchased_at->lt(now()->subDays(30)) ? 'high' : 'normal',
            ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function overdueCredit(int $tenantId, ?int $branchId): array
    {
        $rows = $this->collection->receivablesTable($branchId, $tenantId, 50);

        return collect($rows)
            ->filter(fn ($r) => ($r['days_oldest'] ?? 0) > 30)
            ->map(fn ($r) => [
                'key' => "credit-{$r['id']}",
                'source' => 'overdue_credit',
                'title' => 'Cobrar a '.$r['name'],
                'detail' => "Debe hace {$r['days_oldest']} días",
                'amount' => (float) $r['balance'],
                'due_at' => null,
                'severity' => ($r['days_oldest'] ?? 0) > 60 ? 'high' : 'normal',
                'phone' => $r['phone'] ?? null,
            ])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function openStaleShifts(int $tenantId, ?int $branchId): array
    {
        return CashRegisterShift::query()
            ->whereNull('closed_at')
            ->where('opened_at', '<', now()->startOfDay())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get()
            ->map(fn (CashRegisterShift $s) => [
                'key' => "shift-{$s->id}",
                'source' => 'open_shift',
                'title' => 'Turno sin cerrar',
                'detail' => 'Abierto desde '.$s->opened_at->format('d/m H:i'),
                'amount' => null,
                'due_at' => $s->opened_at->toIso8601String(),
                'severity' => 'high',
            ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function expiringApiKeys(int $tenantId, ?int $branchId): array
    {
        return ApiKey::query()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(7))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get()
            ->map(fn (ApiKey $k) => [
                'key' => "apikey-{$k->id}",
                'source' => 'api_key',
                'title' => 'API Key por expirar: '.$k->name,
                'detail' => 'Expira '.$k->expires_at->format('d/m/Y'),
                'amount' => null,
                'due_at' => $k->expires_at->toIso8601String(),
                'severity' => 'normal',
            ])->all();
    }
}
```

- [ ] **Step 4: Verificar que pasa**

Run: `vendor/bin/sail artisan test --filter=AgendaAlertServiceTest`
Expected: PASS (2 tests). Si `receivablesTable` devuelve otra clave para los días, ajusta `days_oldest` al nombre real (verifícalo leyendo `app/Services/Metrics/CollectionMetrics.php`).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Services/Agenda/AgendaAlertService.php tests/Feature/Agenda/AgendaAlertServiceTest.php
git commit -m "feat(agenda): AgendaAlertService (cuentas por pagar, fiados, turno, api key)"
```

---

## Task 7: `IcsBuilder` (.ics con VALARM)

**Files:**
- Create: `app/Services/Agenda/IcsBuilder.php`
- Test: `tests/Feature/Agenda/AgendaIcsTest.php`

- [ ] **Step 1: Test (falla)**

`tests/Feature/Agenda/AgendaIcsTest.php`:
```php
<?php

namespace Tests\Feature\Agenda;

use App\Models\AgendaItem;
use App\Services\Agenda\IcsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AgendaIcsTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_builds_valid_ics_with_alarm(): void
    {
        $item = AgendaItem::create([
            'tenant_id' => $this->tenant->id,
            'type' => 'event', 'title' => 'Entrega de carne', 'scope' => 'branch',
            'branch_id' => $this->branch->id, 'user_id' => $this->adminSucursal->id,
            'starts_at' => Carbon::parse('2026-06-10 14:00:00'),
            'remind_at' => Carbon::parse('2026-06-10 13:00:00'),
        ]);

        $ics = app(IcsBuilder::class)->forItem($item, 'test-tenant');

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString('SUMMARY:Entrega de carne', $ics);
        $this->assertStringContainsString("UID:agenda-{$item->id}@test-tenant", $ics);
        $this->assertStringContainsString('BEGIN:VALARM', $ics);
        $this->assertStringContainsString('END:VCALENDAR', $ics);
    }
}
```

- [ ] **Step 2: Verificar que falla**

Run: `vendor/bin/sail artisan test --filter=AgendaIcsTest`
Expected: FAIL (`IcsBuilder` no existe).

- [ ] **Step 3: Implementar**

`app/Services/Agenda/IcsBuilder.php`:
```php
<?php

namespace App\Services\Agenda;

use App\Models\AgendaItem;
use Illuminate\Support\Carbon;

class IcsBuilder
{
    public function forItem(AgendaItem $item, string $tenantSlug): string
    {
        $start = ($item->starts_at ?? now())->copy()->utc();
        $end = ($item->ends_at ?? $start->copy()->addHour())->copy()->utc();
        $stamp = now()->utc();
        $uid = "agenda-{$item->id}@{$tenantSlug}";

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Carniceria SaaS//Agenda//ES',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            "UID:{$uid}",
            'DTSTAMP:'.$this->fmt($stamp),
            'DTSTART:'.$this->fmt($start),
            'DTEND:'.$this->fmt($end),
            'SUMMARY:'.$this->escape($item->title),
        ];

        if ($item->body) {
            $lines[] = 'DESCRIPTION:'.$this->escape($item->body);
        }

        if ($item->remind_at) {
            $minutesBefore = max(0, $item->remind_at->diffInMinutes($item->starts_at ?? $item->remind_at, false));
            $lines[] = 'BEGIN:VALARM';
            $lines[] = 'ACTION:DISPLAY';
            $lines[] = 'DESCRIPTION:'.$this->escape($item->title);
            $lines[] = "TRIGGER:-PT{$minutesBefore}M";
            $lines[] = 'END:VALARM';
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }

    private function fmt(Carbon $dt): string
    {
        return $dt->format('Ymd\THis\Z');
    }

    private function escape(string $text): string
    {
        return str_replace(["\\", ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
    }
}
```

- [ ] **Step 4: Verificar que pasa**

Run: `vendor/bin/sail artisan test --filter=AgendaIcsTest`
Expected: PASS (1 test).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Services/Agenda/IcsBuilder.php tests/Feature/Agenda/AgendaIcsTest.php
git commit -m "feat(agenda): IcsBuilder genera .ics con VALARM"
```

---

## Task 8: Evento broadcast + canal

**Files:**
- Create: `app/Events/AgendaItemAssigned.php`
- Modify: `routes/channels.php`

- [ ] **Step 1: Crear el evento**

`app/Events/AgendaItemAssigned.php`:
```php
<?php

namespace App\Events;

use App\Models\AgendaItem;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class AgendaItemAssigned implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public AgendaItem $item, public int $notifyUserId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("agenda.user.{$this->notifyUserId}");
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->item->id,
            'title' => $this->item->title,
            'type' => $this->item->type->value,
        ];
    }
}
```

- [ ] **Step 2: Autorizar el canal**

En `routes/channels.php`, agrega:
```php
Broadcast::channel('agenda.user.{userId}', function ($user, int $userId) {
    return (int) $user->id === (int) $userId;
});
```
(Verifica que el archivo ya importe `use Illuminate\Support\Facades\Broadcast;` como los demás canales.)

- [ ] **Step 3: Commit**

```bash
git add app/Events/AgendaItemAssigned.php routes/channels.php
git commit -m "feat(agenda): evento AgendaItemAssigned + canal privado por usuario"
```

---

## Task 9: Form Requests (validación)

**Files:**
- Create: `app/Http/Requests/Agenda/StoreAgendaItemRequest.php`, `UpdateAgendaItemRequest.php`

- [ ] **Step 1: StoreAgendaItemRequest**

`app/Http/Requests/Agenda/StoreAgendaItemRequest.php`:
```php
<?php

namespace App\Http\Requests\Agenda;

use App\Enums\AgendaItemType;
use App\Enums\AgendaPriority;
use App\Enums\AgendaRecurrence;
use App\Enums\AgendaScope;
use App\Models\AgendaItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAgendaItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('createScope', [AgendaItem::class, (string) $this->input('scope')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = app('tenant')->id;

        return [
            'type' => ['required', Rule::enum(AgendaItemType::class)],
            'title' => ['required', 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:5000'],
            'scope' => ['required', Rule::enum(AgendaScope::class)],
            'branch_id' => [
                Rule::requiredIf(fn () => $this->input('scope') === AgendaScope::Branch->value),
                'nullable',
                Rule::exists('branches', 'id')->where('tenant_id', $tenantId),
            ],
            'assigned_to_user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where('tenant_id', $tenantId),
            ],
            'starts_at' => [
                Rule::requiredIf(fn () => $this->input('type') === AgendaItemType::Event->value),
                'nullable', 'date',
            ],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'all_day' => ['boolean'],
            'remind_at' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::enum(AgendaPriority::class)],
            'recurrence' => ['nullable', Rule::enum(AgendaRecurrence::class)],
            'recurrence_until' => ['nullable', 'date'],
        ];
    }
}
```

- [ ] **Step 2: UpdateAgendaItemRequest**

`app/Http/Requests/Agenda/UpdateAgendaItemRequest.php`: idéntico a Store pero `authorize()`:
```php
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('item'));
    }
```
Copia el método `rules()` completo de `StoreAgendaItemRequest` (mismas reglas).

- [ ] **Step 3: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Requests/Agenda
git commit -m "feat(agenda): form requests Store/Update con validación y authorize"
```

---

## Task 10: `AgendaController` + rutas

**Files:**
- Create: `app/Http/Controllers/Agenda/AgendaController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Agenda/AgendaCrudTest.php`

- [ ] **Step 1: Test CRUD + complete (falla)**

`tests/Feature/Agenda/AgendaCrudTest.php`:
```php
<?php

namespace Tests\Feature\Agenda;

use App\Models\AgendaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AgendaCrudTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_creates_personal_task(): void
    {
        $this->actingAs($this->cajero)
            ->post(route('agenda.store', $this->tenant->slug), [
                'type' => 'task', 'title' => 'Contar inventario', 'scope' => 'personal',
            ])->assertRedirect();

        $this->assertDatabaseHas('agenda_items', [
            'title' => 'Contar inventario', 'user_id' => $this->cajero->id, 'scope' => 'personal',
        ]);
    }

    public function test_cajero_cannot_create_company_scope(): void
    {
        $this->actingAs($this->cajero)
            ->post(route('agenda.store', $this->tenant->slug), [
                'type' => 'note', 'title' => 'Aviso', 'scope' => 'company',
            ])->assertForbidden();
    }

    public function test_completing_recurring_task_generates_next(): void
    {
        $task = AgendaItem::create([
            'tenant_id' => $this->tenant->id, 'type' => 'task', 'title' => 'Pagar renta',
            'scope' => 'branch', 'branch_id' => $this->branch->id, 'user_id' => $this->adminSucursal->id,
            'starts_at' => Carbon::parse('2026-06-01 09:00:00'), 'recurrence' => 'monthly',
        ]);

        $this->actingAs($this->adminSucursal)
            ->patch(route('agenda.complete', [$this->tenant->slug, $task->id]))
            ->assertRedirect();

        $this->assertNotNull($task->fresh()->completed_at);
        // Se generó la siguiente (julio)
        $this->assertDatabaseHas('agenda_items', [
            'title' => 'Pagar renta', 'completed_at' => null,
        ]);
        $this->assertEquals(2, AgendaItem::where('title', 'Pagar renta')->count());
    }

    public function test_ics_download(): void
    {
        $item = AgendaItem::create([
            'tenant_id' => $this->tenant->id, 'type' => 'event', 'title' => 'Entrega',
            'scope' => 'company', 'user_id' => $this->adminEmpresa->id,
            'starts_at' => Carbon::parse('2026-06-10 14:00:00'),
        ]);

        $res = $this->actingAs($this->adminEmpresa)
            ->get(route('agenda.ics', [$this->tenant->slug, $item->id]));

        $res->assertOk();
        $res->assertHeader('content-type', 'text/calendar; charset=UTF-8');
        $this->assertStringContainsString('BEGIN:VEVENT', $res->getContent());
    }
}
```

- [ ] **Step 2: Verificar que falla**

Run: `vendor/bin/sail artisan test --filter=AgendaCrudTest`
Expected: FAIL (ruta/controlador no existe).

- [ ] **Step 3: Crear el controlador**

`app/Http/Controllers/Agenda/AgendaController.php`:
```php
<?php

namespace App\Http\Controllers\Agenda;

use App\Enums\AgendaItemType;
use App\Enums\AgendaRecurrence;
use App\Events\AgendaItemAssigned;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agenda\StoreAgendaItemRequest;
use App\Http\Requests\Agenda\UpdateAgendaItemRequest;
use App\Models\AgendaItem;
use App\Services\Agenda\AgendaAlertService;
use App\Services\Agenda\AgendaCalendarService;
use App\Services\Agenda\IcsBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AgendaController extends Controller
{
    public function index(Request $request, AgendaAlertService $alerts): \Inertia\Response
    {
        $user = Auth::user();
        $tenant = app('tenant');

        $today = AgendaItem::visibleTo($user)
            ->whereNull('completed_at')
            ->where(function ($q) {
                $q->whereDate('starts_at', '<=', now())
                    ->orWhereDate('remind_at', '<=', now());
            })
            ->orderBy('starts_at')
            ->get();

        $upcoming = AgendaItem::visibleTo($user)
            ->whereNull('completed_at')
            ->whereNotNull('starts_at')
            ->whereBetween('starts_at', [now()->addDay()->startOfDay(), now()->addWeek()->endOfDay()])
            ->orderBy('starts_at')
            ->get();

        return Inertia::render('Agenda/Index', [
            'today' => $today,
            'upcoming' => $upcoming,
            'alerts' => $alerts->for($user),
            'branches' => $this->branchesForUser($user),
            'assignableUsers' => $this->assignableUsers($user),
            'tenant' => $tenant,
        ]);
    }

    public function calendar(Request $request, AgendaCalendarService $calendar): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        $from = Carbon::parse($request->query('from', now()->startOfMonth()->toDateString()));
        $to = Carbon::parse($request->query('to', now()->endOfMonth()->toDateString()));

        $occurrences = $calendar->expand(AgendaItem::visibleTo($user), $from, $to->endOfDay());

        return response()->json([
            'occurrences' => collect($occurrences)->map(fn ($o) => [
                'id' => $o['item']->id,
                'title' => $o['item']->title,
                'type' => $o['item']->type->value,
                'starts_at' => $o['starts_at']->toIso8601String(),
                'all_day' => $o['item']->all_day,
            ])->values(),
        ]);
    }

    public function alerts(AgendaAlertService $alerts): \Illuminate\Http\JsonResponse
    {
        return response()->json(['alerts' => $alerts->for(Auth::user())]);
    }

    public function store(StoreAgendaItemRequest $request): RedirectResponse
    {
        $user = Auth::user();
        $data = $request->validated();
        $data['user_id'] = $user->id;
        $data['tenant_id'] = app('tenant')->id;

        // branch no admin solo puede ser su sucursal
        if (($data['scope'] ?? null) === 'branch' && ! ($user->hasRole('admin-empresa') || $user->hasRole('superadmin'))) {
            $data['branch_id'] = $user->branch_id;
        }

        $item = AgendaItem::create($data);

        if ($item->assigned_to_user_id && $item->assigned_to_user_id !== $user->id) {
            AgendaItemAssigned::dispatch($item, $item->assigned_to_user_id);
        }

        return back()->with('success', 'Agregado a la agenda.');
    }

    public function update(UpdateAgendaItemRequest $request, AgendaItem $item): RedirectResponse
    {
        $item->update($request->validated());

        return back()->with('success', 'Actualizado.');
    }

    public function complete(AgendaItem $item): RedirectResponse
    {
        $this->authorize('complete', $item);

        $item->update(['completed_at' => now()]);

        // Recurrencia: genera la siguiente ocurrencia viva.
        $recurrence = $item->recurrence ?? AgendaRecurrence::None;
        if ($recurrence !== AgendaRecurrence::None && $item->starts_at) {
            $next = $recurrence->advance($item->starts_at);
            $until = $item->recurrence_until?->copy()->endOfDay();
            if (! $until || $next->lte($until)) {
                $clone = $item->replicate(['completed_at']);
                $clone->completed_at = null;
                $clone->starts_at = $next;
                if ($item->remind_at && $item->starts_at) {
                    $offset = $item->remind_at->diffInSeconds($item->starts_at, false);
                    $clone->remind_at = $next->copy()->addSeconds($offset);
                }
                $clone->save();
            }
        }

        return back()->with('success', 'Marcado como hecho.');
    }

    public function destroy(AgendaItem $item): RedirectResponse
    {
        $this->authorize('delete', $item);
        $item->delete();

        return back()->with('success', 'Eliminado.');
    }

    public function ics(AgendaItem $item, IcsBuilder $builder): Response
    {
        $this->authorize('view', $item);
        $ics = $builder->forItem($item, app('tenant')->slug);

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="agenda-'.$item->id.'.ics"',
        ]);
    }

    /** @return array<int, array{id:int,name:string}> */
    private function branchesForUser($user): array
    {
        $isCompanyAdmin = $user->hasRole('admin-empresa') || $user->hasRole('superadmin');
        $query = \App\Models\Branch::query()->where('status', 'active');
        if (! $isCompanyAdmin) {
            $query->where('id', $user->branch_id);
        }

        return $query->orderBy('name')->get(['id', 'name'])->toArray();
    }

    /** @return array<int, array{id:int,name:string}> */
    private function assignableUsers($user): array
    {
        $isCompanyAdmin = $user->hasRole('admin-empresa') || $user->hasRole('superadmin');
        $query = \App\Models\User::query()->where('tenant_id', $user->tenant_id);
        if (! $isCompanyAdmin) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query->orderBy('name')->get(['id', 'name'])->toArray();
    }
}
```

Nota: `complete()`, `destroy()`, `ics()` usan route-model binding de `AgendaItem` (parámetro `{item}`). El `TenantScope` global ya filtra por tenant; aun así la policy revalida `tenant_id`.

- [ ] **Step 4: Registrar rutas**

En `routes/web.php`, agrega el import junto a los demás:
```php
use App\Http\Controllers\Agenda\AgendaController;
```
Y un grupo nuevo (mismo patrón que los grupos tenant existentes — replica `prefix('{tenant}')`, middleware `resolve.tenant`,`ensure.tenant`, y `role:admin-empresa|admin-sucursal|cajero|superadmin`). Coloca dentro:
```php
Route::prefix('agenda')->name('agenda.')->group(function () {
    Route::get('/', [AgendaController::class, 'index'])->name('index');
    Route::get('calendario', [AgendaController::class, 'calendar'])->name('calendar');
    Route::get('alertas', [AgendaController::class, 'alerts'])->name('alerts');
    Route::post('/', [AgendaController::class, 'store'])->name('store');
    Route::put('{item}', [AgendaController::class, 'update'])->name('update');
    Route::patch('{item}/completar', [AgendaController::class, 'complete'])->name('complete');
    Route::delete('{item}', [AgendaController::class, 'destroy'])->name('destroy');
    Route::get('{item}/ics', [AgendaController::class, 'ics'])->name('ics');
});
```
**Importante:** revisa cómo está armado un grupo tenant existente en `routes/web.php` (p. ej. el de `sucursal`) y mete este sub-grupo bajo el MISMO middleware/prefijo de `{tenant}` para que `resolve.tenant` aplique. La ruta `agenda.index` debe quedar como `{tenant}/agenda`.

- [ ] **Step 5: Verificar que pasa**

Run: `vendor/bin/sail artisan test --filter=AgendaCrudTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Agenda/AgendaController.php routes/web.php tests/Feature/Agenda/AgendaCrudTest.php
git commit -m "feat(agenda): AgendaController (index/calendar/alerts/CRUD/complete/ics) + rutas"
```

---

## Task 11: Frontend — modal de creación/edición

**Files:**
- Create: `resources/js/Components/Agenda/AgendaItemModal.vue`

Reusa patrones de modales existentes (p. ej. `resources/js/Components/Caja/CajaGastoModal.vue` para estructura Teleport/Transition/footer, y `DateField.vue` para fechas).

- [ ] **Step 1: Crear el modal**

`resources/js/Components/Agenda/AgendaItemModal.vue`:
```vue
<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    tenantSlug: { type: String, required: true },
    branches: { type: Array, default: () => [] },
    assignableUsers: { type: Array, default: () => [] },
    item: { type: Object, default: null }, // null = crear
});
const emit = defineEmits(['close']);

const form = useForm({
    type: 'task',
    title: '',
    body: '',
    scope: 'personal',
    branch_id: '',
    assigned_to_user_id: '',
    starts_at: '',
    ends_at: '',
    all_day: false,
    remind_at: '',
    priority: 'normal',
    recurrence: 'none',
    recurrence_until: '',
});

watch(() => props.open, (v) => {
    if (v && props.item) {
        Object.keys(form.data()).forEach((k) => { if (props.item[k] !== undefined) form[k] = props.item[k] ?? ''; });
    } else if (v) {
        form.reset();
    }
});

const isEdit = computed(() => !!props.item);
const needsBranch = computed(() => form.scope === 'branch');
const isEvent = computed(() => form.type === 'event');

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => { form.reset(); emit('close'); } };
    if (isEdit.value) {
        form.put(route('agenda.update', [props.tenantSlug, props.item.id]), opts);
    } else {
        form.post(route('agenda.store', props.tenantSlug), opts);
    }
};
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition" leave-active-class="transition" enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 backdrop-blur-sm sm:items-center sm:p-4" @click.self="emit('close')">
                <div class="w-full max-w-lg overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
                    <header class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                        <h2 class="text-lg font-bold text-gray-900">{{ isEdit ? 'Editar' : 'Nuevo' }} en agenda</h2>
                        <button @click="emit('close')" class="text-gray-400 hover:text-gray-700">✕</button>
                    </header>

                    <form @submit.prevent="submit" class="space-y-4 px-5 py-5">
                        <div class="flex gap-2">
                            <button v-for="t in [['task','Tarea'],['event','Evento'],['note','Nota']]" :key="t[0]" type="button"
                                @click="form.type = t[0]"
                                :class="['flex-1 rounded-xl border-2 py-2 text-sm font-bold transition', form.type === t[0] ? 'border-red-400 bg-red-50 text-red-700' : 'border-gray-200 text-gray-600']">
                                {{ t[1] }}
                            </button>
                        </div>

                        <input v-model="form.title" type="text" maxlength="160" placeholder="Título *"
                            class="w-full rounded-xl border-gray-300 text-sm focus:border-red-500 focus:ring-red-500" />
                        <p v-if="form.errors.title" class="text-xs text-red-600">{{ form.errors.title }}</p>

                        <textarea v-model="form.body" rows="2" placeholder="Notas (opcional)"
                            class="w-full rounded-xl border-gray-300 text-sm focus:border-red-500 focus:ring-red-500"></textarea>

                        <select v-model="form.scope" class="w-full rounded-xl border-gray-300 text-sm">
                            <option value="personal">Personal</option>
                            <option value="branch">Sucursal</option>
                            <option value="company">Empresa</option>
                        </select>

                        <select v-if="needsBranch && branches.length > 1" v-model="form.branch_id" class="w-full rounded-xl border-gray-300 text-sm">
                            <option value="">Selecciona sucursal…</option>
                            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>

                        <input v-model="form.starts_at" type="datetime-local"
                            class="w-full rounded-xl border-gray-300 text-sm" :required="isEvent" />
                        <input v-if="isEvent" v-model="form.ends_at" type="datetime-local" class="w-full rounded-xl border-gray-300 text-sm" />
                        <input v-model="form.remind_at" type="datetime-local" class="w-full rounded-xl border-gray-300 text-sm" placeholder="Recordatorio" />

                        <select v-model="form.recurrence" class="w-full rounded-xl border-gray-300 text-sm">
                            <option value="none">No se repite</option>
                            <option value="daily">Diario</option>
                            <option value="weekly">Semanal</option>
                            <option value="monthly">Mensual</option>
                        </select>

                        <select v-if="form.type === 'task' && assignableUsers.length" v-model="form.assigned_to_user_id" class="w-full rounded-xl border-gray-300 text-sm">
                            <option value="">Sin asignar</option>
                            <option v-for="u in assignableUsers" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                    </form>

                    <footer class="flex justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3">
                        <button type="button" @click="emit('close')" class="rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700">Cancelar</button>
                        <button @click="submit" :disabled="form.processing || !form.title" class="rounded-xl bg-red-600 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50">Guardar</button>
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
```

- [ ] **Step 2: Build + commit**

```bash
vendor/bin/sail npm run build
git add resources/js/Components/Agenda/AgendaItemModal.vue
git commit -m "feat(agenda): modal de creación/edición de ítems"
```

---

## Task 12: Frontend — página Index (Hoy / Calendario / Alertas) + calendario

**Files:**
- Create: `resources/js/Pages/Agenda/Index.vue`
- Create: `resources/js/Components/Agenda/AgendaCalendar.vue`

- [ ] **Step 1: Calendario mensual**

`resources/js/Components/Agenda/AgendaCalendar.vue`:
```vue
<script setup>
import { router } from '@inertiajs/vue3';
import { computed, ref, onMounted } from 'vue';

const props = defineProps({ tenantSlug: { type: String, required: true } });

const cursor = ref(new Date());
const occurrences = ref([]);

const monthStart = computed(() => new Date(cursor.value.getFullYear(), cursor.value.getMonth(), 1));
const monthLabel = computed(() => cursor.value.toLocaleDateString('es-MX', { month: 'long', year: 'numeric' }));

const days = computed(() => {
    const start = new Date(monthStart.value);
    start.setDate(start.getDate() - start.getDay()); // arranca en domingo
    return Array.from({ length: 42 }, (_, i) => {
        const d = new Date(start);
        d.setDate(start.getDate() + i);
        return d;
    });
});

const fetchRange = async () => {
    const from = days.value[0].toISOString().slice(0, 10);
    const to = days.value[41].toISOString().slice(0, 10);
    const res = await fetch(route('agenda.calendar', props.tenantSlug) + `?from=${from}&to=${to}`, {
        headers: { Accept: 'application/json' },
    });
    occurrences.value = (await res.json()).occurrences;
};

const itemsForDay = (d) => occurrences.value.filter(o => o.starts_at.slice(0, 10) === d.toISOString().slice(0, 10));
const prevMonth = () => { cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() - 1, 1); fetchRange(); };
const nextMonth = () => { cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + 1, 1); fetchRange(); };
const inMonth = (d) => d.getMonth() === cursor.value.getMonth();

onMounted(fetchRange);
</script>

<template>
    <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
        <div class="mb-3 flex items-center justify-between">
            <button @click="prevMonth" class="rounded-lg px-3 py-1 text-gray-600 hover:bg-gray-100">‹</button>
            <h3 class="text-sm font-bold capitalize text-gray-900">{{ monthLabel }}</h3>
            <button @click="nextMonth" class="rounded-lg px-3 py-1 text-gray-600 hover:bg-gray-100">›</button>
        </div>
        <div class="grid grid-cols-7 gap-1 text-center text-[10px] font-bold uppercase text-gray-400">
            <div v-for="d in ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb']" :key="d">{{ d }}</div>
        </div>
        <div class="mt-1 grid grid-cols-7 gap-1">
            <div v-for="(d, i) in days" :key="i" :class="['min-h-[64px] rounded-lg border p-1 text-left', inMonth(d) ? 'border-gray-100' : 'border-transparent bg-gray-50/50 text-gray-300']">
                <div class="text-[11px] font-semibold">{{ d.getDate() }}</div>
                <div v-for="o in itemsForDay(d)" :key="o.id + o.starts_at" class="mt-0.5 truncate rounded bg-red-50 px-1 text-[10px] font-medium text-red-700">
                    {{ o.title }}
                </div>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Página Index con pestañas**

`resources/js/Pages/Agenda/Index.vue`:
```vue
<script setup>
import FlashToast from '@/Components/FlashToast.vue';
import AgendaItemModal from '@/Components/Agenda/AgendaItemModal.vue';
import AgendaCalendar from '@/Components/Agenda/AgendaCalendar.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    today: Array, upcoming: Array, alerts: Array,
    branches: Array, assignableUsers: Array, tenant: Object,
});

const page = usePage();
const role = computed(() => page.props.auth.role);
const Layout = computed(() => {
    // Carga el layout según rol (los 3 layouts existen en resources/js/Layouts)
    const map = { 'admin-empresa': 'EmpresaLayout', 'admin-sucursal': 'SucursalLayout', 'cajero': 'CajeroLayout' };
    return map[role.value] || 'SucursalLayout';
});

const tab = ref('today');
const modalOpen = ref(false);
const editing = ref(null);

const openCreate = () => { editing.value = null; modalOpen.value = true; };
const complete = (item) => router.patch(route('agenda.complete', [props.tenant.slug, item.id]), {}, { preserveScroll: true });
const remove = (item) => router.delete(route('agenda.destroy', [props.tenant.slug, item.id]), { preserveScroll: true });

const whatsappUrl = (alert) => {
    if (!alert.phone) return null;
    let num = String(alert.phone).replace(/[\s\-()]/g, '');
    if (/^\d{10}$/.test(num)) num = '52' + num;
    return `https://wa.me/${num}`;
};
</script>

<template>
    <Head title="Agenda" />
    <component :is="Layout">
        <template #header><h1 class="text-xl font-bold text-gray-900">Agenda</h1></template>

        <div class="mx-auto max-w-4xl space-y-5">
            <div class="flex items-center justify-between">
                <div class="flex gap-2">
                    <button v-for="t in [['today','Hoy'],['calendar','Calendario'],['alerts','Alertas']]" :key="t[0]"
                        @click="tab = t[0]"
                        :class="['rounded-xl px-4 py-2 text-sm font-bold transition', tab === t[0] ? 'bg-red-600 text-white' : 'bg-white text-gray-600 ring-1 ring-gray-200']">
                        {{ t[1] }}<span v-if="t[0]==='alerts' && alerts.length" class="ml-1 rounded-full bg-white/30 px-1.5 text-xs">{{ alerts.length }}</span>
                    </button>
                </div>
                <button @click="openCreate" class="rounded-xl bg-red-600 px-4 py-2 text-sm font-bold text-white">+ Nuevo</button>
            </div>

            <!-- HOY -->
            <div v-if="tab === 'today'" class="space-y-4">
                <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                    <p class="mb-2 text-xs font-bold uppercase tracking-wider text-gray-400">Hoy y pendiente</p>
                    <p v-if="!today.length" class="py-6 text-center text-sm text-gray-400">Nada pendiente. 🎉</p>
                    <div v-for="it in today" :key="it.id" class="flex items-center gap-3 border-b border-gray-50 py-2 last:border-0">
                        <button v-if="it.type === 'task'" @click="complete(it)" class="h-5 w-5 shrink-0 rounded-full border-2 border-gray-300 hover:border-red-500"></button>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-gray-900">{{ it.title }}</p>
                            <p v-if="it.starts_at" class="text-xs text-gray-400">{{ new Date(it.starts_at).toLocaleString('es-MX') }}</p>
                        </div>
                        <a :href="route('agenda.ics', [tenant.slug, it.id])" class="text-xs font-medium text-gray-400 hover:text-gray-700">📅</a>
                        <button @click="remove(it)" class="text-xs text-gray-300 hover:text-red-600">✕</button>
                    </div>
                </div>
                <div v-if="upcoming.length" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                    <p class="mb-2 text-xs font-bold uppercase tracking-wider text-gray-400">Próximos</p>
                    <div v-for="it in upcoming" :key="it.id" class="flex items-center justify-between border-b border-gray-50 py-2 last:border-0">
                        <p class="truncate text-sm text-gray-700">{{ it.title }}</p>
                        <span class="text-xs text-gray-400">{{ new Date(it.starts_at).toLocaleDateString('es-MX', { weekday:'short', day:'2-digit', month:'short' }) }}</span>
                    </div>
                </div>
            </div>

            <!-- CALENDARIO -->
            <AgendaCalendar v-else-if="tab === 'calendar'" :tenant-slug="tenant.slug" />

            <!-- ALERTAS -->
            <div v-else class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                <p v-if="!alerts.length" class="py-6 text-center text-sm text-gray-400">Sin alertas. Todo al corriente.</p>
                <div v-for="a in alerts" :key="a.key" class="flex items-center gap-3 border-b border-gray-50 py-2.5 last:border-0">
                    <span :class="['h-2 w-2 shrink-0 rounded-full', a.severity === 'high' ? 'bg-red-500' : 'bg-amber-400']"></span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-gray-900">{{ a.title }}</p>
                        <p class="text-xs text-gray-400">{{ a.detail }}</p>
                    </div>
                    <span v-if="a.amount" class="text-sm font-bold tabular-nums text-gray-900">${{ Number(a.amount).toLocaleString('es-MX') }}</span>
                    <a v-if="whatsappUrl(a)" :href="whatsappUrl(a)" target="_blank" class="text-green-600 hover:text-green-700">WA</a>
                </div>
            </div>
        </div>

        <AgendaItemModal :open="modalOpen" :tenant-slug="tenant.slug" :branches="branches" :assignable-users="assignableUsers" :item="editing" @close="modalOpen = false" />
        <FlashToast />
    </component>
</template>
```

Nota: confirma los nombres reales de los layouts en `resources/js/Layouts/` y ajusta el mapa `Layout` si difieren (p. ej. si el de empresa se llama distinto). Importa los 3 layouts estáticamente si `component :is` con string no resuelve; en ese caso usa un `import` y un `computed` que devuelva el componente.

- [ ] **Step 3: Build**

Run: `vendor/bin/sail npm run build`
Expected: `✓ built`.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Agenda/Index.vue resources/js/Components/Agenda/AgendaCalendar.vue
git commit -m "feat(agenda): página Index (Hoy/Calendario/Alertas) + calendario mensual"
```

---

## Task 13: Navegación + widget de dashboard

**Files:**
- Modify: `resources/js/Layouts/CajeroLayout.vue` (y los layouts de empresa/sucursal)
- Create: `resources/js/Components/Agenda/AgendaTodayWidget.vue`
- Modify: dashboards de los 3 roles

- [ ] **Step 1: Link "Agenda" en cada layout**

En `CajeroLayout.vue` agrega al `navLinks` (computed) un item:
```js
{ label: 'Agenda', route: 'agenda.index', icon: 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5' },
```
Repite el equivalente en el layout de admin-sucursal y admin-empresa (mismo nombre de ruta `agenda.index`, que es válido para los 3 roles).

- [ ] **Step 2: Widget "Hoy"**

`resources/js/Components/Agenda/AgendaTodayWidget.vue`:
```vue
<script setup>
import { Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

const props = defineProps({ tenantSlug: { type: String, required: true } });
const items = ref([]);
const alerts = ref([]);

onMounted(async () => {
    const res = await fetch(route('agenda.alerts', props.tenantSlug), { headers: { Accept: 'application/json' } });
    alerts.value = (await res.json()).alerts.slice(0, 3);
});
</script>

<template>
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-bold text-gray-900">Agenda — hoy</h3>
            <Link :href="route('agenda.index', tenantSlug)" class="text-xs font-semibold text-red-600 hover:underline">Ver todo →</Link>
        </div>
        <p v-if="!alerts.length" class="text-sm text-gray-400">Sin alertas.</p>
        <div v-for="a in alerts" :key="a.key" class="flex items-center gap-2 py-1.5">
            <span :class="['h-2 w-2 rounded-full', a.severity === 'high' ? 'bg-red-500' : 'bg-amber-400']"></span>
            <span class="truncate text-sm text-gray-700">{{ a.title }}</span>
        </div>
    </div>
</template>
```

- [ ] **Step 3: Montar el widget en cada dashboard**

En el dashboard de cada rol (p. ej. `resources/js/Pages/Caja/Dashboard.vue` y equivalentes de empresa/sucursal), importa y coloca:
```vue
import AgendaTodayWidget from '@/Components/Agenda/AgendaTodayWidget.vue';
// ...
<AgendaTodayWidget :tenant-slug="tenant.slug" />
```
Ajusta `tenant.slug` al prop/props.auth disponible en cada dashboard.

- [ ] **Step 4: Build + commit**

```bash
vendor/bin/sail npm run build
git add resources/js/Layouts resources/js/Components/Agenda/AgendaTodayWidget.vue resources/js/Pages
git commit -m "feat(agenda): link de navegación + widget de dashboard"
```

---

## Task 14: Suite completa + cierre

- [ ] **Step 1: Correr todos los tests de agenda**

Run: `vendor/bin/sail artisan test --filter=Agenda`
Expected: PASS (todos).

- [ ] **Step 2: Correr la suite completa (no romper nada)**

Run: `vendor/bin/sail artisan test --compact`
Expected: PASS (toda la suite).

- [ ] **Step 3: Pint final**

Run: `vendor/bin/sail bin pint --dirty --format agent`
Expected: `{"result":"pass"}` o cambios aplicados.

- [ ] **Step 4: Commit final si quedó algo**

```bash
git add -A
git commit -m "chore(agenda): formato y cierre v1"
```

---

## Self-Review (cobertura del spec)

- Modelo `agenda_items` (task/event/note, scope, recurrence, assignment) → Tasks 1-3. ✅
- Visibilidad por rol + policy → Tasks 3-4. ✅
- Recurrencia (eventos expand, tareas regen al completar) → Tasks 5, 10. ✅
- Alertas derivadas (cuentas por pagar, fiados, turno, api key) → Task 6. ✅
- Recordatorio in-app (Hoy) + bloque "Próximos" → Task 10 (index/upcoming), Task 12. ✅
- Reverb al asignar → Task 8, dispatch en Task 10. ✅
- WhatsApp manual → Task 12 (botón WA en alertas). ✅
- `.ics` con VALARM → Tasks 7, 10, 12. ✅
- Vistas Hoy/Calendario/Alertas + widget → Tasks 12-13. ✅
- Zona horaria: el `.ics` usa UTC (`...Z`); la UI usa `toLocaleString('es-MX')`. (Para mostrar siempre en `America/Mexico_City` independientemente del navegador, considerar formatear con `timeZone: 'America/Mexico_City'` — anotar como ajuste menor durante ejecución.)

**Riesgo conocido a validar en ejecución:** el nombre exacto del layout de empresa y el de sucursal (Task 12/13), y la clave `days_oldest` de `receivablesTable` (Task 6). Ambos se verifican leyendo los archivos reales y ajustando.
