# Retiros para cajero + corte con desglose neto — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que el cajero registre y borre retiros de efectivo en su turno abierto, y reestructurar el corte (WhatsApp + pantallas) para mostrar primero el veredicto NETO del turno y luego el desglose por método, corrigiendo el bug del arqueo que no restaba gastos/compras en efectivo.

**Architecture:** Backend Laravel 13 + Vue 3/Inertia. Se reutiliza `WithdrawalController` para las rutas de caja y se amplía su `destroy`. Se crea `ShiftVerdictService` como **fuente única** del veredicto neto (consumido por el texto de WhatsApp y pasado como prop Inertia a las pantallas de corte, sin duplicar la lógica en JS). La UI de retiros se extrae a `WithdrawalsPanel.vue` compartido.

**Tech Stack:** PHP 8.5, Laravel 13, PHPUnit 12, Inertia v2, Vue 3 `<script setup>`, Tailwind v3. Todo corre bajo Sail (`vendor/bin/sail ...`).

**Spec:** `docs/superpowers/specs/2026-07-02-retiros-cajero-y-corte-mejorado-design.md`

---

## File Structure

**Backend**
- `routes/web.php` — 2 rutas nuevas en el grupo `caja`.
- `app/Http/Controllers/Sucursal/WithdrawalController.php` — `destroy` amplía permiso al cajero dueño en turno abierto.
- `app/Services/ShiftVerdictService.php` — **nuevo**. Fuente única del veredicto neto + totales + desglose por método.
- `app/Services/ShiftReportMessageService.php` — consume `ShiftVerdictService`; reestructura el texto; arqueo con gastos/compras.
- `app/Http/Controllers/Caja/TurnoController.php` — `showCorte` pasa prop `verdict`.
- `app/Http/Controllers/Sucursal/CashShiftController.php` — `show` pasa prop `verdict`.

**Frontend**
- `resources/js/Components/Turno/WithdrawalsPanel.vue` — **nuevo** (extraído de `Sucursal/Turno/Active.vue`).
- `resources/js/Pages/Sucursal/Turno/Active.vue` — usa `WithdrawalsPanel`.
- `resources/js/Pages/Caja/Turno/Active.vue` — agrega slot `#extra` con `WithdrawalsPanel`.
- `resources/js/Pages/Caja/Turno/Corte.vue` — renderiza prop `verdict` (veredicto arriba + vendido/cobrado + total neto).
- `resources/js/Pages/Sucursal/Cortes/Show.vue` — idem.

**Tests**
- `tests/Feature/Caja/CajaWithdrawalTest.php` — **nuevo**.
- `tests/Unit/ShiftVerdictServiceTest.php` — **nuevo**.
- `tests/Feature/Services/ShiftReportMessageServiceTest.php` — **reescrito** (la estructura del texto cambia; el test actual asume el formato viejo).

**Convenciones de test verificadas:**
- Todos usan `use RefreshDatabase, SeedsMetricsData;`. El concern expone `$this->tenant`, `$this->branch`, `$this->secondBranch`, `$this->cajero`, `$this->adminSucursal`, `$this->adminEmpresa`, y `seedTenant()`.
- `setUp()` llama `$this->seedTenant(); app()->instance('tenant', $this->tenant);`.
- Auth vía `$this->actingAs($this->cajero)`. Rutas con `route('caja.turno.withdrawal.store', $this->tenant->slug)`.
- No hay factory de `CashRegisterShift`/`CashWithdrawal`: se crean con `::create([...])` (ver ejemplos en `TurnoCorteCashOutTest`).

---

## Parte 1 — Retiros de efectivo para el cajero

### Task 1: Ruta y test de alta de retiro (cajero)

**Files:**
- Test: `tests/Feature/Caja/CajaWithdrawalTest.php` (crear)
- Modify: `routes/web.php` (grupo `caja`, tras `Route::get('turno/corte/{shift}', ...)` en ~línea 517)

- [ ] **Step 1: Escribir el test de alta (falla porque la ruta no existe)**

Crear `tests/Feature/Caja/CajaWithdrawalTest.php`:

```php
<?php

namespace Tests\Feature\Caja;

use App\Models\CashRegisterShift;
use App\Models\CashWithdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CajaWithdrawalTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function openShiftFor(int $userId): CashRegisterShift
    {
        return CashRegisterShift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $userId,
            'opened_at' => now()->subHour(),
            'opening_amount' => 1000,
        ]);
    }

    public function test_cajero_can_register_withdrawal_on_open_shift(): void
    {
        $this->openShiftFor($this->cajero->id);

        $this->actingAs($this->cajero)
            ->post(route('caja.turno.withdrawal.store', $this->tenant->slug), [
                'amount' => 250.50,
                'reason' => 'Compra de bolsas',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('cash_withdrawals', [
            'user_id' => $this->cajero->id,
            'amount' => 250.50,
            'reason' => 'Compra de bolsas',
        ]);
    }
}
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `vendor/bin/sail artisan test --compact --filter=test_cajero_can_register_withdrawal_on_open_shift`
Expected: FAIL (ruta `caja.turno.withdrawal.store` no definida → `RouteNotFoundException`).

- [ ] **Step 3: Agregar las dos rutas de retiro en el grupo `caja`**

En `routes/web.php`, dentro del grupo `Route::middleware('role:cajero|superadmin')->prefix('caja')->name('caja.')`, justo después de la línea `Route::get('turno/corte/{shift}', [CajaTurnoController::class, 'showCorte'])->name('turno.corte');`, agregar:

```php
                Route::post('turno/retiros', [WithdrawalController::class, 'store'])->name('turno.withdrawal.store');
                Route::delete('turno/retiros/{withdrawal}', [WithdrawalController::class, 'destroy'])->name('turno.withdrawal.destroy');
```

Verificar que `WithdrawalController` esté importado al inicio de `routes/web.php`. Ya lo está (lo usa el grupo `sucursal`). Si no aparece, agregar:
`use App\Http\Controllers\Sucursal\WithdrawalController;`

- [ ] **Step 4: Correr el test para verificar que pasa**

Run: `vendor/bin/sail artisan test --compact --filter=test_cajero_can_register_withdrawal_on_open_shift`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Caja/CajaWithdrawalTest.php routes/web.php
git commit -m "feat: ruta de retiros de efectivo para cajero"
```

---

### Task 2: Cajero borra sus retiros solo en turno abierto

**Files:**
- Modify: `app/Http/Controllers/Sucursal/WithdrawalController.php:38-60` (método `destroy`)
- Test: `tests/Feature/Caja/CajaWithdrawalTest.php` (agregar métodos)

- [ ] **Step 1: Escribir los tests de borrado (permiso del cajero + regresión admin)**

Agregar al final de la clase `CajaWithdrawalTest` (antes de la llave de cierre):

