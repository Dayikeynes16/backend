# Agenda v2 — Estados, historial y notificaciones globales — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Agregar a la Agenda: estado Cancelada + estado derivado Atrasada, pestaña Completadas, secciones Atrasadas/Notas en Hoy, y una campana global (badge + dropdown + toast por polling) con posponer/cancelar/marcar-visto. Sin cron.

**Architecture:** Migración aditiva a `agenda_items` (`cancelled_at`, `cancel_reason`, `reminder_seen_at`). Estados derivados vía scopes del modelo. Nuevos endpoints en el `AgendaController` compartido. Un componente `AgendaBell.vue` montado en los 4 layouts hace polling a `agenda.notificaciones`. Reusa la Agenda v1 ya implementada.

**Tech Stack:** Laravel 13, PostgreSQL, Inertia v2 + Vue 3, Tailwind v3, PHPUnit 12, Sail.

**Base/spec:** `docs/superpowers/specs/2026-05-24-agenda-v2-estados-notificaciones-design.md`. La Agenda v1 vive en: `app/Models/AgendaItem.php`, `app/Http/Controllers/Agenda/AgendaController.php`, `app/Policies/AgendaItemPolicy.php`, `app/Services/Agenda/AgendaAlertService.php`, `resources/js/Pages/Agenda/Index.vue`, `resources/js/Components/Agenda/{AgendaItemModal,AgendaCalendar,AgendaTodayWidget,AgendaCapturaIAModal}.vue`, rutas en el grupo `{tenant}/agenda` de `routes/web.php`.

**Convenciones:** Sail para todo (`vendor/bin/sail ...`). Pint tras PHP (`vendor/bin/sail bin pint --dirty --format agent`). Commits con `git add <paths específicos>` — NUNCA `git add -A` (hay trabajo en paralelo sin relación en el árbol: Compras/Gastos/AuditLog; jamás stagearlo). Tests con `SeedsMetricsData`.

---

## Task 1: Migración — columnas de estado

**Files:**
- Create: `database/migrations/2026_05_24_130000_add_states_to_agenda_items.php`