```php
    private function makeWithdrawal(CashRegisterShift $shift, int $userId): CashWithdrawal
    {
        return CashWithdrawal::create([
            'shift_id' => $shift->id,
            'user_id' => $userId,
            'amount' => 100,
            'reason' => 'Cambio',
            'created_at' => now(),
        ]);
    }

    public function test_cajero_can_delete_own_withdrawal_on_open_shift(): void
    {
        $shift = $this->openShiftFor($this->cajero->id);
        $w = $this->makeWithdrawal($shift, $this->cajero->id);

        $this->actingAs($this->cajero)
            ->delete(route('caja.turno.withdrawal.destroy', ['tenant' => $this->tenant->slug, 'withdrawal' => $w->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('cash_withdrawals', ['id' => $w->id]);
    }

    public function test_cajero_cannot_delete_withdrawal_on_closed_shift(): void
    {
        $shift = $this->openShiftFor($this->cajero->id);
        $shift->update(['closed_at' => now()]);
        $w = $this->makeWithdrawal($shift, $this->cajero->id);

        $this->actingAs($this->cajero)
            ->delete(route('caja.turno.withdrawal.destroy', ['tenant' => $this->tenant->slug, 'withdrawal' => $w->id]))
            ->assertForbidden();

        $this->assertDatabaseHas('cash_withdrawals', ['id' => $w->id]);
    }

    public function test_cajero_cannot_delete_another_users_withdrawal(): void
    {
        // Otro cajero de la MISMA sucursal, con su propio turno abierto.
        $otherCajero = $this->makeUser('caja2@test.local', 'cajero', $this->branch->id);
        $otherShift = $this->openShiftFor($otherCajero->id);
        $w = $this->makeWithdrawal($otherShift, $otherCajero->id);

        $this->actingAs($this->cajero)
            ->delete(route('caja.turno.withdrawal.destroy', ['tenant' => $this->tenant->slug, 'withdrawal' => $w->id]))
            ->assertForbidden();

        $this->assertDatabaseHas('cash_withdrawals', ['id' => $w->id]);
    }

    public function test_admin_sucursal_can_still_delete_withdrawal_on_closed_shift(): void
    {
        // Regresión: el admin conserva su permiso incluso con turno cerrado.
        $shift = $this->openShiftFor($this->cajero->id);
        $shift->update(['closed_at' => now()]);
        $w = $this->makeWithdrawal($shift, $this->cajero->id);

        $this->actingAs($this->adminSucursal)
            ->delete(route('caja.turno.withdrawal.destroy', ['tenant' => $this->tenant->slug, 'withdrawal' => $w->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('cash_withdrawals', ['id' => $w->id]);
    }
```

Nota: el admin usa la MISMA ruta `caja.*`. El middleware del grupo es `role:cajero|superadmin`, así que `admin-sucursal` no puede entrar por ahí. Para la regresión del admin usar la ruta `sucursal.turno.withdrawal.destroy`:

Cambiar en `test_admin_sucursal_can_still_delete_withdrawal_on_closed_shift` la llamada a:

```php
        $this->actingAs($this->adminSucursal)
            ->delete(route('sucursal.turno.withdrawal.destroy', ['tenant' => $this->tenant->slug, 'withdrawal' => $w->id]))
            ->assertRedirect();
```

- [ ] **Step 2: Correr los tests para verificar que fallan como se espera**

Run: `vendor/bin/sail artisan test --compact --filter=CajaWithdrawalTest`
Expected: `test_cajero_can_delete_own_withdrawal_on_open_shift` FALLA (hoy `destroy` da 403 al cajero); los `cannot`/`admin` pueden pasar o fallar según el estado actual. El objetivo tras el cambio es que TODOS pasen.

- [ ] **Step 3: Ampliar `WithdrawalController@destroy`**

Reemplazar el cuerpo del método `destroy` (líneas 38-60) por:

```php
    public function destroy(CashWithdrawal $withdrawal): RedirectResponse
    {
        $user = Auth::user();

        // Aislamiento tenant/sucursal primero (aplica a todos los roles).
        // Para un retiro de otro tenant, TenantScope hace que $withdrawal->shift
        // resuelva a null y cae en la primera guarda.
        $shift = $withdrawal->shift;

        if (! $shift || $shift->branch_id !== $user->branch_id) {
            abort(403, 'Este retiro no pertenece a tu sucursal.');
        }

        if ($shift->tenant_id !== $user->tenant_id) {
            abort(403, 'Este retiro no pertenece a tu empresa.');
        }

        $isManager = $user->hasRole('admin-sucursal')
            || $user->hasRole('admin-empresa')
            || $user->hasRole('superadmin');

        // El cajero dueño puede borrar SOLO en su propio turno abierto.
        $isOwnerOnOpenShift = $shift->user_id === $user->id
            && $shift->closed_at === null;

        if (! $isManager && ! $isOwnerOnOpenShift) {
            abort(403);
        }

        $withdrawal->delete();

        return back()->with('success', 'Retiro eliminado.');
    }
```

- [ ] **Step 4: Correr los tests para verificar que pasan**

Run: `vendor/bin/sail artisan test --compact --filter=CajaWithdrawalTest`
Expected: PASS (los 5 tests).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Sucursal/WithdrawalController.php tests/Feature/Caja/CajaWithdrawalTest.php
git commit -m "feat: cajero borra sus retiros en turno abierto"
```

---

### Task 3: Componente compartido `WithdrawalsPanel.vue`

**Files:**
- Create: `resources/js/Components/Turno/WithdrawalsPanel.vue`
- Modify: `resources/js/Pages/Sucursal/Turno/Active.vue`

- [ ] **Step 1: Crear `WithdrawalsPanel.vue` (extraído del bloque inline de Sucursal)**

Crear `resources/js/Components/Turno/WithdrawalsPanel.vue`:

```vue
<script setup>
import { useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    withdrawals: { type: Array, default: () => [] },
    storeRouteName: { type: String, required: true },
    destroyRouteName: { type: String, required: true },
    tenantSlug: { type: String, required: true },
});

const showWithdrawal = ref(false);
const withdrawalForm = useForm({ amount: '', reason: '' });

const money = (n) => '$' + (Number(n) || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const formatTime = (iso) => new Date(iso).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });

const submitWithdrawal = () => {
    withdrawalForm.post(route(props.storeRouteName, props.tenantSlug), {
        preserveScroll: true,
        onSuccess: () => { withdrawalForm.reset(); showWithdrawal.value = false; },
    });
};

const deleteWithdrawal = (id) => {
    if (confirm('¿Eliminar este retiro?')) {
        router.delete(route(props.destroyRouteName, [props.tenantSlug, id]), { preserveScroll: true });
    }
};
</script>