- [ ] **Step 1: Crear migración**
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agenda_items', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            $table->string('cancel_reason', 255)->nullable()->after('cancelled_at');
            $table->timestamp('reminder_seen_at')->nullable()->after('remind_at');
            $table->index(['tenant_id', 'completed_at']);
            $table->index(['tenant_id', 'cancelled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('agenda_items', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'completed_at']);
            $table->dropIndex(['tenant_id', 'cancelled_at']);
            $table->dropColumn(['cancelled_at', 'cancel_reason', 'reminder_seen_at']);
        });
    }
};
```

- [ ] **Step 2:** `vendor/bin/sail artisan migrate` → DONE.
- [ ] **Step 3: Commit**
```bash
git add database/migrations/*_add_states_to_agenda_items.php
git commit -m "feat(agenda): columnas cancelled_at, cancel_reason, reminder_seen_at"
```

---

## Task 2: Modelo — fillable, casts, scopes y accessor `state`

**Files:**
- Modify: `app/Models/AgendaItem.php`
- Test: `tests/Feature/Agenda/AgendaStatesTest.php`

- [ ] **Step 1: Test (falla)**
`tests/Feature/Agenda/AgendaStatesTest.php`:
```php
<?php

namespace Tests\Feature\Agenda;

use App\Models\AgendaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AgendaStatesTest extends TestCase
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
            'tenant_id' => $this->tenant->id, 'type' => 'task', 'title' => 'X',
            'scope' => 'personal', 'user_id' => $this->cajero->id,
        ], $attrs));
    }

    public function test_state_accessor_and_scopes(): void
    {
        $pending = $this->make(['starts_at' => now()->addDay()]);
        $overdue = $this->make(['starts_at' => now()->subDay()]);
        $done = $this->make(['completed_at' => now()]);
        $cancelled = $this->make(['cancelled_at' => now(), 'cancel_reason' => 'ya no']);

        $this->assertSame('pending', $pending->state);
        $this->assertSame('overdue', $overdue->state);
        $this->assertSame('completed', $done->state);
        $this->assertSame('cancelled', $cancelled->state);

        $this->assertEqualsCanonicalizing(
            [$pending->id, $overdue->id],
            AgendaItem::active()->pluck('id')->all()
        );
        $this->assertEquals([$overdue->id], AgendaItem::overdue()->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$done->id, $cancelled->id],
            AgendaItem::history()->pluck('id')->all()
        );
    }
}
```

- [ ] **Step 2:** `vendor/bin/sail artisan test --filter=AgendaStatesTest` → FAIL.

- [ ] **Step 3: Editar el modelo.** En `app/Models/AgendaItem.php`:

3a. Agrega al atributo `#[Fillable([...])]` las 3 columnas nuevas: `'cancelled_at', 'cancel_reason', 'reminder_seen_at'`.

3b. En `casts()` agrega:
```php
'cancelled_at' => 'datetime',
'reminder_seen_at' => 'datetime',
```

3c. Agrega scopes + accessor (después de `scopeVisibleTo`):
```php
public function scopeActive(Builder $query): Builder
{
    return $query->whereNull('completed_at')->whereNull('cancelled_at');
}

public function scopeOverdue(Builder $query): Builder
{
    return $query->active()->whereNotNull('starts_at')->where('starts_at', '<', now());
}

public function scopePending(Builder $query): Builder
{
    return $query->active()->where(function (Builder $q) {
        $q->whereNull('starts_at')->orWhere('starts_at', '>=', now());
    });
}

public function scopeHistory(Builder $query): Builder
{
    return $query->where(function (Builder $q) {
        $q->whereNotNull('completed_at')->orWhereNotNull('cancelled_at');
    });
}

public function getStateAttribute(): string
{
    if ($this->completed_at) {
        return 'completed';
    }
    if ($this->cancelled_at) {
        return 'cancelled';
    }
    if ($this->starts_at && $this->starts_at->isPast()) {
        return 'overdue';
    }

    return 'pending';
}
```
(Asegúrate de que `protected $appends = ['state'];` exista para exponerlo a Inertia; agrégalo si no está.)

- [ ] **Step 4:** `vendor/bin/sail artisan test --filter=AgendaStatesTest` → PASS.
- [ ] **Step 5: Pint + commit**
```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Models/AgendaItem.php tests/Feature/Agenda/AgendaStatesTest.php
git commit -m "feat(agenda): scopes active/overdue/pending/history + accessor state"
```

---

## Task 3: Policy — ability `cancel`

**Files:**
- Modify: `app/Policies/AgendaItemPolicy.php`

- [ ] **Step 1:** Agrega el método (igual a `update`):
```php
public function cancel(User $user, AgendaItem $item): bool
{
    return $this->update($user, $item);
}
```
- [ ] **Step 2: Pint + commit**
```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Policies/AgendaItemPolicy.php
git commit -m "feat(agenda): policy ability cancel"
```

---

## Task 4: Controller — cancelar/posponer/visto/completadas/notificaciones + index/calendar actualizados + rutas

**Files:**
- Modify: `app/Http/Controllers/Agenda/AgendaController.php`
- Modify: `routes/web.php` (dentro del grupo `agenda` existente)
- Test: `tests/Feature/Agenda/AgendaNotificationsTest.php`

- [ ] **Step 1: Test (falla)**
`tests/Feature/Agenda/AgendaNotificationsTest.php`:
```php
<?php

namespace Tests\Feature\Agenda;

use App\Models\AgendaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AgendaNotificationsTest extends TestCase
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
            'tenant_id' => $this->tenant->id, 'type' => 'task', 'title' => 'X',
            'scope' => 'personal', 'user_id' => $this->cajero->id,
        ], $attrs));
    }

    public function test_notifications_lists_due_reminders_and_overdue(): void
    {
        $this->make(['remind_at' => now()->subMinute()]);          // due reminder
        $this->make(['starts_at' => now()->subDay()]);             // overdue
        $this->make(['starts_at' => now()->addDay()]);             // pending (no aparece)
        $this->make(['remind_at' => now()->subMinute(), 'reminder_seen_at' => now()]); // ya visto

        $res = $this->actingAs($this->cajero)
            ->getJson(route('agenda.notificaciones', $this->tenant->slug));

        $res->assertOk();
        $res->assertJsonPath('counts.due_reminders', 1);
        $res->assertJsonPath('counts.overdue', 1);
    }

    public function test_cancel_sets_reason_and_excludes_from_active(): void
    {
        $item = $this->make(['starts_at' => now()->addDay()]);

        $this->actingAs($this->cajero)
            ->patch(route('agenda.cancel', [$this->tenant->slug, $item->id]), ['cancel_reason' => 'ya no aplica'])
            ->assertRedirect();

        $item->refresh();
        $this->assertNotNull($item->cancelled_at);
        $this->assertSame('ya no aplica', $item->cancel_reason);
        $this->assertEquals(0, AgendaItem::active()->count());
    }

    public function test_snooze_moves_remind_at_and_clears_seen(): void
    {
        $item = $this->make(['starts_at' => now()->addDay(), 'remind_at' => now()->subMinute(), 'reminder_seen_at' => now()]);

        $this->actingAs($this->cajero)
            ->patch(route('agenda.snooze', [$this->tenant->slug, $item->id]), ['minutes' => 30])
            ->assertRedirect();

        $item->refresh();
        $this->assertNull($item->reminder_seen_at);
        $this->assertTrue($item->remind_at->gt(now()->addMinutes(25)));
    }

    public function test_mark_seen(): void
    {
        $item = $this->make(['remind_at' => now()->subMinute()]);
        $this->actingAs($this->cajero)
            ->patch(route('agenda.visto', [$this->tenant->slug, $item->id]))->assertRedirect();
        $this->assertNotNull($item->fresh()->reminder_seen_at);
    }

    public function test_completed_history(): void
    {
        $this->make(['completed_at' => now()]);
        $this->make(['cancelled_at' => now()]);
        $this->make(['starts_at' => now()->addDay()]); // activa, no aparece

        $res = $this->actingAs($this->cajero)
            ->getJson(route('agenda.completadas', $this->tenant->slug));

        $res->assertOk()->assertJsoncount(2, 'items.data');
    }
}
```

- [ ] **Step 2:** `vendor/bin/sail artisan test --filter=AgendaNotificationsTest` → FAIL (rutas no existen).

- [ ] **Step 3: Agregar métodos al `AgendaController`.** Añade (y asegúrate de tener `use App\Models\User;` ya presente; agrega lo que falte). Métodos nuevos:
```php
public function cancel(Request $request, AgendaItem $item): RedirectResponse
{
    $this->authorize('cancel', $item);
    $validated = $request->validate(['cancel_reason' => 'nullable|string|max:255']);
    $item->update([
        'cancelled_at' => now(),
        'cancel_reason' => $validated['cancel_reason'] ?? null,
    ]);

    return back()->with('success', 'Tarea cancelada.');
}

public function snooze(Request $request, AgendaItem $item): RedirectResponse
{
    $this->authorize('complete', $item);
    $validated = $request->validate(['minutes' => 'required|integer|min:1|max:10080']);
    $item->update([
        'remind_at' => now()->addMinutes($validated['minutes']),
        'reminder_seen_at' => null,
    ]);

    return back()->with('success', 'Recordatorio pospuesto.');
}

public function markReminderSeen(AgendaItem $item): RedirectResponse
{
    $this->authorize('view', $item);
    $item->update(['reminder_seen_at' => now()]);

    return back();
}

public function completed(Request $request): JsonResponse
{
    $items = AgendaItem::visibleTo(Auth::user())
        ->history()
        ->orderByDesc('completed_at')
        ->orderByDesc('cancelled_at')
        ->paginate(30);

    return response()->json(['items' => $items]);
}

public function notifications(AgendaAlertService $alerts): JsonResponse
{
    $user = Auth::user();

    $due = AgendaItem::visibleTo($user)->active()
        ->whereNotNull('remind_at')->where('remind_at', '<=', now())
        ->whereNull('reminder_seen_at')
        ->orderBy('remind_at')->limit(20)->get();

    $overdue = AgendaItem::visibleTo($user)->overdue()
        ->orderBy('starts_at')->limit(10)->get();

    $financial = $alerts->for($user);

    $map = fn ($i) => [
        'id' => $i->id, 'title' => $i->title, 'type' => $i->type->value,
        'starts_at' => optional($i->starts_at)->toIso8601String(),
        'remind_at' => optional($i->remind_at)->toIso8601String(),
    ];

    return response()->json([
        'due_reminders' => $due->map($map)->values(),
        'overdue' => $overdue->map($map)->values(),
        'alerts' => $financial,
        'counts' => [
            'due_reminders' => $due->count(),
            'overdue' => $overdue->count(),
            'alerts' => count($financial),
            'total' => $due->count() + $overdue->count() + count($financial),
        ],
    ]);
}
```

- [ ] **Step 4: Actualizar `index()`** para excluir canceladas, separar atrasadas y notas. Reemplaza el cuerpo de `index()` por:
```php
public function index(Request $request, AgendaAlertService $alerts): \Inertia\Response
{
    $user = Auth::user();
    $tenant = app('tenant');

    $overdue = AgendaItem::visibleTo($user)->overdue()->orderBy('starts_at')->get();

    $today = AgendaItem::visibleTo($user)->active()
        ->where(function ($q) {
            $q->whereDate('starts_at', now()->toDateString())
                ->orWhereDate('remind_at', now()->toDateString());
        })
        ->whereNotIn('id', $overdue->pluck('id'))
        ->orderBy('starts_at')->get();

    $upcoming = AgendaItem::visibleTo($user)->active()
        ->whereNotNull('starts_at')
        ->whereBetween('starts_at', [now()->addDay()->startOfDay(), now()->addWeek()->endOfDay()])
        ->orderBy('starts_at')->get();

    $notes = AgendaItem::visibleTo($user)->active()
        ->where('type', 'note')->whereNull('starts_at')
        ->orderByDesc('created_at')->get();

    return Inertia::render('Agenda/Index', [
        'overdue' => $overdue,
        'today' => $today,
        'upcoming' => $upcoming,
        'notes' => $notes,
        'alerts' => $alerts->for($user),
        'branches' => $this->branchesForUser($user),
        'assignableUsers' => $this->assignableUsers($user),
        'tenant' => $tenant,
    ]);
}
```

- [ ] **Step 5: Actualizar `calendar()`** para excluir canceladas e incluir `completed_at`. En el método `calendar`, cambia la query a `AgendaItem::visibleTo($user)->whereNull('cancelled_at')` y agrega `'completed_at' => optional($o['item']->completed_at)->toIso8601String(),` al map de ocurrencias.

- [ ] **Step 6: Rutas.** En `routes/web.php`, dentro del grupo `agenda` existente, agrega:
```php
Route::get('completadas', [AgendaController::class, 'completed'])->name('completed');
Route::get('notificaciones', [AgendaController::class, 'notifications'])->name('notificaciones');
Route::patch('{item}/cancelar', [AgendaController::class, 'cancel'])->name('cancel');
Route::patch('{item}/posponer', [AgendaController::class, 'snooze'])->name('snooze');
Route::patch('{item}/visto', [AgendaController::class, 'markReminderSeen'])->name('visto');
```

- [ ] **Step 7:** `vendor/bin/sail artisan test --filter=AgendaNotificationsTest` → PASS. Luego `vendor/bin/sail artisan test --filter=Agenda` (no romper v1).

- [ ] **Step 8: Pint + commit**
```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Agenda/AgendaController.php routes/web.php tests/Feature/Agenda/AgendaNotificationsTest.php
git commit -m "feat(agenda): cancelar/posponer/visto/completadas/notificaciones + index con atrasadas y notas"
```

---

## Task 5: Campana global `AgendaBell.vue` + montaje en layouts

**Files:**
- Create: `resources/js/Components/Agenda/AgendaBell.vue`
- Modify: `resources/js/Layouts/EmpresaLayout.vue`, `SucursalLayout.vue`, `CajeroLayout.vue`, `AuthenticatedLayout.vue`

- [ ] **Step 1: Crear el componente**
`resources/js/Components/Agenda/AgendaBell.vue`:
```vue
<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);

const data = ref({ due_reminders: [], overdue: [], alerts: [], counts: { total: 0 } });
const open = ref(false);
const seenIds = ref(new Set());
const toast = ref(null);
let timer = null;

const poll = async () => {
    if (!slug.value) return;
    try {
        const res = await fetch(route('agenda.notificaciones', slug.value), { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const json = await res.json();
        data.value = json;
        // Toast para due reminders nuevos en esta sesión.
        const fresh = (json.due_reminders ?? []).find(d => !seenIds.value.has(d.id));
        if (fresh && !open.value) { toast.value = fresh; }
    } catch (e) { /* silencioso: degradación elegante */ }
};

const total = computed(() => data.value.counts?.total ?? 0);

const complete = (id) => router.patch(route('agenda.complete', [slug.value, id]), {}, { preserveScroll: true, onSuccess: poll });
const snooze = (id, minutes) => router.patch(route('agenda.snooze', [slug.value, id]), { minutes }, { preserveScroll: true, onSuccess: poll });
const markSeen = (id) => { seenIds.value.add(id); router.patch(route('agenda.visto', [slug.value, id]), {}, { preserveScroll: true, onSuccess: poll }); };
const dismissToast = () => { if (toast.value) { markSeen(toast.value.id); toast.value = null; } };
const toggle = () => {
    open.value = !open.value;
    if (open.value) (data.value.due_reminders ?? []).forEach(d => seenIds.value.add(d.id));
};

onMounted(() => { poll(); timer = setInterval(poll, 60000); });
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <div class="relative">
        <button type="button" @click="toggle" class="relative rounded-lg p-2 text-gray-500 hover:bg-gray-100" title="Avisos de agenda">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
            <span v-if="total" class="absolute -right-0.5 -top-0.5 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white">{{ total > 9 ? '9+' : total }}</span>
        </button>

        <!-- Dropdown -->
        <div v-if="open" class="absolute right-0 z-50 mt-2 w-80 rounded-2xl bg-white p-2 shadow-xl ring-1 ring-gray-100">
            <div class="flex items-center justify-between px-2 py-1.5">
                <span class="text-sm font-bold text-gray-900">Avisos</span>
                <Link :href="route('agenda.index', slug)" class="text-xs font-semibold text-red-600 hover:underline" @click="open = false">Ver agenda →</Link>
            </div>
            <p v-if="!total" class="px-2 py-4 text-center text-sm text-gray-400">Sin avisos.</p>
            <div class="max-h-80 overflow-y-auto">
                <div v-for="d in data.due_reminders" :key="'d'+d.id" class="flex items-center gap-2 rounded-lg px-2 py-2 hover:bg-gray-50">
                    <span class="h-2 w-2 shrink-0 rounded-full bg-violet-500"></span>
                    <span class="min-w-0 flex-1 truncate text-sm text-gray-800">{{ d.title }}</span>
                    <button @click="complete(d.id)" class="text-xs font-semibold text-green-600">Hecho</button>
                    <button @click="snooze(d.id, 30)" class="text-xs font-semibold text-gray-500">+30m</button>
                </div>
                <div v-for="o in data.overdue" :key="'o'+o.id" class="flex items-center gap-2 rounded-lg px-2 py-2 hover:bg-gray-50">
                    <span class="h-2 w-2 shrink-0 rounded-full bg-red-500"></span>
                    <span class="min-w-0 flex-1 truncate text-sm text-gray-800">{{ o.title }} <em class="text-[10px] text-red-500">atrasada</em></span>
                    <button @click="complete(o.id)" class="text-xs font-semibold text-green-600">Hecho</button>
                </div>
                <div v-for="a in data.alerts" :key="a.key" class="flex items-center gap-2 rounded-lg px-2 py-2 hover:bg-gray-50">
                    <span :class="['h-2 w-2 shrink-0 rounded-full', a.severity === 'high' ? 'bg-red-500' : 'bg-amber-400']"></span>
                    <span class="min-w-0 flex-1 truncate text-sm text-gray-700">{{ a.title }}</span>
                </div>
            </div>
        </div>

        <!-- Toast -->
        <Teleport to="body">
            <div v-if="toast" class="fixed bottom-4 right-4 z-[60] w-72 rounded-2xl bg-gray-900 p-4 text-white shadow-2xl">
                <p class="text-xs font-bold uppercase tracking-wide text-violet-300">Recordatorio</p>
                <p class="mt-1 text-sm font-semibold">{{ toast.title }}</p>
                <div class="mt-3 flex gap-2">
                    <button @click="complete(toast.id); dismissToast()" class="rounded-lg bg-green-600 px-3 py-1 text-xs font-bold">Hecho</button>
                    <button @click="snooze(toast.id, 30); dismissToast()" class="rounded-lg bg-white/10 px-3 py-1 text-xs font-bold">+30m</button>
                    <button @click="dismissToast" class="ml-auto text-xs text-gray-400 hover:text-white">Cerrar</button>
                </div>
            </div>
        </Teleport>
    </div>