<template>
    <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200/70">
        <div class="flex items-center justify-between px-5 py-4 sm:px-6">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-rose-100 text-rose-600">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9.75 14.25 12m0 0 2.25 2.25M14.25 12l2.25-2.25M14.25 12 12 14.25m-2.58 4.92-6.374-6.375a1.125 1.125 0 0 1 0-1.59L9.42 4.83c.21-.211.497-.33.795-.33H19.5a2.25 2.25 0 0 1 2.25 2.25v10.5a2.25 2.25 0 0 1-2.25 2.25h-9.284c-.298 0-.585-.119-.795-.33Z" /></svg>
                </div>
                <div>
                    <h2 class="text-sm font-bold text-gray-900">Retiros de efectivo</h2>
                    <p class="text-xs text-gray-400">Salidas de caja durante el turno</p>
                </div>
            </div>
            <button @click="showWithdrawal = !showWithdrawal" type="button"
                class="rounded-xl px-3 py-1.5 text-sm font-semibold transition"
                :class="showWithdrawal ? 'text-gray-500 hover:bg-gray-100' : 'bg-slate-900 text-white hover:bg-slate-800'">
                {{ showWithdrawal ? 'Cancelar' : '+ Registrar retiro' }}
            </button>
        </div>

        <div v-if="showWithdrawal" class="border-t border-gray-100 bg-gray-50/60 px-5 py-4 sm:px-6">
            <form @submit.prevent="submitWithdrawal" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="sm:w-36">
                    <label class="text-xs font-semibold text-gray-500">Monto</label>
                    <div class="relative mt-1">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">$</span>
                        <input v-model="withdrawalForm.amount" type="number" step="0.01" min="0.01" required placeholder="0.00"
                            class="block w-full rounded-xl border-gray-200 pl-7 font-mono text-sm tabular-nums focus:border-slate-400 focus:ring-slate-300/60" />
                    </div>
                </div>
                <div class="flex-1">
                    <label class="text-xs font-semibold text-gray-500">Motivo</label>
                    <input v-model="withdrawalForm.reason" type="text" required placeholder="Ej. Compra de bolsas"
                        class="mt-1 block w-full rounded-xl border-gray-200 text-sm focus:border-slate-400 focus:ring-slate-300/60" />
                </div>
                <button type="submit" :disabled="withdrawalForm.processing"
                    class="rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:opacity-50">Registrar</button>
            </form>
        </div>

        <div class="divide-y divide-gray-100">
            <div v-for="w in withdrawals" :key="w.id" class="flex items-center justify-between px-5 py-3 sm:px-6">
                <div>
                    <p class="font-mono text-sm font-bold tabular-nums text-gray-900">{{ money(w.amount) }}</p>
                    <p class="text-xs text-gray-400">{{ w.reason }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-xs text-gray-400">{{ formatTime(w.created_at) }}</span>
                    <button @click="deleteWithdrawal(w.id)" type="button" class="text-xs font-medium text-gray-400 transition hover:text-rose-600">Eliminar</button>
                </div>
            </div>
            <div v-if="!withdrawals || withdrawals.length === 0" class="px-6 py-6 text-center text-sm text-gray-400">Sin retiros registrados.</div>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Reemplazar el bloque inline en `Sucursal/Turno/Active.vue`**

Reescribir `resources/js/Pages/Sucursal/Turno/Active.vue` completo:

```vue
<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import CierreTurnoPanel from '@/Components/Turno/CierreTurnoPanel.vue';
import WithdrawalsPanel from '@/Components/Turno/WithdrawalsPanel.vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    shift: Object,
    totals: Object,
    tenant: Object,
    paymentMethods: { type: Array, default: () => ['cash', 'card', 'transfer'] },
});
</script>

<template>
    <Head title="Turno Activo" />
    <SucursalLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Turno Activo</h1>
        </template>

        <CierreTurnoPanel
            :shift="shift"
            :totals="totals"
            :tenant="tenant"
            :payment-methods="paymentMethods"
            close-route-name="sucursal.turno.close">
            <template #extra>
                <WithdrawalsPanel
                    :withdrawals="shift.withdrawals"
                    store-route-name="sucursal.turno.withdrawal.store"
                    destroy-route-name="sucursal.turno.withdrawal.destroy"
                    :tenant-slug="tenant.slug" />
            </template>
        </CierreTurnoPanel>

        <FlashToast />
    </SucursalLayout>
</template>
```

- [ ] **Step 3: Compilar para verificar que no hay errores**

Run: `vendor/bin/sail npm run build`
Expected: build OK, sin errores de import ni de sintaxis Vue.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/Turno/WithdrawalsPanel.vue resources/js/Pages/Sucursal/Turno/Active.vue
git commit -m "refactor: extraer WithdrawalsPanel compartido"
```

---

### Task 4: Montar `WithdrawalsPanel` en la pantalla del cajero

**Files:**
- Modify: `resources/js/Pages/Caja/Turno/Active.vue`

- [ ] **Step 1: Agregar el panel de retiros al turno del cajero**

Reescribir `resources/js/Pages/Caja/Turno/Active.vue` completo:

```vue
<script setup>
import CajeroLayout from '@/Layouts/CajeroLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import CierreTurnoPanel from '@/Components/Turno/CierreTurnoPanel.vue';
import WithdrawalsPanel from '@/Components/Turno/WithdrawalsPanel.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    shift: Object,
    totals: Object,
    tenant: Object,
    paymentMethods: { type: Array, default: () => ['cash', 'card', 'transfer'] },
});
</script>

<template>
    <Head title="Mi Turno" />
    <CajeroLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Mi Turno</h1>
        </template>

        <CierreTurnoPanel
            :shift="shift"
            :totals="totals"
            :tenant="tenant"
            :payment-methods="paymentMethods"
            close-route-name="caja.turno.close">
            <template #extra>
                <WithdrawalsPanel
                    :withdrawals="shift.withdrawals"
                    store-route-name="caja.turno.withdrawal.store"
                    destroy-route-name="caja.turno.withdrawal.destroy"
                    :tenant-slug="tenant.slug" />
            </template>
        </CierreTurnoPanel>

        <FlashToast />
    </CajeroLayout>
</template>
```

Nota: `Caja/TurnoController@index` ya hace `$shift->load('withdrawals')` (línea 67) y recalcula `totals.withdrawals` / `expected_cash` vía `ShiftCashOutCalculator`. No hay cambios de backend.

- [ ] **Step 2: Compilar**

Run: `vendor/bin/sail npm run build`
Expected: build OK.

- [ ] **Step 3: Verificación manual (si hay entorno levantado)**

Abrir el turno del cajero (`el-toro`, `cajero@eltoro.test`), registrar un retiro, verificar que aparece en la lista y que "Efectivo esperado en caja" baja por ese monto. Borrar el retiro y verificar que vuelve a subir.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Caja/Turno/Active.vue
git commit -m "feat: panel de retiros en el turno del cajero"
```

---

## Parte 2 — Corte con desglose neto

### Task 5: `ShiftVerdictService` (fuente única del veredicto)

**Files:**
- Create: `app/Services/ShiftVerdictService.php`
- Test: `tests/Unit/ShiftVerdictServiceTest.php`

- [ ] **Step 1: Escribir el test unitario del servicio**

Crear `tests/Unit/ShiftVerdictServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\CashRegisterShift;
use App\Services\ShiftVerdictService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ShiftVerdictServiceTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private ShiftVerdictService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->svc = new ShiftVerdictService;
    }

    /**
     * Fixture base: efectivo esperado 8700 / declarado 8700 (cuadra),
     * tarjeta 3200 y transferencia 1140, ambos cuadrados.
     */
    private function shift(array $attrs = []): CashRegisterShift
    {
        return CashRegisterShift::make(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'opening_amount' => 500,
            'total_cash' => 8200,
            'total_card' => 3200,
            'total_transfer' => 1140,
            'expected_amount' => 8700,
            'declared_amount' => 8700,
            'declared_card' => 3200,
            'declared_transfer' => 1140,
            'difference' => 0,
            'difference_card' => 0,
            'difference_transfer' => 0,
        ], $attrs));
    }

    public function test_balanced_when_all_zero(): void
    {
        $v = $this->svc->build($this->shift());

        $this->assertSame('balanced', $v['status']);
        $this->assertSame(0.0, $v['total_diff']);
        $this->assertNull($v['detail']);
    }

    public function test_simple_cash_shortage(): void
    {
        $v = $this->svc->build($this->shift([
            'declared_amount' => 8650, 'difference' => -50,
        ]));

        $this->assertSame('net_off', $v['status']);
        $this->assertSame(-50.0, $v['total_diff']);
        $this->assertStringContainsString('Faltante total de $50.00', $v['headline']);
        $this->assertNull($v['detail']);
    }

    public function test_full_compensation_between_methods(): void
    {
        // Falta $50 en efectivo, sobra $50 en tarjeta → el neto cuadra.
        $v = $this->svc->build($this->shift([
            'declared_amount' => 8650, 'difference' => -50,
            'declared_card' => 3250, 'difference_card' => 50,
        ]));

        $this->assertSame('cross_balanced', $v['status']);
        $this->assertSame(0.0, $v['total_diff']);
        $this->assertStringContainsString('cuadra en total', $v['headline']);
        $this->assertStringContainsString('faltan $50.00 en efectivo', $v['detail']);
        $this->assertStringContainsString('sobran $50.00 en tarjeta', $v['detail']);
    }

    public function test_partial_compensation(): void
    {
        // Falta $80 en efectivo, sobra $30 en tarjeta → faltante real $50.
        $v = $this->svc->build($this->shift([
            'declared_amount' => 8620, 'difference' => -80,
            'declared_card' => 3230, 'difference_card' => 30,
        ]));

        $this->assertSame('net_off', $v['status']);
        $this->assertSame(-50.0, $v['total_diff']);
        $this->assertStringContainsString('Faltante total de $50.00', $v['headline']);
        $this->assertStringContainsString('faltan $80.00 en efectivo', $v['detail']);
        $this->assertStringContainsString('sobran $30.00 en tarjeta', $v['detail']);
    }

    public function test_method_with_movement_but_undeclared_is_included(): void
    {
        // Tarjeta no declarada (null) pero con movimiento: debe entrar en la
        // suma con declared = total y diff 0, para que el neto cuadre.
        $v = $this->svc->build($this->shift([
            'declared_card' => null, 'difference_card' => 0,
        ]));

        $card = collect($v['by_method'])->firstWhere('key', 'card');
        $this->assertNotNull($card);
        $this->assertSame(3200.0, $card['expected']);
        $this->assertSame(3200.0, $card['declared']);
        $this->assertTrue($card['declared_is_null']);
        // El total incluye la tarjeta: 8700 (cash) + 3200 + 1140 = 13040.
        $this->assertSame(13040.0, $v['expected_total']);
        $this->assertSame(13040.0, $v['declared_total']);
    }

    public function test_undeclared_when_nothing_declared(): void
    {
        $v = $this->svc->build($this->shift([
            'declared_amount' => null, 'declared_card' => null, 'declared_transfer' => null,
            'difference' => 0, 'difference_card' => 0, 'difference_transfer' => 0,
        ]));

        $this->assertSame('undeclared', $v['status']);
        $this->assertStringContainsString('sin conteo declarado', $v['headline']);
    }
}
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `vendor/bin/sail artisan test --compact --filter=ShiftVerdictServiceTest`
Expected: FAIL (`Class "App\Services\ShiftVerdictService" not found`).

- [ ] **Step 3: Implementar `ShiftVerdictService`**

Crear `app/Services/ShiftVerdictService.php`:

```php
<?php

namespace App\Services;

use App\Models\CashRegisterShift;

/**
 * Fuente única del "veredicto" de un corte: interpreta las diferencias por
 * método (efectivo/tarjeta/transferencia) en términos NETOS del turno.
 *
 * Distingue la compensación: si falta efectivo pero sobra la misma cantidad en
 * tarjeta, la caja cuadra en total aunque un método individual esté descuadrado
 * (típico cobro registrado con el método equivocado).
 *
 * Opera solo sobre campos ya persistidos en el shift, así que reinterpreta
 * cortes históricos sin recálculo. Consumido por ShiftReportMessageService
 * (texto de WhatsApp) y por los controladores de corte (prop Inertia 'verdict').
 *
 * El criterio de "método aplicable" y las derivaciones (expected/declared/diff)
 * son idénticos a los de las tablas Vue Corte.vue / Cortes/Show.vue, para que el
 * total neto siempre iguale la suma de las filas visibles.
 */
class ShiftVerdictService
{
    /**
     * @var array<int,array{key:string,label:string,declaredField:string,diffField:string,expectedField:string,totalField:string}>
     */
    private const METHODS = [
        ['key' => 'cash', 'label' => 'efectivo', 'declaredField' => 'declared_amount', 'diffField' => 'difference', 'expectedField' => 'expected_amount', 'totalField' => 'total_cash'],
        ['key' => 'card', 'label' => 'tarjeta', 'declaredField' => 'declared_card', 'diffField' => 'difference_card', 'expectedField' => 'total_card', 'totalField' => 'total_card'],
        ['key' => 'transfer', 'label' => 'transferencia', 'declaredField' => 'declared_transfer', 'diffField' => 'difference_transfer', 'expectedField' => 'total_transfer', 'totalField' => 'total_transfer'],
    ];

    /**
     * @return array{
     *   status: string, tone: string, headline: string, detail: ?string,
     *   expected_total: float, declared_total: float, total_diff: float,
     *   by_method: array<int,array{key:string,label:string,expected:float,declared:float,diff:float,declared_is_null:bool}>
     * }
     */
    public function build(CashRegisterShift $shift): array
    {
        $byMethod = [];
        foreach (self::METHODS as $m) {
            $declaredRaw = $shift->{$m['declaredField']};
            $total = round((float) $shift->{$m['totalField']}, 2);

            if ($declaredRaw === null && $total <= 0.0) {
                continue; // método no aplicable
            }

            $byMethod[] = [
                'key' => $m['key'],
                'label' => $m['label'],
                'expected' => round((float) $shift->{$m['expectedField']}, 2),
                'declared' => round((float) ($declaredRaw ?? $shift->{$m['totalField']}), 2),
                'diff' => round((float) $shift->{$m['diffField']}, 2),
                'declared_is_null' => $declaredRaw === null,
            ];
        }

        $expectedTotal = round(array_sum(array_column($byMethod, 'expected')), 2);
        $declaredTotal = round(array_sum(array_column($byMethod, 'declared')), 2);
        $totalDiff = round($declaredTotal - $expectedTotal, 2);

        $offMethods = array_values(array_filter($byMethod, fn ($x) => abs($x['diff']) > 0.0));
        $anyMethodOff = count($offMethods) > 0;
        $hasPositive = count(array_filter($offMethods, fn ($x) => $x['diff'] > 0.0)) > 0;
        $hasNegative = count(array_filter($offMethods, fn ($x) => $x['diff'] < 0.0)) > 0;
        $signsMixed = $hasPositive && $hasNegative;
        $allUndeclared = count(array_filter($byMethod, fn ($x) => ! $x['declared_is_null'])) === 0;

        [$status, $tone, $headline, $detail] = $this->verdict($byMethod, $totalDiff, $anyMethodOff, $signsMixed, $allUndeclared);

        return [
            'status' => $status,
            'tone' => $tone,
            'headline' => $headline,
            'detail' => $detail,
            'expected_total' => $expectedTotal,
            'declared_total' => $declaredTotal,
            'total_diff' => $totalDiff,
            'by_method' => $byMethod,
        ];
    }

    /**
     * @param  array<int,array{key:string,label:string,expected:float,declared:float,diff:float,declared_is_null:bool}>  $byMethod
     * @return array{0:string,1:string,2:string,3:?string}
     */
    private function verdict(array $byMethod, float $totalDiff, bool $anyMethodOff, bool $signsMixed, bool $allUndeclared): array
    {
        if ($allUndeclared) {
            return ['undeclared', 'neutral', '📋 Cierre sin conteo declarado.', null];
        }

        if (! $anyMethodOff) {
            return ['balanced', 'ok', '✅ Caja cuadrada — sin diferencias.', null];
        }

        if (abs($totalDiff) <= 0.0) {
            return [
                'cross_balanced',
                'warn',
                '⚖️ La caja cuadra en total, pero hay diferencias cruzadas entre métodos.',
                $this->crossDetail($byMethod).' — posible cobro registrado con otro método.',
            ];
        }

        $word = $totalDiff < 0.0 ? 'Faltante' : 'Sobrante';
        $headline = '⚠️ '.$word.' total de '.$this->money(abs($totalDiff)).'.';

        $detail = null;
        if ($signsMixed) {
            $realWord = $totalDiff < 0.0 ? 'faltante' : 'sobrante';
            $detail = 'El '.$realWord.' real es '.$this->money(abs($totalDiff)).': '.$this->crossDetail($byMethod).'.';
        }

        return ['net_off', 'bad', $headline, $detail];
    }

    /**
     * "faltan $X en efectivo, sobran $Y en tarjeta" — negativos primero.
     *
     * @param  array<int,array{key:string,label:string,expected:float,declared:float,diff:float,declared_is_null:bool}>  $byMethod
     */
    private function crossDetail(array $byMethod): string
    {
        $parts = [];
        foreach ($byMethod as $m) {
            if ($m['diff'] < 0.0) {
                $parts[] = 'faltan '.$this->money(abs($m['diff'])).' en '.$m['label'];
            }
        }
        foreach ($byMethod as $m) {
            if ($m['diff'] > 0.0) {
                $parts[] = 'sobran '.$this->money($m['diff']).' en '.$m['label'];
            }
        }

        return implode(', ', $parts);
    }