</template>
```

- [ ] **Step 2: Montar en los 4 layouts.** En cada layout (`EmpresaLayout.vue`, `SucursalLayout.vue`, `CajeroLayout.vue`, `AuthenticatedLayout.vue`), importa `AgendaBell` y colócalo en la barra superior (header), cerca del nombre de usuario o el toggle. Ejemplo en `CajeroLayout.vue` dentro del `<header>`:
```vue
import AgendaBell from '@/Components/Agenda/AgendaBell.vue';
...
<div class="flex flex-1 items-center justify-between">
    <slot name="header" />
    <div class="flex items-center gap-2">
        <AgendaBell />
        <span class="hidden rounded-full bg-green-100 px-3 py-1 text-xs font-bold text-green-700 sm:inline-flex">Cajero</span>
    </div>
</div>
```
Para cada layout, ubica su `<header>`/topbar real y coloca `<AgendaBell />` de forma no intrusiva. NO cambies otra cosa del layout.

- [ ] **Step 3: Build**
`vendor/bin/sail npm run build` → limpio.

- [ ] **Step 4: Commit** (lista los 4 layouts explícitamente para no stagear otros archivos sucios)
```bash
git add resources/js/Components/Agenda/AgendaBell.vue resources/js/Layouts/EmpresaLayout.vue resources/js/Layouts/SucursalLayout.vue resources/js/Layouts/CajeroLayout.vue resources/js/Layouts/AuthenticatedLayout.vue
git commit -m "feat(agenda): campana global con badge, dropdown y toast (polling)"
```

---

## Task 6: Index.vue — secciones Atrasadas/Notas, pestaña Completadas, acciones cancelar/posponer

**Files:**
- Modify: `resources/js/Pages/Agenda/Index.vue`

- [ ] **Step 1: Props nuevas.** Cambia `defineProps` para recibir `overdue` y `notes` (además de `today`, `upcoming`, `alerts`...). Agrega `['completed','Completadas']` al arreglo `tabs`.

- [ ] **Step 2: Acciones.** Agrega helpers:
```js
const cancelItem = (item) => {
    const reason = prompt('Motivo de cancelación (opcional):') ?? '';
    router.patch(route('agenda.cancel', [props.tenant.slug, item.id]), { cancel_reason: reason }, { preserveScroll: true });
};
const snoozeItem = (item, minutes) => router.patch(route('agenda.snooze', [props.tenant.slug, item.id]), { minutes }, { preserveScroll: true });