    private function money(float $value): string
    {
        return '$'.number_format($value, 2, '.', ',');
    }
}
```

- [ ] **Step 4: Correr el test para verificar que pasa**

Run: `vendor/bin/sail artisan test --compact --filter=ShiftVerdictServiceTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Services/ShiftVerdictService.php tests/Unit/ShiftVerdictServiceTest.php
git commit -m "feat: ShiftVerdictService veredicto neto del corte"
```

---

### Task 6: Reestructurar `ShiftReportMessageService` (texto WhatsApp)

**Files:**
- Modify: `app/Services/ShiftReportMessageService.php` (reescribe `buildShiftCloseText`, agrega constructor, elimina `verdictLine`)
- Modify: `tests/Feature/Services/ShiftReportMessageServiceTest.php` (reescribe aserciones al nuevo formato)

- [ ] **Step 1: Reescribir el test al nuevo formato del texto**

Reemplazar `tests/Feature/Services/ShiftReportMessageServiceTest.php` completo:

```php
<?php

namespace Tests\Feature\Services;

use App\Enums\SaleStatus;
use App\Models\CashRegisterShift;
use App\Models\CashWithdrawal;
use App\Models\Sale;
use App\Services\ShiftReportMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ShiftReportMessageServiceTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private ShiftReportMessageService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->svc = app(ShiftReportMessageService::class);
        Carbon::setTestNow('2026-04-24 20:45:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Fixture base: expected efectivo 8700, declarado 8680 → faltante neto $20
     * (tarjeta y transferencia cuadran).
     */
    private function makeClosedShift(array $attrs = []): CashRegisterShift
    {
        return CashRegisterShift::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'opened_at' => Carbon::parse('2026-04-24 12:30:00'),
            'closed_at' => Carbon::parse('2026-04-24 20:45:00'),
            'opening_amount' => 500,
            'total_cash' => 8200,
            'total_card' => 3200,
            'total_transfer' => 1140,
            'total_cash_expenses' => 0,
            'total_cash_provider_payments' => 0,
            'total_sales' => 12540,
            'sale_count' => 48,
            'sales_generated_amount' => 12540,
            'sales_generated_count' => 48,
            'collections_from_today_amount' => 12540,
            'collections_from_previous_amount' => 0,
            'declared_amount' => 8680,
            'declared_card' => 3200,
            'declared_transfer' => 1140,
            'expected_amount' => 8700,
            'difference' => -20,
            'difference_card' => 0,
            'difference_transfer' => 0,
        ], $attrs));
    }

    public function test_basic_message_contains_key_sections_and_totals(): void
    {
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift());

        $this->assertStringContainsString('*CORTE DE CAJA*', $text);
        $this->assertStringContainsString('Test — Sucursal 1', $text);
        $this->assertStringContainsString('Cierre: 24/04/2026 20:45', $text);
        $this->assertStringContainsString('Cajero: cajero', $text);
        // Veredicto NETO arriba del todo (faltante neto de $20).
        $this->assertStringContainsString('Faltante total de $20.00', $text);
        // Resumen del turno.
        $this->assertStringContainsString('RESUMEN DEL TURNO', $text);
        $this->assertStringContainsString('Vendido: 48 ventas', $text);
        $this->assertStringContainsString('*$12,540.00*', $text);
        $this->assertStringContainsString('Cobrado en el turno: $12,540.00', $text);
        $this->assertStringContainsString('Esperado total', $text);
        // Desglose por método.
        $this->assertStringContainsString('DESGLOSE POR MÉTODO', $text);
        // Arqueo de efectivo con la cuenta explícita.
        $this->assertStringContainsString('ARQUEO DE EFECTIVO', $text);
        $this->assertStringContainsString('Fondo inicial: $500.00', $text);
        $this->assertStringContainsString('+ Efectivo cobrado: $8,200.00', $text);
        $this->assertStringContainsString('Esperado en cajón: *$8,700.00*', $text);
        $this->assertStringContainsString('Contado por el cajero: $8,680.00', $text);
    }

    public function test_separates_today_sales_from_old_debt_collections(): void
    {
        $shift = $this->makeClosedShift([
            'total_cash' => 35000,
            'total_card' => 0,
            'total_transfer' => 0,
            'total_sales' => 35000,
            'sale_count' => 5,
            'sales_generated_amount' => 5000,
            'sales_generated_count' => 4,
            'collections_from_today_amount' => 5000,
            'collections_from_previous_amount' => 30000,
            'declared_amount' => 35500,
            'expected_amount' => 35500,
            'difference' => 0,
            'declared_card' => null,
            'declared_transfer' => null,
        ]);

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringContainsString('Vendido: 4 ventas', $text);
        $this->assertStringContainsString('*$5,000.00*', $text);
        $this->assertStringContainsString('Cobrado en el turno: $35,000.00', $text);
        $this->assertStringContainsString('De ventas del turno: $5,000.00', $text);
        $this->assertStringContainsString('Abonos a fiados anteriores: $30,000.00', $text);
    }

    public function test_omits_split_lines_when_no_old_debt_collections(): void
    {
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift());

        $this->assertStringNotContainsString('De ventas del turno:', $text);
        $this->assertStringNotContainsString('Abonos a fiados anteriores:', $text);
    }

    public function test_includes_cancelled_count_and_amount_when_present(): void
    {
        $shift = $this->makeClosedShift();

        $this->makeCancelledSaleAt('C1', 200, '2026-04-24 14:00:00');
        $this->makeCancelledSaleAt('C2', 140, '2026-04-24 15:00:00');
        $this->makeCancelledSaleAt('C3', 999, '2026-04-23 09:00:00'); // fuera de la ventana

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringContainsString('Canceladas: 2 ($340.00)', $text);
    }

    private function makeCancelledSaleAt(string $folio, float $total, string $createdAt): void
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => $folio,
            'payment_method' => 'cash',
            'total' => $total,
            'amount_paid' => 0,
            'amount_pending' => 0,
            'origin' => 'admin',
            'status' => SaleStatus::Cancelled->value,
        ]);
        DB::table('sales')->where('id', $sale->id)->update(['created_at' => $createdAt]);
    }

    public function test_verdict_says_caja_cuadrada_when_all_zero(): void
    {
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift([
            'declared_amount' => 8700, 'difference' => 0,
        ]));

        $this->assertStringContainsString('Caja cuadrada', $text);
    }

    public function test_verdict_full_compensation_does_not_say_faltante_total(): void
    {
        // Falta $50 efectivo, sobra $50 tarjeta → neto cuadra.
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift([
            'declared_amount' => 8650, 'difference' => -50,
            'declared_card' => 3250, 'difference_card' => 50,
        ]));

        $this->assertStringContainsString('cuadra en total', $text);
        $this->assertStringContainsString('faltan $50.00 en efectivo', $text);
        $this->assertStringContainsString('sobran $50.00 en tarjeta', $text);
        $this->assertStringNotContainsString('Faltante total', $text);
    }

    public function test_verdict_partial_compensation_reports_net(): void
    {
        // Falta $80 efectivo, sobra $30 tarjeta → faltante real $50.
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift([
            'declared_amount' => 8620, 'difference' => -80,
            'declared_card' => 3230, 'difference_card' => 30,
        ]));

        $this->assertStringContainsString('Faltante total de $50.00', $text);
        $this->assertStringContainsString('faltan $80.00 en efectivo', $text);
        $this->assertStringContainsString('sobran $30.00 en tarjeta', $text);
    }

    public function test_arqueo_includes_cash_expenses_and_purchases(): void
    {
        // Bug corregido: el arqueo ahora resta gastos y compras en efectivo.
        // 500 + 8200 − 1000 (retiros) − 300 (gastos) − 200 (compras) = 7200.
        $shift = $this->makeClosedShift([
            'total_cash_expenses' => 300,
            'total_cash_provider_payments' => 200,
            'expected_amount' => 7200,
            'declared_amount' => 7200,
            'difference' => 0,
        ]);
        CashWithdrawal::create([
            'shift_id' => $shift->id, 'user_id' => $this->cajero->id,
            'amount' => 1000, 'reason' => 'Retiro', 'created_at' => Carbon::parse('2026-04-24 18:00:00'),
        ]);

        $text = $this->svc->buildShiftCloseText($shift);

        $this->assertStringContainsString('− Retiros: $1,000.00', $text);
        $this->assertStringContainsString('− Gastos en efectivo: $300.00', $text);
        $this->assertStringContainsString('− Compras en efectivo: $200.00', $text);
        $this->assertStringContainsString('Esperado en cajón: *$7,200.00*', $text);
    }

    public function test_verdict_when_nothing_declared(): void
    {
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift([
            'declared_amount' => null, 'declared_card' => null, 'declared_transfer' => null,
            'difference' => 0, 'difference_card' => 0, 'difference_transfer' => 0,
        ]));

        $this->assertStringContainsString('sin conteo declarado', $text);
        $this->assertStringNotContainsString('Caja cuadrada', $text);
    }

    public function test_includes_notes_when_present(): void
    {
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift(['notes' => 'Sin incidentes']));

        $this->assertStringContainsString('Notas del cajero: Sin incidentes', $text);
    }

    public function test_text_stays_under_max_bytes(): void
    {
        $text = $this->svc->buildShiftCloseText($this->makeClosedShift(['notes' => str_repeat('X', 10000)]));

        $this->assertLessThanOrEqual(3500, strlen($text));
    }
}
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Services/ShiftReportMessageServiceTest.php`
Expected: FAIL (el `new ShiftReportMessageService` viejo ya no existe / el texto aún tiene el formato viejo).

- [ ] **Step 3: Reescribir `buildShiftCloseText` y agregar el constructor**

En `app/Services/ShiftReportMessageService.php`:

3a. Agregar el `use` y el constructor. Tras `use App\Models\Sale;` agregar nada nuevo (el service ya está en el namespace). Reemplazar la línea de apertura de la clase y su primer método. Cambiar:

```php
class ShiftReportMessageService
{
    private const MAX_TEXT_BYTES = 3500;