// Completadas (lazy)
const completedItems = ref([]);
const loadCompleted = async () => {
    const res = await fetch(route('agenda.completadas', props.tenant.slug), { headers: { Accept: 'application/json' } });
    completedItems.value = (await res.json()).items.data;
};
watch(tab, (t) => { if (t === 'completed' && !completedItems.value.length) loadCompleted(); });
```
(Agrega `watch` a los imports de `vue`.)

- [ ] **Step 3: Template.** En la pestaña Hoy, antes del bloque "Hoy y pendiente", agrega una sección **Atrasadas** (solo si `overdue.length`) con estilo rojo (`ring-red-200`, título "Atrasadas"), reutilizando el mismo render de fila pero con un punto rojo; cada fila con botones completar/posponer(+30m)/cancelar/.ics. Después del bloque "Próximos", agrega una sección **Notas** (solo si `notes.length`) listando `notes`. Agrega la vista de la pestaña **Completadas** que itera `completedItems` mostrando título + chip de estado (`it.state === 'cancelled' ? 'Cancelada' : 'Completada'`) + fecha. Mantén el resto igual.

- [ ] **Step 4: Build** → limpio.
- [ ] **Step 5: Commit**
```bash
git add resources/js/Pages/Agenda/Index.vue
git commit -m "feat(agenda): Hoy con Atrasadas/Notas + pestaña Completadas + cancelar/posponer"
```

---

## Task 7: AgendaItemModal — acción cancelar en edición

**Files:**
- Modify: `resources/js/Components/Agenda/AgendaItemModal.vue`

- [ ] **Step 1:** En modo edición (`item` presente y activo), agrega en el footer un botón "Cancelar tarea" (rojo, distinto de "Cerrar") que llame `router.patch(route('agenda.cancel', [tenantSlug, item.id]), { cancel_reason }, { onSuccess: () => emit('close') })` con un `prompt`/textarea para el motivo. No lo muestres si el ítem ya está completado/cancelado.
- [ ] **Step 2: Build** → limpio.
- [ ] **Step 3: Commit**
```bash
git add resources/js/Components/Agenda/AgendaItemModal.vue
git commit -m "feat(agenda): cancelar tarea (con motivo) desde el modal de edición"
```

---

## Task 8: Calendario — estilo de completadas

**Files:**
- Modify: `resources/js/Components/Agenda/AgendaCalendar.vue`

- [ ] **Step 1:** El payload de `calendar` ahora trae `completed_at`. En el render de cada ocurrencia, si `o.completed_at` aplica clase atenuada/tachada (`line-through opacity-60`). Canceladas ya no llegan (excluidas en backend).
- [ ] **Step 2: Build** → limpio.
- [ ] **Step 3: Commit**
```bash
git add resources/js/Components/Agenda/AgendaCalendar.vue
git commit -m "feat(agenda): calendario atenúa ocurrencias completadas"
```

---

## Task 9: Suite + cierre

- [ ] **Step 1:** `vendor/bin/sail artisan test --filter=Agenda` → todos verdes.
- [ ] **Step 2:** `vendor/bin/sail artisan test --compact` → suite completa verde (no romper nada).
- [ ] **Step 3:** `vendor/bin/sail npm run build` → limpio.
- [ ] **Step 4:** Si Pint dejó cambios, commitéalos con paths específicos.

---

## Self-Review (cobertura del spec)

- Estado Cancelada (motivo) → Tasks 1-4 (migración, cancel endpoint, policy). ✅
- Atrasada derivada → Task 2 (scope/accessor), Task 4 (index.overdue, notifications). ✅
- reminder_seen_at + visto + posponer → Tasks 1, 4. ✅
- Pestaña Completadas (historial) → Task 4 (endpoint), Task 6 (UI). ✅
- Sección Atrasadas + Notas (bug notas sin fecha) → Task 4 (index), Task 6 (UI). ✅
- Campana global + badge + dropdown + toast (polling, sin cron) → Task 5. ✅
- Acciones completar/cancelar/posponer desde Hoy/detalle/campana → Tasks 4-7. ✅
- Calendario excluye canceladas + atenúa completadas → Tasks 4 (backend), 8 (front). ✅
- Permisos (cancel = update; posponer/visto = complete) → Tasks 3-4. ✅

**A verificar en ejecución:** el header real de cada layout (Task 5) y que `AuthenticatedLayout` sea el que usa el dashboard de caja; el render exacto de filas en `Index.vue` (Task 6) reusando lo existente.