    public function buildShiftCloseText(CashRegisterShift $shift): string
    {
```

por:

```php
class ShiftReportMessageService
{
    private const MAX_TEXT_BYTES = 3500;

    public function __construct(private ShiftVerdictService $verdictService) {}

    public function buildShiftCloseText(CashRegisterShift $shift): string
    {
```

3b. Reemplazar TODO el cuerpo de `buildShiftCloseText` (desde `$shift->loadMissing(...)` hasta el `return $this->truncateIfNeeded(...)` inclusive) por:

```php
        $shift->loadMissing(['branch:id,tenant_id,name', 'user:id,name', 'withdrawals', 'tenant:id,name']);

        $verdict = $this->verdictService->build($shift);

        $cancelled = $this->cancelledSalesFor($shift);
        $cancelledCount = $cancelled->count();
        $cancelledAmount = (float) $cancelled->sum('total');

        $withdrawalsTotal = (float) $shift->withdrawals->sum('amount');
        $cashExpenses = (float) $shift->total_cash_expenses;
        $cashProviderPayments = (float) $shift->total_cash_provider_payments;
        $totalCollected = (float) $shift->total_cash + (float) $shift->total_card + (float) $shift->total_transfer;
        $fromToday = (float) $shift->collections_from_today_amount;
        $fromPrevious = (float) $shift->collections_from_previous_amount;

        $lines = [];

        // ── Encabezado ─────────────────────────────────────────────
        $lines[] = '*CORTE DE CAJA*';
        if ($shift->tenant && $shift->branch) {
            $lines[] = '_'.$shift->tenant->name.' — '.$shift->branch->name.'_';
        } elseif ($shift->branch) {
            $lines[] = '_'.$shift->branch->name.'_';
        }
        $lines[] = '';

        // Veredicto NETO — lo primero que se ve.
        $lines[] = $verdict['headline'];
        if ($verdict['detail'] !== null) {
            $lines[] = '_'.$verdict['detail'].'_';
        }
        $lines[] = '';

        $lines[] = 'Cierre: '.($shift->closed_at?->format('d/m/Y H:i') ?? '—');
        $lines[] = 'Cajero: '.($shift->user?->name ?? '—');
        $lines[] = 'Turno: '.$this->shiftRange($shift);
        $lines[] = '';
        $lines[] = '━━━━━━━━━━━━━━━━━━';
        $lines[] = '';

        // ── Resumen del turno (vendido → cobrado → esperado vs declarado) ──
        $count = (int) $shift->sales_generated_count;
        $lines[] = '📊 *RESUMEN DEL TURNO*';
        $lines[] = '• Vendido: '.$count.' '.($count === 1 ? 'venta' : 'ventas').' · *'.$this->money($shift->sales_generated_amount).'*';
        if ($cancelledCount > 0) {
            $lines[] = '• Canceladas: '.$cancelledCount.' ('.$this->money($cancelledAmount).') — no cuentan en el total';
        }
        $lines[] = '• Cobrado en el turno: '.$this->money($totalCollected);
        if ($fromPrevious > 0.0) {
            $lines[] = '   ↳ De ventas del turno: '.$this->money($fromToday);
            $lines[] = '   ↳ Abonos a fiados anteriores: '.$this->money($fromPrevious);
        }
        $lines[] = '• Esperado total (todos los métodos): '.$this->money($verdict['expected_total']);
        if ($verdict['status'] === 'undeclared') {
            $lines[] = '• Declarado por el cajero: no declarado';
        } else {
            $lines[] = '• Declarado por el cajero: '.$this->money($verdict['declared_total']);
            $lines[] = (float) $verdict['total_diff'] === 0.0
                ? '• *Diferencia total: '.$this->money(0).'* ✅'
                : '• *Diferencia total: '.$this->signedMoney($verdict['total_diff']).'* ⚠️';
        }
        $lines[] = '';

        // ── Desglose por método (esperado → declarado) ──
        $lines[] = '💳 *DESGLOSE POR MÉTODO*  _esperado → declarado_';
        foreach ($verdict['by_method'] as $m) {
            $label = ucfirst($m['label']);
            if ($m['declared_is_null']) {
                $lines[] = '• '.$label.': '.$this->money($m['expected']).' _(no declarado)_';

                continue;
            }
            $tail = (float) $m['diff'] === 0.0
                ? '✅'
                : '('.$this->signedMoney($m['diff']).' '.($m['diff'] < 0.0 ? 'faltante' : 'sobrante').')';
            $lines[] = '• '.$label.': '.$this->money($m['expected']).' → '.$this->money($m['declared']).' '.$tail;
        }
        $lines[] = '';

        // ── Arqueo de efectivo (cuenta explícita, ahora con gastos y compras) ──
        $lines[] = '🧾 *ARQUEO DE EFECTIVO*';
        $lines[] = '• Fondo inicial: '.$this->money($shift->opening_amount);
        $lines[] = '• + Efectivo cobrado: '.$this->money($shift->total_cash);
        if ($withdrawalsTotal > 0.0) {
            $lines[] = '• − Retiros: '.$this->money($withdrawalsTotal);
        }
        if ($cashExpenses > 0.0) {
            $lines[] = '• − Gastos en efectivo: '.$this->money($cashExpenses);
        }
        if ($cashProviderPayments > 0.0) {
            $lines[] = '• − Compras en efectivo: '.$this->money($cashProviderPayments);
        }
        $lines[] = '• = Esperado en cajón: *'.$this->money($shift->expected_amount).'*';
        if ($shift->declared_amount !== null) {
            $lines[] = '• Contado por el cajero: '.$this->money($shift->declared_amount);
            $lines[] = (float) $shift->difference === 0.0
                ? '• Diferencia: ninguna ✅'
                : '• *Diferencia: '.$this->signedMoney($shift->difference).'* '.$this->diffMarker($shift->difference);
        } else {
            $lines[] = '• Conteo de efectivo no declarado por el cajero';
        }

        // ── Notas ──────────────────────────────────────────────────
        if (! empty($shift->notes)) {
            $lines[] = '';
            $lines[] = '_Notas del cajero: '.trim($shift->notes).'_';
        }

        $lines[] = '';
        $lines[] = '━━━━━━━━━━━━━━━━━━';
        $lines[] = '_Reporte automático del corte_';

        return $this->truncateIfNeeded(implode("\n", $lines));
```

3c. Eliminar el método `verdictLine()` completo (ya no se usa; el veredicto lo da `ShiftVerdictService`). Es el método privado que empieza en `private function verdictLine(CashRegisterShift $shift): string` y termina en su llave de cierre. Conservar `shiftRange`, `cancelledSalesFor`, `money`, `signedMoney`, `diffMarker`, `truncateIfNeeded`.

- [ ] **Step 4: Correr el test para verificar que pasa**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Services/ShiftReportMessageServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Services/ShiftReportMessageService.php tests/Feature/Services/ShiftReportMessageServiceTest.php
git commit -m "feat: corte WhatsApp con veredicto neto y arqueo corregido"
```

---

### Task 7: Pasar prop `verdict` desde los controladores de corte

**Files:**
- Modify: `app/Http/Controllers/Caja/TurnoController.php:203-242` (`showCorte`)
- Modify: `app/Http/Controllers/Sucursal/CashShiftController.php:351-401` (`show`)

- [ ] **Step 1: Inyectar `ShiftVerdictService` y pasar `verdict` en `Caja/TurnoController@showCorte`**

En `app/Http/Controllers/Caja/TurnoController.php`:

1a. En la firma de `showCorte`, agregar el parámetro. Cambiar:

```php
    public function showCorte(
        CashRegisterShift $shift,
        ShiftReportMessageService $reportService,
        WhatsappMessageService $whatsappService,
    ): Response {
```

por:

```php
    public function showCorte(
        CashRegisterShift $shift,
        ShiftReportMessageService $reportService,
        WhatsappMessageService $whatsappService,
        ShiftVerdictService $verdictService,
    ): Response {
```

1b. Agregar el `use` al inicio del archivo, junto a los demás `use App\Services\...`:

```php
use App\Services\ShiftVerdictService;
```

1c. En el `return Inertia::render('Caja/Turno/Corte', [ ... ])`, agregar la prop `verdict`:

```php
        return Inertia::render('Caja/Turno/Corte', [
            'shift' => $shift,
            'tenant' => $tenant,
            'verdict' => $verdictService->build($shift),
            'whatsappUrl' => $whatsappUrl,
            'hasOwnerWhatsapp' => $hasOwnerWhatsapp,
            'autoOpenWhatsapp' => (bool) session('auto_open_whatsapp', false),
        ]);
```

- [ ] **Step 2: Inyectar `ShiftVerdictService` y pasar `verdict` en `Sucursal/CashShiftController@show`**

En `app/Http/Controllers/Sucursal/CashShiftController.php`:

2a. Firma de `show`. Cambiar:

```php
    public function show(
        CashRegisterShift $shift,
        ShiftReportMessageService $reportService,
        WhatsappMessageService $whatsappService,
    ): Response {
```

por:

```php
    public function show(
        CashRegisterShift $shift,
        ShiftReportMessageService $reportService,
        WhatsappMessageService $whatsappService,
        ShiftVerdictService $verdictService,
    ): Response {
```

2b. Agregar `use App\Services\ShiftVerdictService;` junto a los demás `use`.

2c. En `return Inertia::render('Sucursal/Cortes/Show', [ ... ])`, agregar:

```php
            'verdict' => $verdictService->build($shift),
```

(justo después de `'shift' => $shift,`).

- [ ] **Step 3: Verificar que las rutas de corte siguen resolviendo**

Run: `vendor/bin/sail artisan route:list --path=corte` y `vendor/bin/sail artisan route:list --path=cortes`
Expected: las rutas `caja.turno.corte` y `sucursal.cortes.show` aparecen sin error de resolución del controlador.

- [ ] **Step 4: Correr los tests de corte existentes (regresión)**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Caja/TurnoCorteCashOutTest.php tests/Feature/Sucursal/CashShiftCloseTest.php`
Expected: PASS (la prop nueva es aditiva; no rompe aserciones existentes).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Caja/TurnoController.php app/Http/Controllers/Sucursal/CashShiftController.php
git commit -m "feat: prop verdict en pantallas de corte"
```

---

### Task 8: Renderizar el veredicto en `Caja/Turno/Corte.vue`

**Files:**
- Modify: `resources/js/Pages/Caja/Turno/Corte.vue`

- [ ] **Step 1: Aceptar la prop `verdict` y añadir el bloque de veredicto + total neto**

En `resources/js/Pages/Caja/Turno/Corte.vue`:

1a. Agregar `verdict` a `defineProps`:

```js
const props = defineProps({
    shift: Object,
    tenant: Object,
    verdict: { type: Object, default: null },
    whatsappUrl: { type: String, default: null },
    hasOwnerWhatsapp: { type: Boolean, default: false },
    autoOpenWhatsapp: { type: Boolean, default: false },
});
```

1b. Añadir un mapa de tono para el bloque de veredicto. Tras la línea `const money = (v) => ...` en el `<script setup>`, agregar:

```js
const verdictTone = {
    ok: { box: 'bg-emerald-50 ring-emerald-200', title: 'text-emerald-800', body: 'text-emerald-700' },
    warn: { box: 'bg-amber-50 ring-amber-200', title: 'text-amber-800', body: 'text-amber-700' },
    bad: { box: 'bg-rose-50 ring-rose-200', title: 'text-rose-800', body: 'text-rose-700' },
    neutral: { box: 'bg-gray-50 ring-gray-200', title: 'text-gray-700', body: 'text-gray-500' },
};
const toneOf = (v) => verdictTone[v?.tone] ?? verdictTone.neutral;
```

1c. En el `<template>`, justo después del bloque `<!-- Success banner -->` (el `</div>` que cierra el banner verde) y antes del bloque de WhatsApp, insertar el bloque de veredicto:

```vue
            <!-- Veredicto neto del turno -->
            <div v-if="verdict" class="rounded-xl px-5 py-4 ring-1" :class="toneOf(verdict).box">
                <p class="text-sm font-bold" :class="toneOf(verdict).title">{{ verdict.headline }}</p>
                <p v-if="verdict.detail" class="mt-1 text-xs" :class="toneOf(verdict).body">{{ verdict.detail }}</p>
                <div v-if="verdict.status !== 'undeclared'" class="mt-3 grid grid-cols-3 gap-2 text-center">
                    <div>
                        <p class="text-[10px] uppercase tracking-wider text-gray-400">Esperado total</p>
                        <p class="font-mono text-sm font-bold tabular-nums text-gray-700">{{ money(verdict.expected_total) }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase tracking-wider text-gray-400">Declarado total</p>
                        <p class="font-mono text-sm font-bold tabular-nums text-gray-900">{{ money(verdict.declared_total) }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase tracking-wider text-gray-400">Diferencia total</p>
                        <p class="font-mono text-sm font-bold tabular-nums" :class="diffColor(verdict.total_diff)">
                            {{ verdict.total_diff > 0 ? '+' : '' }}{{ money(verdict.total_diff) }}
                        </p>
                    </div>
                </div>
            </div>
```

1d. En el bloque "Header info", añadir "Vendido" junto a "Total cobrado". Cambiar el `<div class="border-t border-gray-100 px-5 py-3 flex items-center justify-between">` que hoy solo muestra "Total cobrado" por:

```vue
                <div class="border-t border-gray-100 px-5 py-3 flex items-center justify-between">
                    <span class="text-xs text-gray-400">Fondo inicial: <span class="font-semibold text-gray-600">{{ money(shift.opening_amount) }}</span></span>
                    <div class="flex items-center gap-4">
                        <p class="text-xs text-gray-400">Vendido: <span class="font-mono font-semibold tabular-nums text-gray-700">{{ money(shift.sales_generated_amount) }}</span></p>
                        <p class="text-sm font-bold text-gray-900">Cobrado: <span class="font-mono tabular-nums">{{ money(shift.total_sales) }}</span></p>
                    </div>
                </div>
```

Nota: la tabla "Resumen por método" existente se conserva sin cambios; el bloque de veredicto la antecede con el total neto interpretado.

- [ ] **Step 2: Compilar**

Run: `vendor/bin/sail npm run build`
Expected: build OK.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Caja/Turno/Corte.vue
git commit -m "feat: veredicto neto en corte del cajero"
```

---

### Task 9: Renderizar el veredicto en `Sucursal/Cortes/Show.vue`

**Files:**
- Modify: `resources/js/Pages/Sucursal/Cortes/Show.vue`

- [ ] **Step 1: Aceptar la prop `verdict` y añadir el bloque de veredicto**

En `resources/js/Pages/Sucursal/Cortes/Show.vue`:

1a. Agregar `verdict` a `defineProps`:

```js
const props = defineProps({
    shift: Object,
    tenant: Object,
    isAdmin: Boolean,
    verdict: { type: Object, default: null },
    paymentMethods: { type: Array, default: () => ['cash', 'card', 'transfer'] },
    whatsappUrl: { type: String, default: null },
    hasOwnerWhatsapp: { type: Boolean, default: false },
    autoOpenWhatsapp: { type: Boolean, default: false },
});
```

1b. Añadir el mapa de tono. Tras `const money = (v) => '$' + Number(v ?? 0).toFixed(2);`, agregar:

```js
const verdictTone = {
    ok: { box: 'bg-emerald-50 ring-emerald-200', title: 'text-emerald-800', body: 'text-emerald-700' },
    warn: { box: 'bg-amber-50 ring-amber-200', title: 'text-amber-800', body: 'text-amber-700' },
    bad: { box: 'bg-rose-50 ring-rose-200', title: 'text-rose-800', body: 'text-rose-700' },
    neutral: { box: 'bg-gray-50 ring-gray-200', title: 'text-gray-700', body: 'text-gray-500' },
};
const toneOf = (v) => verdictTone[v?.tone] ?? verdictTone.neutral;
```

1c. En el `<template>`, dentro de `<div class="mx-auto max-w-3xl space-y-6">`, como PRIMER hijo (antes del bloque WhatsApp `<div v-if="shift.closed_at" ...>`), insertar:

```vue
            <!-- Veredicto neto del turno -->
            <div v-if="verdict" class="rounded-xl px-5 py-4 ring-1" :class="toneOf(verdict).box">
                <p class="text-sm font-bold" :class="toneOf(verdict).title">{{ verdict.headline }}</p>
                <p v-if="verdict.detail" class="mt-1 text-xs" :class="toneOf(verdict).body">{{ verdict.detail }}</p>
                <div v-if="verdict.status !== 'undeclared'" class="mt-3 grid grid-cols-3 gap-2 text-center">
                    <div>
                        <p class="text-[10px] uppercase tracking-wider text-gray-400">Esperado total</p>
                        <p class="font-mono text-sm font-bold tabular-nums text-gray-700">{{ money(verdict.expected_total) }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase tracking-wider text-gray-400">Declarado total</p>
                        <p class="font-mono text-sm font-bold tabular-nums text-gray-900">{{ money(verdict.declared_total) }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase tracking-wider text-gray-400">Diferencia total</p>
                        <p class="font-mono text-sm font-bold tabular-nums" :class="diffColor(verdict.total_diff)">
                            {{ verdict.total_diff > 0 ? '+' : '' }}{{ money(verdict.total_diff) }}
                        </p>
                    </div>
                </div>
            </div>
```

Nota: la sección "Conciliación por método" y su "Diferencia total del turno" existentes se conservan; el bloque de veredicto las antecede como resumen narrativo.

- [ ] **Step 2: Compilar**

Run: `vendor/bin/sail npm run build`
Expected: build OK.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Sucursal/Cortes/Show.vue
git commit -m "feat: veredicto neto en corte de sucursal"
```

---

### Task 10: Verificación integral

**Files:** ninguno (solo verificación)

- [ ] **Step 1: Correr toda la suite de tests de turno/corte/retiros**

Run:
```bash
vendor/bin/sail artisan test --compact --filter="CajaWithdrawal|ShiftVerdictService|ShiftReportMessageService|TurnoCorteCashOut|CashShiftClose"
```
Expected: PASS en todos.

- [ ] **Step 2: Build de producción**

Run: `vendor/bin/sail npm run build`
Expected: build OK sin warnings de imports rotos.

- [ ] **Step 3: Verificación manual del flujo completo (si hay entorno)**

Con datos demo (`el-toro`):
1. Como `cajero@eltoro.test`: abrir turno, registrar y borrar un retiro (verificar que el esperado se ajusta).
2. Registrar un gasto en efectivo y una compra en efectivo.
3. Cerrar el turno declarando efectivo de menos y tarjeta de más por el mismo monto → el corte debe decir "La caja cuadra en total, pero hay diferencias cruzadas".
4. Verificar el texto de WhatsApp: el arqueo resta gastos y compras y el "Esperado en cajón" cuadra con la cuenta.
5. Como `sucursal@eltoro.test`: abrir el corte en `Sucursal > Cortes` y verificar el mismo veredicto arriba.

- [ ] **Step 4: Suite completa (opcional, confirmar con el usuario)**

Run: `vendor/bin/sail artisan test --compact`
Expected: PASS. Si algo no relacionado falla, reportar sin corregir fuera de alcance.

---

## Self-Review

**1. Cobertura del spec:**
- Parte 1 (rutas caja + destroy ampliado + WithdrawalsPanel) → Tasks 1-4. ✅
- Parte 2 (ShiftVerdictService, ShiftReportMessageService, prop verdict, pantallas, fix arqueo) → Tasks 5-9. ✅
- Tests: `CajaWithdrawalTest`, `ShiftVerdictServiceTest`, `ShiftReportMessageServiceTest` reescrito → Tasks 1-2, 5, 6. ✅
- Fix bug arqueo (gastos/compras) → Task 6, cubierto por `test_arqueo_includes_cash_expenses_and_purchases`. ✅

**2. Placeholders:** ninguno; todo el código está completo.

**3. Consistencia de tipos:** `verdict` shape (`status`, `tone`, `headline`, `detail`, `expected_total`, `declared_total`, `total_diff`, `by_method[]`) es idéntico entre `ShiftVerdictService::build` (Task 5), el consumo en `ShiftReportMessageService` (Task 6), las props de los controladores (Task 7) y el render Vue (Tasks 8-9). El criterio de aplicabilidad (`declared_* !== null || total_* > 0`) y las derivaciones (`declared = declared_* ?? total_*`, `diff = difference_*`) coinciden con las tablas Vue existentes.

**Riesgo conocido:** las pantallas Vue no tienen runner de tests JS; se verifican por `npm run build` + verificación manual (Task 10). La lógica del veredicto (lo delicado) vive en PHP y sí está cubierta por tests.
