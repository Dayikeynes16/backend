# Adjuntos Unificados — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace three inconsistent "attach a file" UIs (Gastos, Compras, comprobantes de pago) with one shared `AttachmentsPicker.vue` component (camera + drag&drop + thumbnails + full-screen viewer), and fix two pre-existing bugs that block Caja from seeing gasto/compra attachments at all today.

**Architecture:** A new presentational Vue component (`resources/js/Components/AttachmentsPicker.vue`) owns file selection, client-side validation, drag&drop, and thumbnail/pending-upload rendering — it never makes network requests itself. Two operating modes cover the two existing data-flow shapes already in this codebase: `mode="staged"` (Gastos/Compras: files queue locally, submit with the rest of the form) and `mode="immediate"` (comprobantes de pago: each file upload/delete is its own request, no surrounding form). The already-generic `AttachmentViewerModal.vue` moves out of `Components/Gastos/` to become genuinely shared. On the backend, comprobantes de pago gain `preview()` (inline) endpoints mirroring `ExpenseAttachmentController@preview`, and Caja gains 6 new attachment routes for Gastos/Compras — which requires splitting `authorizeAccess()` into `authorizeView()`/`authorizeMutation()` in the two existing attachment controllers so a cajero can't read or delete another cajero's evidence.

**Tech Stack:** Laravel 13 + Inertia v2 + Vue 3 (Composition API, `<script setup>`), PHPUnit, all commands via `vendor/bin/sail`.

## Global Constraints

- All commands run through Sail: `vendor/bin/sail artisan …`, `vendor/bin/sail npm run …`, `vendor/bin/sail bin pint …`.
- No JS test runner exists in this project — frontend verification is `vendor/bin/sail npm run build` (must stay green) plus a manual click-through at the end (per spec's "Riesgo" section). Do not invent a JS test suite.
- Run `vendor/bin/sail bin pint --dirty --format agent` after any PHP change and fix anything it reports before committing.
- Every task ends with its own commit. Do not amend previous commits; create new ones.
- Allowed file mime types everywhere in this feature: `image/jpeg`, `image/png`, `image/webp`, `application/pdf`. Max size 5 MB per file (`5 * 1024 * 1024` bytes).
- `AttachmentsPicker.vue` never calls `route()`/`fetch()`/`router.*` itself and never decides permissions — the parent always owns that (mirrors `PaymentReceiptsPanel.vue`'s existing documented convention).
- This repo's working tree may be shared with other concurrent sessions — before `git add`, run `git status --short` and stage only the files this plan's task touched. Never `git add -A` or `git add .`.
- Source of truth for every decision below is `docs/superpowers/specs/2026-07-17-adjuntos-unificados-design.md` — if a step here ever seems to contradict it, the spec wins and the plan should be corrected.

---

### Task 1: Move `AttachmentViewerModal.vue` to a shared location

**Files:**
- Create: `resources/js/Components/AttachmentViewerModal.vue`
- Delete: `resources/js/Components/Gastos/AttachmentViewerModal.vue`
- Modify: `resources/js/Components/Gastos/GastoFormModal.vue:5` (import path only)
- Modify: `resources/js/Components/Gastos/GastoDetailModal.vue:3` (import path only)

**Interfaces:**
- Produces: `@/Components/AttachmentViewerModal.vue`, a Vue component with props `{ show: Boolean, attachments: Array, initialIndex: Number, previewUrl: Function, downloadUrl: Function }` and emits `close`. (Unchanged from today — this task is a pure file move, zero logic changes.)

This is a pure relocation with no behavior change, so both existing consumers (`GastoFormModal.vue`, `GastoDetailModal.vue`) continue to work exactly as before — the build is the test.

- [ ] **Step 1: Create the file at its new shared path with the exact current content**

Read the current file first to confirm it hasn't changed, then create the new file with this exact content:

```vue
<script setup>
/**
 * AttachmentViewerModal — visor inline de adjuntos (imagen + PDF).
 *
 * Acepta una lista de adjuntos y un índice activo. Permite navegar prev/next
 * (si hay varios), descargar y cerrar. NO descarga al abrir.
 */
import { computed, ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    attachments: { type: Array, default: () => [] },
    initialIndex: { type: Number, default: 0 },
    /** Builder que recibe (attachment) y retorna URL de preview. */
    previewUrl: { type: Function, required: true },
    /** Builder que recibe (attachment) y retorna URL de descarga forzada. */
    downloadUrl: { type: Function, required: true },
});

const emit = defineEmits(['close']);

const index = ref(props.initialIndex);

watch(() => props.initialIndex, (v) => { index.value = v; });
watch(() => props.show, (v) => { if (v) index.value = props.initialIndex; });

const current = computed(() => props.attachments[index.value] || null);
const total = computed(() => props.attachments.length);

const isImage = (att) => att?.mime_type?.startsWith('image/');
const isPdf = (att) => att?.mime_type === 'application/pdf';
const isPreviewable = (att) => isImage(att) || isPdf(att);

const fmtSize = (b) => {
    if (b == null) return '';
    if (b < 1024) return `${b} B`;
    if (b < 1024 * 1024) return `${(b / 1024).toFixed(1)} KB`;
    return `${(b / (1024 * 1024)).toFixed(1)} MB`;
};

const prev = () => {
    if (total.value <= 1) return;
    index.value = (index.value - 1 + total.value) % total.value;
};
const next = () => {
    if (total.value <= 1) return;
    index.value = (index.value + 1) % total.value;
};

const onKey = (e) => {
    if (!props.show) return;
    if (e.key === 'Escape') emit('close');
    if (e.key === 'ArrowLeft') prev();
    if (e.key === 'ArrowRight') next();
};

import { onBeforeUnmount, onMounted } from 'vue';
onMounted(() => document.addEventListener('keydown', onKey));
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition duration-150" leave-active-class="transition duration-100" enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="show && current" class="fixed inset-0 z-[60] flex flex-col bg-black/85 backdrop-blur-sm" @click.self="$emit('close')">
                <!-- Top bar -->
                <div class="flex items-center justify-between gap-3 border-b border-white/10 px-4 py-3 text-white sm:px-6">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-bold">{{ current.original_name }}</p>
                        <p class="text-[11px] text-white/60">
                            <span class="uppercase">{{ current.mime_type?.split('/')[1] || 'archivo' }}</span>
                            <span v-if="current.size_bytes" class="ml-2">{{ fmtSize(current.size_bytes) }}</span>
                            <span v-if="total > 1" class="ml-3">{{ index + 1 }} / {{ total }}</span>
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <a :href="downloadUrl(current)"
                            class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-white/10 px-3 text-xs font-bold text-white transition hover:bg-white/20"
                            download>
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                            Descargar
                        </a>
                        <button @click="$emit('close')" class="flex h-9 w-9 items-center justify-center rounded-lg bg-white/10 text-white transition hover:bg-white/20" aria-label="Cerrar">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                </div>

                <!-- Content -->
                <div class="relative flex flex-1 items-center justify-center overflow-hidden p-4 sm:p-8">
                    <!-- Prev/Next -->
                    <button v-if="total > 1" @click="prev" aria-label="Anterior"
                        class="absolute left-3 top-1/2 z-10 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20 sm:left-6">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    </button>
                    <button v-if="total > 1" @click="next" aria-label="Siguiente"
                        class="absolute right-3 top-1/2 z-10 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20 sm:right-6">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </button>

                    <!-- Image -->
                    <img v-if="isImage(current)"
                        :src="previewUrl(current)"
                        :alt="current.original_name"
                        class="max-h-full max-w-full rounded-xl object-contain shadow-2xl" />

                    <!-- PDF -->
                    <iframe v-else-if="isPdf(current)"
                        :src="previewUrl(current)"
                        :title="current.original_name"
                        class="h-full w-full max-w-5xl rounded-xl bg-white"
                        frameborder="0" />

                    <!-- Unsupported -->
                    <div v-else class="rounded-2xl bg-white/5 px-8 py-12 text-center text-white">
                        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-white/10">
                            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                        </div>
                        <p class="mt-4 text-base font-bold">No se puede previsualizar este archivo</p>
                        <p class="mt-1 text-sm text-white/60">Descárgalo para abrirlo en tu equipo.</p>
                        <a :href="downloadUrl(current)" download
                            class="mt-5 inline-flex h-10 items-center gap-2 rounded-lg bg-white px-5 text-sm font-bold text-gray-900 transition hover:bg-gray-100">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                            Descargar
                        </a>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
```

- [ ] **Step 2: Delete the old file**

```bash
rm "resources/js/Components/Gastos/AttachmentViewerModal.vue"
```

- [ ] **Step 3: Update the import in `GastoFormModal.vue`**

In `resources/js/Components/Gastos/GastoFormModal.vue`, change line 5:

```js
// Before
import AttachmentViewerModal from '@/Components/Gastos/AttachmentViewerModal.vue';
// After
import AttachmentViewerModal from '@/Components/AttachmentViewerModal.vue';
```

- [ ] **Step 4: Update the import in `GastoDetailModal.vue`**

In `resources/js/Components/Gastos/GastoDetailModal.vue`, change line 3:

```js
// Before
import AttachmentViewerModal from '@/Components/Gastos/AttachmentViewerModal.vue';
// After
import AttachmentViewerModal from '@/Components/AttachmentViewerModal.vue';
```

- [ ] **Step 5: Build to verify nothing broke**

```bash
vendor/bin/sail npm run build
```

Expected: green build, no "failed to resolve import" errors for `AttachmentViewerModal.vue`.

- [ ] **Step 6: Regression-check the two consumers still pass their existing PHP tests**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Sucursal/GastoControllerTest.php
```

Expected: all tests pass (this file's attachment tests exercise the routes the Vue components call — no backend changed, so this just confirms nothing about the request/response shape regressed).

- [ ] **Step 7: Commit**

```bash
git status --short
git add resources/js/Components/AttachmentViewerModal.vue resources/js/Components/Gastos/GastoFormModal.vue resources/js/Components/Gastos/GastoDetailModal.vue
git status --short  # confirm the old Gastos/AttachmentViewerModal.vue shows as deleted (D) and is included
git add -u resources/js/Components/Gastos/  # stage the deletion specifically
git commit -m "$(cat <<'EOF'
refactor(adjuntos): mueve AttachmentViewerModal.vue a Components/ (compartido)

Primer paso de la unificación de adjuntos (spec 2026-07-17): el visor ya era
100% genérico por props, solo vivía en la carpeta de Gastos. Sin cambios de
comportamiento — GastoFormModal.vue y GastoDetailModal.vue solo actualizan
la ruta del import.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Backend — `preview()` endpoints for comprobantes de pago

**Files:**
- Modify: `app/Http/Controllers/Sucursal/PaymentReceiptController.php` (add `preview()` method + import)
- Modify: `app/Http/Controllers/Sucursal/CustomerPaymentReceiptController.php` (add `preview()` method + import)
- Modify: `routes/web.php` (4 new routes)
- Modify: `tests/Feature/Sucursal/PaymentReceiptTest.php` (1 new test)
- Modify: `tests/Feature/Sucursal/CustomerPaymentReceiptTest.php` (1 new test)

**Interfaces:**
- Produces: routes `sucursal.pagos.receipts.preview`, `caja.pagos.receipts.preview`, `sucursal.cobros.receipts.preview`, `caja.cobros.receipts.preview` — each `GET`, same URL shape as the existing `.download` routes with `/preview` appended, same auth as `.download` (`authorizeView()`, no shift/ownership check).

This task is entirely backend and independent of the Vue work — it's the endpoint `AttachmentsPicker.vue`'s `previewUrl` prop will call once wired in Task 3.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Sucursal/PaymentReceiptTest.php`, add this test right after `test_flag_off_returns_403` (end of file, before the closing `}`):

```php
    public function test_preview_returns_inline_disposition(): void
    {
        Storage::fake(PaymentReceiptService::disk());
        [, $payment] = $this->makeSaleWithTransferPayment();

        $this->actingAs($this->adminSucursal)->post(
            route('sucursal.pagos.receipts.store', [$this->tenant->slug, $payment->id]),
            ['receipts' => [UploadedFile::fake()->image('captura.jpg')]],
        )->assertSessionHas('success');

        $receipt = $payment->receipts()->firstOrFail();

        $response = $this->actingAs($this->adminSucursal)->get(
            route('sucursal.pagos.receipts.preview', [$this->tenant->slug, $payment->id, $receipt->id]),
        );
        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition') ?? '');

        // Mismo gate de rol que .download: un cajero sin turno/dueño del pago no puede.
        $this->actingAs($this->cajero)->get(
            route('sucursal.pagos.receipts.preview', [$this->tenant->slug, $payment->id, $receipt->id]),
        )->assertForbidden();
    }
```

In `tests/Feature/Sucursal/CustomerPaymentReceiptTest.php`, add this test right after `test_admin_attaches_receipt_later_via_sucursal_route` (around line 177, before the `NOTA:` comment that precedes `test_owner_cajero_attaches_and_downloads_receipt_via_caja_route_within_shift`):

```php
    public function test_preview_returns_inline_disposition(): void
    {
        Storage::fake(PaymentReceiptService::disk());
        $cg = $this->makeCustomerPayment($this->adminSucursal);

        $this->actingAs($this->adminSucursal)->post(
            route('sucursal.cobros.receipts.store', [$this->tenant->slug, $cg->id]),
            ['receipts' => [UploadedFile::fake()->image('tarde.jpg')]],
        )->assertSessionHas('success');

        $receipt = $cg->receipts()->firstOrFail();

        $response = $this->actingAs($this->adminSucursal)->get(
            route('sucursal.cobros.receipts.preview', [$this->tenant->slug, $cg->id, $receipt->id]),
        );
        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition') ?? '');
    }
```

- [ ] **Step 2: Run the tests to verify they fail (route not found)**

```bash
vendor/bin/sail artisan test --compact --filter=test_preview_returns_inline_disposition
```

Expected: FAIL — `sucursal.pagos.receipts.preview`/`sucursal.cobros.receipts.preview` routes don't exist yet (`RouteNotFoundException` or similar from the `route()` helper).

- [ ] **Step 3: Add the `preview()` method to `PaymentReceiptController.php`**

In `app/Http/Controllers/Sucursal/PaymentReceiptController.php`, add `Illuminate\Http\Response` to the imports:

```php
// Before
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
// After
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
```

Then add the `preview()` method right after `download()`:

```php
    public function download(Payment $payment, PaymentReceipt $receipt): StreamedResponse
    {
        $user = Auth::user();
        $this->authorizeView($user, $payment);
        abort_unless($receipt->payment_id === $payment->id, 404);

        return Storage::disk(PaymentReceiptService::disk())->download($receipt->path, $receipt->original_name);
    }

    /**
     * Vista previa en línea (no descarga). Sirve el archivo con
     * Content-Disposition: inline para que el navegador lo muestre
     * directamente (img/iframe). Espejo de ExpenseAttachmentController@preview.
     */
    public function preview(Payment $payment, PaymentReceipt $receipt): Response
    {
        $user = Auth::user();
        $this->authorizeView($user, $payment);
        abort_unless($receipt->payment_id === $payment->id, 404);

        $disk = Storage::disk(PaymentReceiptService::disk());
        if (! $disk->exists($receipt->path)) {
            abort(404, 'Archivo no encontrado.');
        }

        return response($disk->get($receipt->path), 200, [
            'Content-Type' => $receipt->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($receipt->original_name).'"',
            'Content-Length' => (string) $receipt->size_bytes,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
```

- [ ] **Step 4: Add the `preview()` method to `CustomerPaymentReceiptController.php`**

Same import addition (`use Illuminate\Http\Response;`), then add `preview()` right after `download()`:

```php
    public function download(CustomerPayment $customerPayment, PaymentReceipt $receipt): StreamedResponse
    {
        $user = Auth::user();
        $this->authorizeView($user, $customerPayment);
        abort_unless($receipt->customer_payment_id === $customerPayment->id, 404);

        return Storage::disk(PaymentReceiptService::disk())->download($receipt->path, $receipt->original_name);
    }

    /**
     * Vista previa en línea (no descarga). Espejo de
     * ExpenseAttachmentController@preview / PaymentReceiptController@preview.
     */
    public function preview(CustomerPayment $customerPayment, PaymentReceipt $receipt): Response
    {
        $user = Auth::user();
        $this->authorizeView($user, $customerPayment);
        abort_unless($receipt->customer_payment_id === $customerPayment->id, 404);

        $disk = Storage::disk(PaymentReceiptService::disk());
        if (! $disk->exists($receipt->path)) {
            abort(404, 'Archivo no encontrado.');
        }

        return response($disk->get($receipt->path), 200, [
            'Content-Type' => $receipt->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($receipt->original_name).'"',
            'Content-Length' => (string) $receipt->size_bytes,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
```

- [ ] **Step 5: Add the 4 routes in `routes/web.php`**

Find this block (sucursal group, comprobantes de pago — currently 3 lines):

```php
                // Comprobantes de pago (adjuntar después / descargar / eliminar)
                Route::post('pagos/{payment}/comprobantes', [PaymentReceiptController::class, 'store'])->whereNumber('payment')->name('pagos.receipts.store');
                Route::get('pagos/{payment}/comprobantes/{receipt}', [PaymentReceiptController::class, 'download'])->whereNumber('payment')->whereNumber('receipt')->name('pagos.receipts.download');
                Route::delete('pagos/{payment}/comprobantes/{receipt}', [PaymentReceiptController::class, 'destroy'])->whereNumber('payment')->whereNumber('receipt')->name('pagos.receipts.destroy');
```

Replace with (adds the `preview` line):

```php
                // Comprobantes de pago (adjuntar después / descargar / previsualizar / eliminar)
                Route::post('pagos/{payment}/comprobantes', [PaymentReceiptController::class, 'store'])->whereNumber('payment')->name('pagos.receipts.store');
                Route::get('pagos/{payment}/comprobantes/{receipt}', [PaymentReceiptController::class, 'download'])->whereNumber('payment')->whereNumber('receipt')->name('pagos.receipts.download');
                Route::get('pagos/{payment}/comprobantes/{receipt}/preview', [PaymentReceiptController::class, 'preview'])->whereNumber('payment')->whereNumber('receipt')->name('pagos.receipts.preview');
                Route::delete('pagos/{payment}/comprobantes/{receipt}', [PaymentReceiptController::class, 'destroy'])->whereNumber('payment')->whereNumber('receipt')->name('pagos.receipts.destroy');
```

This exact 3-line block appears **twice** (sucursal group and caja group) — apply the same edit to **both** occurrences.

Find this block (sucursal group, comprobantes de cobro global):

```php
                // Comprobantes de cobro global (adjuntar después / descargar / eliminar)
                Route::post('cobros/{customerPayment}/comprobantes', [CustomerPaymentReceiptController::class, 'store'])->whereNumber('customerPayment')->name('cobros.receipts.store');
                Route::get('cobros/{customerPayment}/comprobantes/{receipt}', [CustomerPaymentReceiptController::class, 'download'])->whereNumber('customerPayment')->whereNumber('receipt')->name('cobros.receipts.download');
                Route::delete('cobros/{customerPayment}/comprobantes/{receipt}', [CustomerPaymentReceiptController::class, 'destroy'])->whereNumber('customerPayment')->whereNumber('receipt')->name('cobros.receipts.destroy');
```

Replace with:

```php
                // Comprobantes de cobro global (adjuntar después / descargar / previsualizar / eliminar)
                Route::post('cobros/{customerPayment}/comprobantes', [CustomerPaymentReceiptController::class, 'store'])->whereNumber('customerPayment')->name('cobros.receipts.store');
                Route::get('cobros/{customerPayment}/comprobantes/{receipt}', [CustomerPaymentReceiptController::class, 'download'])->whereNumber('customerPayment')->whereNumber('receipt')->name('cobros.receipts.download');
                Route::get('cobros/{customerPayment}/comprobantes/{receipt}/preview', [CustomerPaymentReceiptController::class, 'preview'])->whereNumber('customerPayment')->whereNumber('receipt')->name('cobros.receipts.preview');
                Route::delete('cobros/{customerPayment}/comprobantes/{receipt}', [CustomerPaymentReceiptController::class, 'destroy'])->whereNumber('customerPayment')->whereNumber('receipt')->name('cobros.receipts.destroy');
```

For the **caja group**'s cobros block, there is **no** `destroy` line (caja never deletes CG receipts) — find:

```php
                Route::post('cobros/{customerPayment}/comprobantes', [CustomerPaymentReceiptController::class, 'store'])->whereNumber('customerPayment')->name('cobros.receipts.store');
                Route::get('cobros/{customerPayment}/comprobantes/{receipt}', [CustomerPaymentReceiptController::class, 'download'])->whereNumber('customerPayment')->whereNumber('receipt')->name('cobros.receipts.download');
```

(with no `destroy` line following it inside that group) and replace with:

```php
                Route::post('cobros/{customerPayment}/comprobantes', [CustomerPaymentReceiptController::class, 'store'])->whereNumber('customerPayment')->name('cobros.receipts.store');
                Route::get('cobros/{customerPayment}/comprobantes/{receipt}', [CustomerPaymentReceiptController::class, 'download'])->whereNumber('customerPayment')->whereNumber('receipt')->name('cobros.receipts.download');
                Route::get('cobros/{customerPayment}/comprobantes/{receipt}/preview', [CustomerPaymentReceiptController::class, 'preview'])->whereNumber('customerPayment')->whereNumber('receipt')->name('cobros.receipts.preview');
```

Verify the route count after editing:

```bash
vendor/bin/sail artisan route:list --name=receipts
```

Expected: 15 routes total (was 11 before this task — 4 new `.preview` routes: `sucursal.pagos.receipts.preview`, `caja.pagos.receipts.preview`, `sucursal.cobros.receipts.preview`, `caja.cobros.receipts.preview`).

- [ ] **Step 6: Run pint**

```bash
vendor/bin/sail bin pint --dirty --format agent
```

Expected: `{"result":"pass"}` (or auto-fixed — re-run if it reports changes).

- [ ] **Step 7: Run the tests to verify they pass**

```bash
vendor/bin/sail artisan test --compact --filter=test_preview_returns_inline_disposition
```

Expected: 2 passed.

- [ ] **Step 8: Regression — full receipt test files + route list sanity**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Sucursal/PaymentReceiptTest.php tests/Feature/Sucursal/CustomerPaymentReceiptTest.php
```

Expected: all passed (no regressions in the existing store/download/destroy tests).

- [ ] **Step 9: Commit**

```bash
git status --short
git add app/Http/Controllers/Sucursal/PaymentReceiptController.php app/Http/Controllers/Sucursal/CustomerPaymentReceiptController.php routes/web.php tests/Feature/Sucursal/PaymentReceiptTest.php tests/Feature/Sucursal/CustomerPaymentReceiptTest.php
git commit -m "$(cat <<'EOF'
feat(pagos): endpoint de vista previa inline para comprobantes de pago

Espejo exacto de ExpenseAttachmentController@preview. 4 rutas nuevas
(sucursal/caja × pagos/cobros .receipts.preview), mismo authorizeView()
que .download (solo lectura, sin regla de turno). Necesario para que el
visor de pantalla completa (AttachmentViewerModal) pueda mostrar la
imagen/PDF sin forzar su descarga.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: `AttachmentsPicker.vue` (new component) + refactor `PaymentReceiptsPanel.vue`

**Files:**
- Create: `resources/js/Components/AttachmentsPicker.vue`
- Modify: `resources/js/Components/PaymentReceiptsPanel.vue`

**Interfaces:**
- Produces: `AttachmentsPicker.vue` with the props/emits contract below — this is what Tasks 4 and 5 will also consume.
- Consumes (from Task 1): `@/Components/AttachmentViewerModal.vue` (props `show`, `attachments`, `initialIndex`, `previewUrl`, `downloadUrl`, emits `close`).
- Consumes (existing, unmodified): `@/Components/CameraCaptureModal.vue` (`v-model:open`, emits `capture` with a `File`), `@/utils/device.js#isMobileDevice()`.

**`AttachmentsPicker.vue` props:**

```
mode            String   required, validator: v => ['staged', 'immediate'].includes(v)
attachments     Array    default: () => []            — already-uploaded items: {id, original_name, mime_type, size_bytes}
newFiles        Array    default: () => []            — mode="staged" only, v-model:new-files (File[] queued for later submit)
maxCount        Number   default: 3
allowedMimes    Array    default: () => ['image/jpeg','image/png','image/webp','application/pdf']
maxBytes        Number   default: 5 * 1024 * 1024
previewUrl      Function default: null                — (attachment) => url; null disables click-to-view entirely
downloadUrl     Function default: null
canAdd          Boolean  default: true                — gates the "Tomar foto"/"Adjuntar" triggers (and slots)
canDelete       Boolean  default: true                — gates the remove button on already-uploaded thumbnails ONLY
uploading       Boolean  default: false               — mode="immediate" only: parent has a request in flight
uploadError     String   default: ''                  — mode="immediate" only: parent's error message to display
emptyStateText  String   default: 'Aún no hay archivos — toma una foto o adjunta uno.'
```

**Emits:**

```
update:newFiles  (File[])       — mode="staged": full updated queue after add/remove
remove-existing  (attachment)   — both modes: parent performs the actual delete request
files-selected   (File[])       — mode="immediate": parent performs the actual upload request
```

`canAdd`/`canDelete` are two separate props (not one `canManage`) on purpose: `PaymentReceiptsPanel.vue` (Task 3, Step 2) has an existing rule from a previous task — a cajero can add new receipts to a cobro global but can never delete one (`caja.cobros.receipts.destroy` doesn't exist as a route) — so its `canAdd`/`canDelete` computeds diverge for that one case. `GastoFormModal.vue`/`CompraFormModal.vue` (Tasks 4/5) never pass either prop and rely on both defaulting to `true`.

- [ ] **Step 1: Create `AttachmentsPicker.vue`**

```vue
<script setup>
/**
 * AttachmentsPicker — selector/gestor de adjuntos compartido por Gastos,
 * Compras y comprobantes de pago (spec 2026-07-17-adjuntos-unificados).
 *
 * Presentacional: no hace peticiones de red, no decide permisos, no arma
 * URLs de rutas del backend. El padre sigue siendo dueño de esa lógica —
 * este componente solo junta/valida archivos localmente y emite eventos.
 *
 * Dos modos:
 * - "staged": los archivos nuevos se acumulan aquí (v-model:new-files) y se
 *   envían junto con el resto de un formulario al guardar (Gastos, Compras).
 * - "immediate": cada selección dispara `files-selected` de inmediato — el
 *   padre hace su propia petición de subida (comprobantes de pago).
 *
 * Eliminar un adjunto YA SUBIDO siempre es una petición inmediata del padre
 * (emit('remove-existing', attachment)), sin importar el modo — no existe
 * "borrado en cola" para algo que ya vive en el servidor.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { isMobileDevice } from '@/utils/device';
import CameraCaptureModal from '@/Components/CameraCaptureModal.vue';
import AttachmentViewerModal from '@/Components/AttachmentViewerModal.vue';

const props = defineProps({
    mode: {
        type: String,
        required: true,
        validator: (v) => ['staged', 'immediate'].includes(v),
    },
    attachments: { type: Array, default: () => [] },
    newFiles: { type: Array, default: () => [] },
    maxCount: { type: Number, default: 3 },
    allowedMimes: {
        type: Array,
        default: () => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
    },
    maxBytes: { type: Number, default: 5 * 1024 * 1024 },
    previewUrl: { type: Function, default: null },
    downloadUrl: { type: Function, default: null },
    canAdd: { type: Boolean, default: true },
    canDelete: { type: Boolean, default: true },
    uploading: { type: Boolean, default: false },
    uploadError: { type: String, default: '' },
    emptyStateText: { type: String, default: 'Aún no hay archivos — toma una foto o adjunta uno.' },
});

const emit = defineEmits(['update:newFiles', 'remove-existing', 'files-selected']);

const fileInput = ref(null);
const cameraInput = ref(null);
const cameraModalOpen = ref(false);
const localError = ref('');
const dragOver = ref(false);

// Previews locales (blob URLs): para archivos en cola (modo staged) y para
// las miniaturas "pendientes" mientras se sube (modo immediate). Se revocan
// al quitarlas o al desmontar para no fugar memoria.
const previewsByFile = ref(new Map());
const revoke = (file) => {
    const url = previewsByFile.value.get(file);
    if (url) {
        URL.revokeObjectURL(url);
        previewsByFile.value.delete(file);
    }
};

// mode="immediate": archivos que se están subiendo AHORA MISMO, solo para
// feedback visual (spinner). Se limpian cuando `uploading` vuelve a false.
const pendingFiles = ref([]);
watch(() => props.uploading, (isUploading, was) => {
    if (was && !isUploading) {
        pendingFiles.value.forEach(revoke);
        pendingFiles.value = [];
    }
});

// Si el padre reemplaza `newFiles` externamente (p.ej. al resetear un
// formulario), libera los blobs de los archivos que ya no están.
watch(() => props.newFiles, (next, prev) => {
    (prev || []).forEach((f) => { if (!next.includes(f)) revoke(f); });
});

onBeforeUnmount(() => {
    previewsByFile.value.forEach((url) => URL.revokeObjectURL(url));
});

const stagedCount = computed(() => (props.mode === 'staged' ? props.newFiles.length : pendingFiles.value.length));
const totalCount = computed(() => props.attachments.length + stagedCount.value);
const remainingSlots = computed(() => Math.max(0, props.maxCount - totalCount.value));
const showAddTriggers = computed(() => props.canAdd && remainingSlots.value > 0);

const validate = (files) => {
    for (const f of files) {
        if (!props.allowedMimes.includes(f.type)) {
            return `Tipo no permitido: ${f.name}. Solo imágenes (jpg, png, webp) o PDF.`;
        }
        if (f.size > props.maxBytes) {
            return `Archivo demasiado grande (máx ${Math.round(props.maxBytes / (1024 * 1024))} MB): ${f.name}`;
        }
    }
    if (files.length > remainingSlots.value) {
        return `Solo puedes adjuntar hasta ${props.maxCount} archivos.`;
    }
    return '';
};

const addFiles = (files) => {
    localError.value = '';
    if (!files.length) return;
    const error = validate(files);
    if (error) {
        localError.value = error;
        return;
    }

    files.forEach((f) => {
        if (f.type.startsWith('image/')) previewsByFile.value.set(f, URL.createObjectURL(f));
    });

    if (props.mode === 'staged') {
        emit('update:newFiles', [...props.newFiles, ...files]);
    } else {
        pendingFiles.value = [...pendingFiles.value, ...files];
        emit('files-selected', files);
    }
};

const onFileInputChange = (e) => {
    addFiles(Array.from(e.target.files ?? []));
    e.target.value = '';
};

const onDrop = (e) => {
    dragOver.value = false;
    if (!showAddTriggers.value) return;
    addFiles(Array.from(e.dataTransfer?.files ?? []));
};

const onTakePhoto = () => {
    if (isMobileDevice()) {
        cameraInput.value?.click();
    } else {
        cameraModalOpen.value = true;
    }
};
const onCameraCapture = (file) => addFiles([file]);

const removeNewFile = (index) => {
    const file = props.newFiles[index];
    revoke(file);
    emit('update:newFiles', props.newFiles.filter((_, i) => i !== index));
};

const removeExisting = (att) => {
    if (!window.confirm(`¿Eliminar "${att.original_name}"?`)) return;
    emit('remove-existing', att);
};

// --- Viewer (solo adjuntos ya subidos) ---
const viewerOpen = ref(false);
const viewerIndex = ref(0);
const openViewer = (i) => {
    if (!props.previewUrl) return;
    viewerIndex.value = i;
    viewerOpen.value = true;
};

const isImage = (att) => att?.mime_type?.startsWith('image/');
const isPdf = (att) => att?.mime_type === 'application/pdf';

const fmtSize = (b) => {
    if (b == null) return '';
    if (b < 1024) return `${b} B`;
    if (b < 1024 * 1024) return `${(b / 1024).toFixed(1)} KB`;
    return `${(b / (1024 * 1024)).toFixed(1)} MB`;
};
</script>

<template>
    <div>
        <!-- Grid de miniaturas: existentes + en cola (staged) + pendientes de subida (immediate) -->
        <div v-if="totalCount > 0" class="grid grid-cols-3 gap-2 sm:grid-cols-4">
            <div v-for="(att, i) in attachments" :key="`existing-${att.id}`"
                class="group relative aspect-square overflow-hidden rounded-xl bg-gray-50 ring-1 ring-gray-200">
                <button v-if="previewUrl" type="button" @click="openViewer(i)" class="block h-full w-full">
                    <img v-if="isImage(att)" :src="previewUrl(att)" :alt="att.original_name" loading="lazy"
                        class="h-full w-full object-cover transition group-hover:scale-105" />
                    <div v-else class="flex h-full w-full flex-col items-center justify-center gap-1 p-2 text-gray-500">
                        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                        <span class="line-clamp-2 text-[10px] font-medium">{{ att.original_name }}</span>
                    </div>
                </button>
                <div v-else class="flex h-full w-full flex-col items-center justify-center gap-1 p-2 text-gray-500">
                    <svg v-if="isImage(att)" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3 4.5h18v15H3v-15Z" /></svg>
                    <svg v-else class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                    <span class="line-clamp-2 text-[10px] font-medium">{{ att.original_name }}</span>
                </div>
                <span class="pointer-events-none absolute bottom-1 left-1 rounded-md bg-black/60 px-1.5 py-0.5 text-[9px] font-bold text-white">{{ fmtSize(att.size_bytes) }}</span>
                <button v-if="canDelete" type="button" @click="removeExisting(att)"
                    class="absolute right-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-white/90 text-gray-700 shadow ring-1 ring-gray-200 transition hover:bg-red-600 hover:text-white">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <template v-if="mode === 'staged'">
                <div v-for="(f, i) in newFiles" :key="`staged-${i}`"
                    class="group relative aspect-square overflow-hidden rounded-xl bg-amber-50 ring-1 ring-amber-200">
                    <img v-if="previewsByFile.get(f)" :src="previewsByFile.get(f)" :alt="f.name" class="h-full w-full object-cover" />
                    <div v-else class="flex h-full w-full flex-col items-center justify-center gap-1 p-2 text-amber-700">
                        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                        <span class="line-clamp-2 text-[10px] font-medium">{{ f.name }}</span>
                    </div>
                    <span class="pointer-events-none absolute bottom-1 left-1 rounded-md bg-amber-600 px-1.5 py-0.5 text-[9px] font-bold text-white">{{ fmtSize(f.size) }}</span>
                    <button type="button" @click="removeNewFile(i)"
                        class="absolute right-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-white/90 text-gray-700 shadow ring-1 ring-gray-200 transition hover:bg-red-600 hover:text-white">
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>
            </template>

            <template v-else>
                <div v-for="(f, i) in pendingFiles" :key="`pending-${i}`"
                    class="relative aspect-square overflow-hidden rounded-xl bg-blue-50 ring-1 ring-blue-200">
                    <img v-if="previewsByFile.get(f)" :src="previewsByFile.get(f)" :alt="f.name" class="h-full w-full object-cover opacity-50" />
                    <div v-else class="flex h-full w-full items-center justify-center p-2 text-blue-700 opacity-50">
                        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                    </div>
                    <div class="absolute inset-0 flex items-center justify-center bg-black/20">
                        <svg class="h-6 w-6 animate-spin text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    </div>
                </div>
            </template>
        </div>

        <!-- Estado vacío -->
        <div v-else class="rounded-xl border border-dashed border-gray-200 px-4 py-6 text-center text-xs text-gray-400">
            {{ emptyStateText }}
        </div>

        <!-- Triggers: cámara + archivo/arrastrar -->
        <div v-if="showAddTriggers" class="mt-3 grid grid-cols-2 gap-2">
            <button type="button" @click="onTakePhoto"
                class="group flex cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed border-red-200 bg-red-50/40 px-4 py-3 text-center transition hover:border-red-400 hover:bg-red-50">
                <svg class="h-5 w-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" />
                </svg>
                <span class="text-sm font-semibold text-red-700">Tomar foto</span>
            </button>
            <label
                @dragover.prevent="dragOver = true" @dragleave.prevent="dragOver = false" @drop.prevent="onDrop"
                :class="['group flex cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed px-4 py-3 text-center transition',
                    dragOver ? 'border-red-400 bg-red-50' : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50']">
                <svg class="h-5 w-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                </svg>
                <span class="text-sm font-semibold text-gray-700">{{ uploading ? 'Subiendo…' : 'Adjuntar o arrastra aquí' }}</span>
                <input ref="fileInput" type="file" multiple :accept="allowedMimes.join(',')" class="hidden" :disabled="uploading" @change="onFileInputChange" />
            </label>
            <input ref="cameraInput" type="file" accept="image/*" capture="environment" class="hidden" :disabled="uploading" @change="onFileInputChange" />
        </div>
        <p v-else-if="canAdd" class="mt-3 text-center text-xs text-gray-400">Máximo {{ maxCount }} archivos.</p>

        <p v-if="localError" class="mt-2 text-xs font-semibold text-red-600">{{ localError }}</p>
        <p v-if="uploadError" class="mt-2 text-xs font-semibold text-red-600">{{ uploadError }}</p>

        <AttachmentViewerModal v-if="previewUrl"
            :show="viewerOpen"
            :attachments="attachments"
            :initial-index="viewerIndex"
            :preview-url="previewUrl"
            :download-url="downloadUrl"
            @close="viewerOpen = false" />
        <CameraCaptureModal v-model:open="cameraModalOpen" @capture="onCameraCapture" />
    </div>
</template>
```

- [ ] **Step 2: Refactor `PaymentReceiptsPanel.vue` to use `AttachmentsPicker` (mode="immediate")**

Read the current file first (it should match the content already in the repo — this task only changes the upload-area/list rendering, not the request logic). Replace the entire file with:

```vue
<script setup>
/**
 * Panel de comprobantes de un pago por transferencia (venta o cobro global
 * de fiado). Está pensado para montarse dentro de un <Modal> existente del
 * proyecto (Components/Modal.vue) — este componente sólo aporta el
 * contenido (header + AttachmentsPicker), no el overlay/backdrop.
 *
 * Consume los endpoints de Tasks 5/6 (`*.pagos.receipts.*` /
 * `*.cobros.receipts.*`) tal cual quedaron: responden redirect-back con
 * flash de éxito / errores de validación (`back()->with('success', ...)`,
 * `back()->withErrors(...)`) — cero cambios a esos controladores. Por eso
 * usamos Inertia `router.post`/`router.delete` (NO axios): los errores de
 * validación (422: método no es transferencia, límite de 3, tipo/tamaño de
 * archivo) SÍ llegan al callback `onError` porque ese flujo es el estándar
 * de Inertia (el redirect se sigue y la página anfitriona se re-renderiza
 * con `errors` compartido).
 *
 * `canManage` se recibe tal cual desde el padre (normalmente `true`): la
 * autorización real vive en el backend (rol, turno abierto del cajero,
 * dueño del pago, flag de sucursal). Si el backend rechaza con 403/404
 * (p.ej. un cajero fuera de su turno), la respuesta no tiene el header
 * `X-Inertia` (es una página de error normal de Laravel), así que Inertia
 * no la puede mapear a `onError` — se ve su modal de error por defecto.
 * Mismo comportamiento que ya tiene el resto de la app para este tipo de
 * rechazo (p.ej. `removeExistingAttachment` en GastoFormModal.vue tampoco
 * maneja el 403 de forma especial). Aceptado a propósito para no tocar los
 * controladores de T5/T6.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AttachmentsPicker from '@/Components/AttachmentsPicker.vue';

const MAX = 3;

const props = defineProps({
    receipts: { type: Array, default: () => [] },
    parentType: {
        type: String,
        required: true,
        validator: (v) => ['payment', 'customer-payment'].includes(v),
    },
    parentId: { type: Number, required: true },
    canManage: { type: Boolean, default: false },
    tenantSlug: { type: String, required: true },
    routePrefix: { type: String, default: 'sucursal' },
});

const emit = defineEmits(['changed', 'close']);

const segment = computed(() => (props.parentType === 'payment' ? 'pagos' : 'cobros'));

// El grupo de rutas `caja` no expone destroy para comprobantes de cobro
// global (T6: el cajero adjunta/descarga los suyos pero no los elimina).
const canDelete = computed(() => props.canManage && !(props.routePrefix === 'caja' && props.parentType === 'customer-payment'));

const routeName = (action) => `${props.routePrefix}.${segment.value}.receipts.${action}`;
const previewUrl = (r) => route(routeName('preview'), [props.tenantSlug, props.parentId, r.id]);
const downloadUrl = (r) => route(routeName('download'), [props.tenantSlug, props.parentId, r.id]);

const uploading = ref(false);
const uploadError = ref('');

const onFilesSelected = (files) => {
    uploadError.value = '';
    uploading.value = true;
    router.post(route(routeName('store'), [props.tenantSlug, props.parentId]), { receipts: files }, {
        forceFormData: true,
        preserveScroll: true,
        onError: (errors) => { uploadError.value = errors.receipts || 'No se pudo subir el comprobante.'; },
        onSuccess: () => emit('changed'),
        onFinish: () => { uploading.value = false; },
    });
};

const onRemoveExisting = (r) => {
    router.delete(route(routeName('destroy'), [props.tenantSlug, props.parentId, r.id]), {
        preserveScroll: true,
        onSuccess: () => emit('changed'),
    });
};
</script>

<template>
    <div>
        <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-6 py-4">
            <div class="flex items-center gap-2">
                <h3 class="text-base font-bold text-gray-900">Comprobantes</h3>
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-bold text-gray-500">{{ receipts.length }}/{{ MAX }}</span>
            </div>
            <button type="button" @click="emit('close')" class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
            </button>
        </div>

        <div class="px-6 py-4">
            <AttachmentsPicker
                mode="immediate"
                :attachments="receipts"
                :max-count="MAX"
                :preview-url="previewUrl"
                :download-url="downloadUrl"
                :can-add="canManage"
                :can-delete="canDelete"
                :uploading="uploading"
                :upload-error="uploadError"
                empty-state-text="Sin comprobantes adjuntos."
                @files-selected="onFilesSelected"
                @remove-existing="onRemoveExisting" />
        </div>
    </div>
</template>
```

Note: `MAX` must stay defined as a plain script constant (`const MAX = 3;`) — it's referenced in the template for the header badge and passed as `:max-count`.

- [ ] **Step 3: Build**

```bash
vendor/bin/sail npm run build
```

Expected: green build. This is the first real consumer of `AttachmentsPicker.vue` — if there's a template/script error in the new component, it surfaces here.

- [ ] **Step 4: Regression — existing payment receipt backend tests unaffected**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Sucursal/PaymentReceiptTest.php tests/Feature/Sucursal/CustomerPaymentReceiptTest.php
```

Expected: all passed (no backend touched in this task; this just confirms Task 2's routes are still intact).

- [ ] **Step 5: Manual verification (no JS test runner in this project)**

Log in as `sucursal@eltoro.test` / `password` (tenant `el-toro`), open Pagos, click a transfer payment's 📎 clip, and confirm: existing receipts show as real thumbnails (not a text list), clicking one opens the full-screen viewer, "Adjuntar o arrastra aquí" accepts both a click-to-pick and a drag-and-drop, and "Tomar foto" opens the camera/webcam flow. Delete one receipt and confirm it disappears after the panel reloads.

- [ ] **Step 6: Commit**

```bash
git status --short
git add resources/js/Components/AttachmentsPicker.vue resources/js/Components/PaymentReceiptsPanel.vue
git commit -m "$(cat <<'EOF'
feat(adjuntos): AttachmentsPicker.vue compartido + comprobantes de pago lo adopta

Nuevo componente presentacional con 2 modos (staged/immediate), cámara,
arrastrar-y-soltar, estado de subida y estado vacío. PaymentReceiptsPanel.vue
lo consume en mode="immediate" — gana miniaturas reales y visor de pantalla
completa en vez de la lista de texto + caja punteada chica. Sin cambios en
la lógica de red (router.post/delete, manejo de errores 422/403 sin tocar).

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: `GastoFormModal.vue` adopts `AttachmentsPicker` (mode="staged")

**Files:**
- Modify: `resources/js/Components/Gastos/GastoFormModal.vue`

**Interfaces:**
- Consumes: `AttachmentsPicker.vue` props/emits from Task 3 (`mode="staged"`, `v-model:new-files`, `remove-existing`).

This is the first "staged" consumer and the first touch on an already-shipped, in-production form — the backend (`submitRouteName`, `attachmentDestroyRouteName`, `attachmentPreviewRouteName`, `attachmentDownloadRouteName` props and what they point to) is untouched; only the internal file-picking/preview implementation moves into the shared component.

- [ ] **Step 1: Replace the script section**

Read the current file first. In the `<script setup>` block, remove these now-redundant pieces (they move into `AttachmentsPicker.vue`): `MAX_ATTACHMENTS`, `MAX_BYTES`, `ALLOWED_MIMES` constants; `fileInput`, `cameraInput`, `newFiles`, `newFilePreviews`, `fileError` refs; `remainingSlots`, `totalAttachments` computeds; `revokeAllPreviews`, `addFiles`, `onFileSelect`, `onTakePhoto`, `onCameraCapture`, `removeNewFile`, `isImageMime` functions; `cameraModalOpen` ref; the `CameraCaptureModal`/`isMobileDevice` imports (no longer used directly here — `AttachmentsPicker` uses them internally).

Keep: `existingAttachments` computed, `removeExistingAttachment`, `viewerOpen`/`viewerIndex`/`openAttachmentViewer`, `previewUrlBuilder`/`downloadUrlBuilder`, `fmtSize` (still used nowhere now — remove it too since only the picker needs it internally). Keep `reset()` but simplify it (no more `newFiles`/`newFilePreviews`/`fileError`/input-ref clearing — those live inside the picker now; the picker's OWN internal state resets naturally because `v-model:new-files` binds to a fresh empty array each time this component's own `newFiles` ref is reset).

The full new `<script setup>` block:

```vue
<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import DateField from '@/Components/DateField.vue';
import AttachmentsPicker from '@/Components/AttachmentsPicker.vue';
import { useExpenseAiDraft } from '@/composables/useExpenseAiDraft';
import { localToday } from '@/utils/date';

const props = defineProps({
    show: { type: Boolean, default: false },
    /** 'create' | 'edit' */
    mode: { type: String, default: 'create' },
    tenantSlug: { type: String, required: true },
    /** Listado completo de categorías (cada una con `subcategories[]`). */
    categories: { type: Array, required: true },
    /** Branches disponibles. Requerido cuando allowBranchSelect=true. */
    branches: { type: Array, default: () => [] },
    /** Si true, muestra el selector de sucursal (admin-empresa). Si false, sucursal viene fija. */
    allowBranchSelect: { type: Boolean, default: false },
    /** Cuando allowBranchSelect=false, branchId que se usará. */
    fixedBranchId: { type: [Number, String, null], default: null },
    /** Gasto en edición (cuando mode === 'edit'). */
    expense: { type: Object, default: null },
    submitRouteName: { type: String, required: true },
    attachmentDestroyRouteName: { type: String, required: true },
    /** Para preview/download dentro del modal en modo edit. */
    attachmentPreviewRouteName: { type: String, default: '' },
    attachmentDownloadRouteName: { type: String, default: '' },
    /** Opciones {value,label} de métodos de pago (vienen del backend). */
    paymentMethods: { type: Array, default: () => [] },
    /** Propuesta de la IA (Fase 1). Cuando se setea, prerellena el form al abrir. */
    aiProposal: { type: Object, default: null },
    /** ID del draft IA — se envía al backend al guardar para mover archivos. */
    aiDraftId: { type: [Number, String, null], default: null },
    /** Metadata de archivos ya guardados en el draft IA. */
    aiAttachments: { type: Array, default: () => [] },
    /** Transcripción de la nota de voz (Fase 2). Informativo, no editable. */
    aiTranscription: { type: String, default: null },
});

const emit = defineEmits(['close', 'success']);

const MAX_ATTACHMENTS = 5;

const form = useForm({
    concept: '',
    amount: '',
    expense_category_id: '',
    expense_subcategory_id: '',
    branch_id: '',
    expense_date: '',
    payment_method: '',
    description: '',
    attachments: [],
});

// Conjunto de claves que vinieron prerellenadas por la IA (badge ✨ en la UI).
const aiFilledFields = ref(new Set());
// Adjuntos del draft IA (sólo metadata, los archivos ya viven en disco privado).
const aiDraftAttachments = ref([]);

const { applyProposalToForm } = useExpenseAiDraft();

// Archivos nuevos en cola (mode="staged" de AttachmentsPicker) — se envían
// junto con el resto del form al guardar.
const newFiles = ref([]);

const subcategories = computed(() => {
    const cat = props.categories.find(c => c.id === Number(form.expense_category_id));
    return cat?.subcategories?.filter(s => s.status === 'active') || [];
});

const existingAttachments = computed(() => props.expense?.attachments || []);

// El picker limita por su cuenta (attachments + newFiles vs maxCount), pero
// los chips del draft IA (aiDraftAttachments) también ocupan cupo y el
// picker no los conoce — se restan aquí para pasarle un tope efectivo.
const pickerMaxCount = computed(() => Math.max(0, MAX_ATTACHMENTS - aiDraftAttachments.value.length));

const reset = () => {
    form.reset();
    form.clearErrors();
    newFiles.value = [];
};

const populateFromExpense = () => {
    if (!props.expense) return;
    form.concept = props.expense.concept || '';
    form.amount = props.expense.amount;
    form.expense_subcategory_id = props.expense.expense_subcategory_id;
    form.expense_category_id = props.expense.subcategory?.expense_category_id
        || props.expense.subcategory?.category?.id || '';
    form.branch_id = props.expense.branch_id || props.fixedBranchId || '';
    if (props.expense.expense_at) {
        const d = new Date(props.expense.expense_at);
        const pad = (n) => String(n).padStart(2, '0');
        form.expense_date = `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
    }
    form.payment_method = props.expense.payment_method || '';
    form.description = props.expense.description || '';
};

const setupCreate = () => {
    form.expense_date = localToday();
    if (!props.allowBranchSelect && props.fixedBranchId) {
        form.branch_id = props.fixedBranchId;
    }
};

const applyAiProposal = () => {
    aiFilledFields.value = new Set();
    aiDraftAttachments.value = props.aiAttachments || [];
    if (!props.aiProposal) return;

    const filled = applyProposalToForm(form, props.aiProposal, props.categories);
    aiFilledFields.value = new Set(filled);
    // Si la IA no detectó fecha, mantenemos el default de hoy.
    if (!form.expense_date) form.expense_date = localToday();
};

const isAiFilled = (key) => aiFilledFields.value.has(key);

const initializeForMode = () => {
    reset();
    if (props.mode === 'edit') {
        populateFromExpense();
    } else {
        setupCreate();
        if (props.aiProposal) applyAiProposal();
    }
};

// El watcher de `show` reinicia tanto al abrir como al cerrar. Antes solo
// reiniciaba en open, lo que permitía que valores quedaran "pegados" si el
// padre cerraba/abría sin re-renderizar o si se llegaba al modal desde una
// transición rápida.
watch(() => props.show, (val) => {
    if (val) {
        initializeForMode();
    } else {
        reset();
    }
});

// Si el padre cambia el modo o el gasto objetivo SIN cerrar el modal (p. ej.
// click directo en "editar" desde otra fila), también hay que reinicializar.
watch(() => [props.mode, props.expense?.id], () => {
    if (props.show) initializeForMode();
});

watch(() => form.expense_category_id, (newVal, oldVal) => {
    if (oldVal && Number(newVal) !== Number(oldVal)) {
        form.expense_subcategory_id = '';
    }
});

const removeExistingAttachment = (att) => {
    router.delete(route(props.attachmentDestroyRouteName, [props.tenantSlug, props.expense.id, att.id]), {
        preserveScroll: true,
        preserveState: true,
    });
};

// --- Adjunto preview (modo edit) ---
const previewUrlBuilder = (att) =>
    route(props.attachmentPreviewRouteName, [props.tenantSlug, props.expense?.id, att.id]);
const downloadUrlBuilder = (att) =>
    route(props.attachmentDownloadRouteName, [props.tenantSlug, props.expense?.id, att.id]);

const submit = () => {
    if (form.processing) return;
    form.attachments = newFiles.value;

    const args = props.mode === 'edit'
        ? [props.tenantSlug, props.expense.id]
        : [props.tenantSlug];

    const url = route(props.submitRouteName, args);

    const opts = {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            emit('success');
            emit('close');
        },
    };

    if (props.mode === 'edit') {
        // forceFormData impide el spoofing automático de método de Inertia,
        // así que `_method: 'put'` debe ir DENTRO del payload (no como option).
        // Laravel lee ese field para tratar el POST multipart como PUT.
        form
            .transform((data) => ({ ...data, _method: 'put' }))
            .post(url, opts);
    } else {
        // En create con propuesta IA, mandamos ai_draft_id para que el backend
        // mueva los archivos del draft al gasto (no se re-suben).
        const draftId = props.aiDraftId;
        if (draftId) {
            form
                .transform((data) => ({ ...data, ai_draft_id: draftId }))
                .post(url, opts);
        } else {
            form.post(url, opts);
        }
    }
};
</script>
```

Note: removed the now-unused `onBeforeUnmount` import usage from the old `revokeAllPreviews` cleanup — `onBeforeUnmount` is no longer called in this file at all, so also drop it from the `vue` import list above (it's not in the import list shown — confirm it isn't left dangling).

- [ ] **Step 2: Replace the "Adjuntos" template block**

Find this block (currently lines ~399–482, from `<!-- Adjuntos: foto + archivo + miniaturas -->` through the closing `</div>` right before `<!-- Concepto -->`):

```html
                            <!-- Adjuntos: foto + archivo + miniaturas -->
                            <div>
                                ... (the whole block with aiDraftAttachments chips, triggers grid, thumbnail grid, counters, errors) ...
                            </div>
```

Replace it with:

```html
                            <!-- Adjuntos: foto + archivo + miniaturas -->
                            <div>
                                <div class="mb-1.5 flex items-center justify-between">
                                    <label class="text-xs font-semibold text-gray-600">Comprobante</label>
                                    <span class="text-[11px] text-gray-400">jpg · png · webp · pdf · 5 MB · {{ MAX_ATTACHMENTS }} máx</span>
                                </div>

                                <!-- Adjuntos del draft IA: chips informativos (no removibles) -->
                                <div v-if="aiDraftAttachments.length" class="mb-2 flex flex-wrap gap-1.5">
                                    <span v-for="a in aiDraftAttachments" :key="a.index" class="inline-flex items-center gap-1.5 rounded-full bg-violet-100 px-2.5 py-1 text-[11px] font-semibold text-violet-800 ring-1 ring-violet-200">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                                        <span class="max-w-[120px] truncate">{{ a.original_name }}</span>
                                    </span>
                                </div>

                                <AttachmentsPicker
                                    mode="staged"
                                    :attachments="existingAttachments"
                                    v-model:new-files="newFiles"
                                    :max-count="pickerMaxCount"
                                    :preview-url="attachmentPreviewRouteName ? previewUrlBuilder : null"
                                    :download-url="attachmentDownloadRouteName ? downloadUrlBuilder : null"
                                    @remove-existing="removeExistingAttachment" />

                                <p class="mt-2 text-[11px] text-gray-400">{{ existingAttachments.length + aiDraftAttachments.length + newFiles.length }} / {{ MAX_ATTACHMENTS }} archivos</p>
                                <p v-if="form.errors.attachments" class="mt-1 text-xs text-red-600">{{ form.errors.attachments }}</p>
                                <p v-for="(err, key) in Object.fromEntries(Object.entries(form.errors).filter(([k]) => k.startsWith('attachments.')))"
                                    :key="key" class="mt-1 text-xs text-red-600">{{ err }}</p>
                            </div>
```

- [ ] **Step 3: Remove the now-unused viewer/camera markup at the bottom of the template**

Find and delete this block (it lived right before the closing `</Teleport>`, and the sibling line after it):

```html
        <!-- Viewer in edit mode -->
        <AttachmentViewerModal v-if="attachmentPreviewRouteName"
            :show="viewerOpen"
            :attachments="existingAttachments"
            :initial-index="viewerIndex"
            :preview-url="previewUrlBuilder"
            :download-url="downloadUrlBuilder"
            @close="viewerOpen = false" />
    </Teleport>

    <!-- Webcam (desktop): captura con getUserMedia cuando `capture` no aplica -->
    <CameraCaptureModal v-model:open="cameraModalOpen" @capture="onCameraCapture" />
```

Replace with just:

```html
    </Teleport>
```

(`AttachmentsPicker` now owns its own `AttachmentViewerModal`/`CameraCaptureModal` internally — this file no longer needs either.)

- [ ] **Step 4: Build**

```bash
vendor/bin/sail npm run build
```

Expected: green build.

- [ ] **Step 5: Regression — Gastos backend tests untouched, must still pass**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Sucursal/GastoControllerTest.php
```

Expected: all passed (this task changed zero backend code; the request shape `form.attachments = newFiles.value` posted via `forceFormData: true` is unchanged).

- [ ] **Step 6: Manual verification**

As `sucursal@eltoro.test`, open Gastos → "Registrar gasto", confirm "Tomar foto"/"Adjuntar o arrastra aquí" work and thumbnails appear for queued files; edit an existing gasto with an attachment and confirm the existing thumbnail shows, opens the viewer, and can be deleted.

- [ ] **Step 7: Commit**

```bash
git status --short
git add resources/js/Components/Gastos/GastoFormModal.vue
git commit -m "$(cat <<'EOF'
refactor(gastos): GastoFormModal.vue usa AttachmentsPicker compartido

Sin cambios de backend ni de comportamiento visible salvo las mejoras del
componente compartido (drag&drop, estado vacío). ~150 líneas de lógica de
archivos se remueven de este archivo y viven ahora en AttachmentsPicker.vue.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: `CompraFormModal.vue` gains attachment props + `AttachmentsPicker`

**Files:**
- Modify: `resources/js/Components/Compras/CompraFormModal.vue`

**Interfaces:**
- Produces: 3 new props on `CompraFormModal.vue` — `attachmentPreviewRouteName: String (default '')`, `attachmentDownloadRouteName: String (default '')`, `attachmentDestroyRouteName: String (default '')` (mirrors `GastoFormModal.vue`'s naming exactly, for the callers in Tasks 6 and 9 to pass).
- Consumes: `AttachmentsPicker.vue` (`mode="staged"`).

`CompraFormModal.vue` today has **no** existing-attachments display at all (for any role) — this task adds that capability to the component itself; wiring the new props into the 3 pages that render it is Tasks 6 (Empresa/Sucursal) and 9 (Caja).

- [ ] **Step 1: Add the 3 new props and the attachments/viewer wiring to the script**

In `resources/js/Components/Compras/CompraFormModal.vue`, change the imports and props:

```js
// Before
import { usePurchaseAiDraft } from '@/composables/usePurchaseAiDraft';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    purchase: { type: Object, default: null }, // null = crear; objeto = editar
    providers: { type: Array, default: () => [] },
    purchaseProducts: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] }, // vacía si es admin-sucursal
    fixedBranchId: { type: Number, default: null }, // si admin-sucursal
    // Propuesta IA opcional (sólo en modo crear). { draftId, proposal, audioTranscription }
    aiResult: { type: Object, default: null },
    // Modo caja: muestra el campo "pagado en efectivo" y postea a la ruta de caja.
    cashMode: { type: Boolean, default: false },
    routes: {
        type: Object,
        required: true,
        validator: (v) => v.store && v.update,
    },
});
const emit = defineEmits(['close']);
```

```js
// After
import { usePurchaseAiDraft } from '@/composables/usePurchaseAiDraft';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AttachmentsPicker from '@/Components/AttachmentsPicker.vue';

const MAX_ATTACHMENTS = 5;

const props = defineProps({
    open: { type: Boolean, default: false },
    purchase: { type: Object, default: null }, // null = crear; objeto = editar
    providers: { type: Array, default: () => [] },
    purchaseProducts: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] }, // vacía si es admin-sucursal
    fixedBranchId: { type: Number, default: null }, // si admin-sucursal
    // Propuesta IA opcional (sólo en modo crear). { draftId, proposal, audioTranscription }
    aiResult: { type: Object, default: null },
    // Modo caja: muestra el campo "pagado en efectivo" y postea a la ruta de caja.
    cashMode: { type: Boolean, default: false },
    routes: {
        type: Object,
        required: true,
        validator: (v) => v.store && v.update,
    },
    /** Rutas de adjuntos ya existentes en modo edición. Vacías = no se muestran (paridad con GastoFormModal). */
    attachmentPreviewRouteName: { type: String, default: '' },
    attachmentDownloadRouteName: { type: String, default: '' },
    attachmentDestroyRouteName: { type: String, default: '' },
});
const emit = defineEmits(['close']);
```

Then, right after the `form` declaration (after `const form = useForm({...});`), add:

```js
const existingAttachments = computed(() => props.purchase?.attachments || []);

const previewUrlBuilder = (att) =>
    route(props.attachmentPreviewRouteName, { tenant: slug.value, compra: props.purchase.id, attachment: att.id });
const downloadUrlBuilder = (att) =>
    route(props.attachmentDownloadRouteName, { tenant: slug.value, compra: props.purchase.id, attachment: att.id });

const removeExistingAttachment = (att) => {
    router.delete(route(props.attachmentDestroyRouteName, { tenant: slug.value, compra: props.purchase.id, attachment: att.id }), {
        preserveScroll: true,
    });
};
```

This needs `router` imported — change:

```js
// Before
import { useForm, usePage } from '@inertiajs/vue3';
// After
import { router, useForm, usePage } from '@inertiajs/vue3';
```

- [ ] **Step 2: Replace `onFiles`/the native file input with `AttachmentsPicker`**

Remove the old handler:

```js
// Remove this line entirely
const onFiles = (e) => { form.attachments = Array.from(e.target.files || []); };
```

`form.attachments` is now driven directly by `v-model:new-files` in the template (Step 3), so no manual assignment function is needed — but `submit()` still reads `form.attachments` as-is, unchanged.

- [ ] **Step 3: Replace the "Adjuntos" template block**

Find:

```html
                        <!-- Adjuntos -->
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Adjuntar factura / comprobantes</label>
                            <input type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf" @change="onFiles"
                                class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-orange-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-orange-700 hover:file:bg-orange-100" />
                            <p class="mt-1 text-xs text-gray-500">Hasta 5 archivos · jpg/png/webp/pdf · 5 MB c/u</p>
                        </div>
```

Replace with:

```html
                        <!-- Adjuntos -->
                        <div>
                            <div class="mb-1 flex items-center justify-between">
                                <label class="block text-sm font-medium text-gray-700">Adjuntar factura / comprobantes</label>
                                <span class="text-xs text-gray-500">jpg/png/webp/pdf · 5 MB · {{ MAX_ATTACHMENTS }} máx</span>
                            </div>
                            <AttachmentsPicker
                                mode="staged"
                                :attachments="existingAttachments"
                                v-model:new-files="form.attachments"
                                :max-count="MAX_ATTACHMENTS"
                                :preview-url="attachmentPreviewRouteName ? previewUrlBuilder : null"
                                :download-url="attachmentDownloadRouteName ? downloadUrlBuilder : null"
                                @remove-existing="removeExistingAttachment" />
                            <p v-if="form.errors.attachments" class="mt-1 text-xs text-red-600">{{ form.errors.attachments }}</p>
                        </div>
```

Note `v-model:new-files="form.attachments"` binds directly to the Inertia form's `attachments` field — `AttachmentsPicker` emits `update:newFiles` with the full array, which Vue's `v-model` sugar assigns straight into `form.attachments`, so `submit()` needs no change at all (it already does `form.post(...)`/`form.put(...)` with whatever is in `form.attachments`).

- [ ] **Step 4: Build**

```bash
vendor/bin/sail npm run build
```

Expected: green build. (Nothing yet passes the 3 new route-name props with a real value — `CompraFormModal.vue` used standalone still works because `previewUrl`/`downloadUrl` fall back to `null` when the prop is empty, matching how `GastoFormModal.vue` already behaves when `attachmentPreviewRouteName` is unset.)

- [ ] **Step 5: Regression — Compras backend tests untouched**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Compras/PurchaseControllerTest.php
```

Expected: all passed (zero backend changes in this task).

- [ ] **Step 6: Commit**

```bash
git status --short
git add resources/js/Components/Compras/CompraFormModal.vue
git commit -m "$(cat <<'EOF'
feat(compras): CompraFormModal.vue gana adjuntos existentes + AttachmentsPicker

Antes este formulario no mostraba adjuntos ya subidos en modo edición para
ningún rol (a diferencia de GastoFormModal.vue) y usaba un <input type=file>
nativo sin cámara ni miniaturas. Ahora usa el mismo AttachmentsPicker.vue
compartido en mode="staged". Las 3 props nuevas de rutas quedan opcionales
(default '') — el wiring real en las páginas que lo invocan es tarea aparte.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Wire `CompraFormModal`'s new props into Empresa/Sucursal Compras pages

**Files:**
- Modify: `resources/js/Pages/Empresa/Compras/Index.vue`
- Modify: `resources/js/Pages/Sucursal/Compras/Index.vue`

**Interfaces:**
- Consumes: `CompraFormModal.vue`'s 3 new props from Task 5, and the **already-existing** backend routes `empresa.compras.adjuntos.{preview,download,destroy}` / `sucursal.compras.adjuntos.{preview,download,destroy}` (no backend change in this task).

- [ ] **Step 1: Wire the props in `Pages/Empresa/Compras/Index.vue`**

Find the `<CompraFormModal>` invocation (uses `formRoutes` already defined at the top of the script, alongside `detailRoutes` which already has `adjuntoDownload`/`adjuntoPreview`/`adjuntoDestroy`):

```html
        <CompraFormModal
            :open="formOpen"
            :purchase="editing"
            :providers="providers"
            :purchase-products="purchaseProducts"
            :fixed-branch-id="branch?.id"
            :ai-result="aiResult"
            :routes="formRoutes"
            @close="formOpen = false; aiResult = null"
        />
```

Replace with:

```html
        <CompraFormModal
            :open="formOpen"
            :purchase="editing"
            :providers="providers"
            :purchase-products="purchaseProducts"
            :fixed-branch-id="branch?.id"
            :ai-result="aiResult"
            :routes="formRoutes"
            attachment-preview-route-name="empresa.compras.adjuntos.preview"
            attachment-download-route-name="empresa.compras.adjuntos.download"
            attachment-destroy-route-name="empresa.compras.adjuntos.destroy"
            @close="formOpen = false; aiResult = null"
        />
```

- [ ] **Step 2: Wire the props in `Pages/Sucursal/Compras/Index.vue`**

Find the equivalent `<CompraFormModal>` invocation (same shape, `sucursal.` prefix) and add the same 3 attributes with `sucursal.compras.adjuntos.*` route names:

```html
        <CompraFormModal
            :open="formOpen"
            :purchase="editing"
            :providers="providers"
            :purchase-products="purchaseProducts"
            :fixed-branch-id="branch?.id"
            :ai-result="aiResult"
            :routes="formRoutes"
            attachment-preview-route-name="sucursal.compras.adjuntos.preview"
            attachment-download-route-name="sucursal.compras.adjuntos.download"
            attachment-destroy-route-name="sucursal.compras.adjuntos.destroy"
            @close="formOpen = false; aiResult = null"
        />
```

(Read the file first to match the exact surrounding attribute order/formatting already there before editing — the props list above may not be in this exact order in the live file; add the 3 new attributes without reordering the existing ones.)

- [ ] **Step 3: Build**

```bash
vendor/bin/sail npm run build
```

Expected: green build.

- [ ] **Step 4: Regression**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Compras/PurchaseControllerTest.php
```

Expected: all passed (routes already existed and are already tested there — this task only threads props through Vue, no backend change).

- [ ] **Step 5: Manual verification**

As `admin@eltoro.test` (empresa) and `sucursal@eltoro.test`, open Compras, edit an existing compra that has an attachment, and confirm the thumbnail now appears with working preview/download/delete.

- [ ] **Step 6: Commit**

```bash
git status --short
git add resources/js/Pages/Empresa/Compras/Index.vue resources/js/Pages/Sucursal/Compras/Index.vue
git commit -m "$(cat <<'EOF'
feat(compras): Empresa/Sucursal ven adjuntos existentes al editar una compra

Wiring de las 3 props nuevas de CompraFormModal.vue a las rutas de adjuntos
que ya existían (empresa.compras.adjuntos.* / sucursal.compras.adjuntos.*,
mismas que ya usa CompraDetailModal.vue correctamente) — sin cambios de
backend.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: `CompraDetailModal.vue` — swap inline lightbox for shared `AttachmentViewerModal`

**Files:**
- Modify: `resources/js/Components/Compras/CompraDetailModal.vue`

**Interfaces:**
- Consumes: `AttachmentViewerModal.vue` from Task 1.

This is a visor-only swap — it does **not** touch the thumbnail-list markup (lines ~211–230) or the delete button, both of which already work correctly. Only the `viewer`/`openViewer`/`closeViewer` object-based lightbox is replaced.

- [ ] **Step 1: Update the script**

Add the import and switch from an object-keyed viewer to an index-keyed one (matching `GastoDetailModal.vue`'s already-established pattern):

```js
// Before
import PagoProveedorModal from './PagoProveedorModal.vue';
import HistorialTimeline from '@/Components/Historial/HistorialTimeline.vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
```

```js
// After
import PagoProveedorModal from './PagoProveedorModal.vue';
import HistorialTimeline from '@/Components/Historial/HistorialTimeline.vue';
import AttachmentViewerModal from '@/Components/AttachmentViewerModal.vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
```

Then replace the viewer state:

```js
// Before
// Visor de adjunto (lightbox)
const viewer = ref(null);
const openViewer = (att) => { viewer.value = att; };
const closeViewer = () => { viewer.value = null; };
```

```js
// After
// Visor de adjunto (AttachmentViewerModal compartido — mismo patrón que GastoDetailModal.vue)
const viewerOpen = ref(false);
const viewerIndex = ref(0);
const openViewer = (i) => { viewerIndex.value = i; viewerOpen.value = true; };
```

- [ ] **Step 2: Update the thumbnail-list template to pass an index instead of the object**

Find (inside the "Adjuntos" `<ul>`):

```html
                                <li v-for="att in purchase.attachments" :key="att.id" class="flex items-center gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                                    <button type="button" @click="openViewer(att)" class="shrink-0" :title="`Ver ${att.original_name}`">
                                        <img v-if="isImage(att)" :src="previewUrl(att)" :alt="att.original_name" loading="lazy"
                                            class="h-12 w-12 rounded-lg border border-gray-200 object-cover transition hover:opacity-80" />
                                        <span v-else class="flex h-12 w-12 items-center justify-center rounded-lg border border-gray-200 bg-gray-50 text-gray-400 transition hover:bg-gray-100">
                                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                            </svg>
                                        </span>
                                    </button>
                                    <button type="button" @click="openViewer(att)" class="flex-1 truncate text-left text-orange-700 hover:underline">{{ att.original_name }}</button>
                                    <span class="text-xs text-gray-500">{{ Math.ceil(att.size_bytes / 1024) }} KB</span>
                                    <a :href="downloadUrl(att)" class="text-xs font-medium text-gray-600 hover:text-gray-900">Descargar</a>
                                    <button v-if="!isCancelled" @click="deleteAttachment(att)" class="text-xs font-medium text-red-600 hover:text-red-800">Eliminar</button>
                                </li>
```

Replace `openViewer(att)` with `openViewer(i)` and add the index to the `v-for`:

```html
                                <li v-for="(att, i) in purchase.attachments" :key="att.id" class="flex items-center gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                                    <button type="button" @click="openViewer(i)" class="shrink-0" :title="`Ver ${att.original_name}`">
                                        <img v-if="isImage(att)" :src="previewUrl(att)" :alt="att.original_name" loading="lazy"
                                            class="h-12 w-12 rounded-lg border border-gray-200 object-cover transition hover:opacity-80" />
                                        <span v-else class="flex h-12 w-12 items-center justify-center rounded-lg border border-gray-200 bg-gray-50 text-gray-400 transition hover:bg-gray-100">
                                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                            </svg>
                                        </span>
                                    </button>
                                    <button type="button" @click="openViewer(i)" class="flex-1 truncate text-left text-orange-700 hover:underline">{{ att.original_name }}</button>
                                    <span class="text-xs text-gray-500">{{ Math.ceil(att.size_bytes / 1024) }} KB</span>
                                    <a :href="downloadUrl(att)" class="text-xs font-medium text-gray-600 hover:text-gray-900">Descargar</a>
                                    <button v-if="!isCancelled" @click="deleteAttachment(att)" class="text-xs font-medium text-red-600 hover:text-red-800">Eliminar</button>
                                </li>
```

- [ ] **Step 3: Replace the inline lightbox markup with `AttachmentViewerModal`**

Find (right after the cancel sub-modal, before the closing `</Teleport>`):

```html
                <!-- Visor de adjunto (lightbox) -->
                <div v-if="viewer" class="fixed inset-0 z-[70] flex flex-col bg-black/80" @click.self="closeViewer">
                    <div class="flex items-center justify-between gap-3 px-4 py-3 text-white">
                        <span class="truncate text-sm font-medium">{{ viewer.original_name }}</span>
                        <div class="flex shrink-0 items-center gap-4">
                            <a :href="downloadUrl(viewer)" class="text-sm hover:underline">Descargar</a>
                            <button @click="closeViewer" class="rounded-full bg-white/10 px-3 py-1 text-sm hover:bg-white/20">✕</button>
                        </div>
                    </div>
                    <div class="flex flex-1 items-center justify-center overflow-auto p-4" @click.self="closeViewer">
                        <img v-if="isImage(viewer)" :src="previewUrl(viewer)" :alt="viewer.original_name" class="max-h-full max-w-full object-contain" />
                        <iframe v-else :src="previewUrl(viewer)" :title="viewer.original_name" class="h-full w-full rounded bg-white"></iframe>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
```

Replace with:

```html
            </div>
        </Transition>
    </Teleport>

    <AttachmentViewerModal v-if="purchase"
        :show="viewerOpen"
        :attachments="purchase.attachments || []"
        :initial-index="viewerIndex"
        :preview-url="previewUrl"
        :download-url="downloadUrl"
        @close="viewerOpen = false" />
```

(This moves the viewer outside the outer `<Teleport>`/`<Transition>` wrapper as its own sibling, exactly like `GastoDetailModal.vue` already does — `AttachmentViewerModal` teleports itself to `<body>` internally, so it doesn't need to be nested inside this component's own teleport.)

- [ ] **Step 4: Build**

```bash
vendor/bin/sail npm run build
```

Expected: green build.

- [ ] **Step 5: Regression**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Compras/PurchaseControllerTest.php
```

Expected: all passed (pure frontend swap, zero backend changes).

- [ ] **Step 6: Manual verification**

Open "Ver compra" for a purchase with an attachment and confirm the full-screen viewer (with prev/next if more than one) opens instead of the old ad-hoc lightbox, and that "Descargar"/close/Escape/arrow-keys all work.

- [ ] **Step 7: Commit**

```bash
git status --short
git add resources/js/Components/Compras/CompraDetailModal.vue
git commit -m "$(cat <<'EOF'
refactor(compras): CompraDetailModal.vue usa AttachmentViewerModal compartido

Reemplaza el lightbox hecho a mano (viewer/openViewer/closeViewer por
objeto) por el visor compartido, igual patrón que ya usa GastoDetailModal.vue
(index-based, initial-index). Compras ya no tiene una segunda implementación
de visor de adjuntos — cierra el hallazgo de la spec sobre inconsistencia.
Solo cambia el visor; la lista de miniaturas y el botón eliminar no se tocan.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Backend — Caja gets attachment routes for Gastos/Compras (authorization split)

**Files:**
- Modify: `app/Http/Controllers/ExpenseAttachmentController.php`
- Modify: `app/Http/Controllers/PurchaseAttachmentController.php`
- Modify: `routes/web.php` (6 new routes)
- Modify: `tests/Feature/Sucursal/GastoControllerTest.php` (new tests)
- Modify: `tests/Feature/Compras/PurchaseControllerTest.php` (new tests)

**Interfaces:**
- Produces: routes `caja.gastos.adjuntos.download`, `caja.gastos.adjuntos.preview`, `caja.gastos.adjuntos.destroy`, `caja.compras.adjuntos.download`, `caja.compras.adjuntos.preview`, `caja.compras.adjuntos.destroy` — all reusing the existing `ExpenseAttachmentController`/`PurchaseAttachmentController` classes.
- **Behavior change for `admin-empresa`/`admin-sucursal`/`superadmin`: none.** `authorizeAccess()` is renamed to `authorizeView()` verbatim (same body), and `authorizeMutation()` is a new method that calls `authorizeView()` first, then adds a cajero-only extra check. `download()`/`preview()` call `authorizeView()`; `destroy()` calls `authorizeMutation()`.

This is the security-sensitive task the spec calls out explicitly: today `authorizeAccess()` has no branch/ownership restriction for any role other than `admin-sucursal` — a cajero reaching it (impossible until this task adds `caja.*` routes) would see/delete anyone's attachments. Write the tests **first** so the exploit is proven closed before it's ever reachable.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Sucursal/GastoControllerTest.php`, add after `test_attachment_destroy_removes_file_from_disk` (around line 172, before `test_subcategory_with_expenses_cannot_be_deleted`):

```php
    public function test_caja_can_only_view_own_expense_attachments_in_own_branch(): void
    {
        Storage::fake('local');

        $shift = \App\Models\CashRegisterShift::create([
            'user_id' => $this->cajero->id,
            'branch_id' => $this->branch->id,
            'tenant_id' => $this->tenant->id,
            'opened_at' => now(),
            'opening_amount' => 0,
        ]);

        $own = Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cash_register_shift_id' => $shift->id,
            'expense_subcategory_id' => $this->sub->id,
            'user_id' => $this->cajero->id,
            'concept' => 'Mío', 'amount' => 100, 'expense_at' => now(),
        ]);
        $ownAtt = ExpenseAttachment::create([
            'tenant_id' => $this->tenant->id,
            'expense_id' => $own->id,
            'original_name' => 'propio.jpg',
            'path' => "tenants/{$this->tenant->id}/expenses/{$own->id}/propio.jpg",
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10,
        ]);
        Storage::disk('local')->put($ownAtt->path, 'fake');

        $otherCajero = $this->makeUser('caja2@test.local', 'cajero', $this->branch->id);
        $othersExpense = Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'expense_subcategory_id' => $this->sub->id,
            'user_id' => $otherCajero->id,
            'concept' => 'De otro cajero', 'amount' => 100, 'expense_at' => now(),
        ]);
        $othersAtt = ExpenseAttachment::create([
            'tenant_id' => $this->tenant->id,
            'expense_id' => $othersExpense->id,
            'original_name' => 'ajeno.jpg',
            'path' => "tenants/{$this->tenant->id}/expenses/{$othersExpense->id}/ajeno.jpg",
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10,
        ]);
        Storage::disk('local')->put($othersAtt->path, 'fake');

        $this->actingAs($this->cajero);

        // Puede ver el suyo.
        $this->get(route('caja.gastos.adjuntos.download', [$this->tenant->slug, $own->id, $ownAtt->id]))
            ->assertOk();
        $this->get(route('caja.gastos.adjuntos.preview', [$this->tenant->slug, $own->id, $ownAtt->id]))
            ->assertOk();

        // NO puede ver el de otro cajero de su misma sucursal.
        $this->get(route('caja.gastos.adjuntos.download', [$this->tenant->slug, $othersExpense->id, $othersAtt->id]))
            ->assertForbidden();
        $this->get(route('caja.gastos.adjuntos.preview', [$this->tenant->slug, $othersExpense->id, $othersAtt->id]))
            ->assertForbidden();

        // Puede eliminar el suyo (dentro de su turno abierto).
        $this->delete(route('caja.gastos.adjuntos.destroy', [$this->tenant->slug, $own->id, $ownAtt->id]))
            ->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($ownAtt->path);

        // NO puede eliminar el de otro cajero.
        $this->delete(route('caja.gastos.adjuntos.destroy', [$this->tenant->slug, $othersExpense->id, $othersAtt->id]))
            ->assertForbidden();
    }

    public function test_admin_sucursal_access_to_expense_attachments_unaffected_by_caja_routes(): void
    {
        Storage::fake('local');

        $exp = Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'expense_subcategory_id' => $this->sub->id,
            'user_id' => $this->cajero->id,
            'concept' => 'De cajero', 'amount' => 100, 'expense_at' => now(),
        ]);
        $att = ExpenseAttachment::create([
            'tenant_id' => $this->tenant->id,
            'expense_id' => $exp->id,
            'original_name' => 'x.jpg',
            'path' => "tenants/{$this->tenant->id}/expenses/{$exp->id}/x.jpg",
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10,
        ]);
        Storage::disk('local')->put($att->path, 'fake');

        // admin-sucursal sigue pudiendo ver/borrar cualquier adjunto de su sucursal,
        // sin importar quién lo creó ni si tiene turno abierto.
        $this->actingAs($this->adminSucursal);
        $this->get(route('sucursal.gastos.adjuntos.download', [$this->tenant->slug, $exp->id, $att->id]))->assertOk();
        $this->delete(route('sucursal.gastos.adjuntos.destroy', [$this->tenant->slug, $exp->id, $att->id]))
            ->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($att->path);
    }
```

This test file already imports `Expense`, `ExpenseAttachment`, `Storage`, `RefreshDatabase`, `SeedsMetricsData` — no new `use` statements needed beyond the fully-qualified `\App\Models\CashRegisterShift::create(...)` used inline above (or add `use App\Models\CashRegisterShift;` to the top imports instead, matching the file's existing style — prefer that over the FQCN, so add it):

```php
// Add to the top-of-file use block
use App\Models\CashRegisterShift;
```

and then simplify the inline FQCN usage in the test to `CashRegisterShift::create([...])`.

In `tests/Feature/Compras/PurchaseControllerTest.php`, add after `test_attachment_destroy_removes_file` (around line 194, before `test_admin_sucursal_cannot_access_empresa_routes`):

```php
    public function test_caja_can_only_view_own_purchase_attachments_in_own_branch(): void
    {
        Storage::fake('local');

        $shift = \App\Models\CashRegisterShift::create([
            'user_id' => $this->cajero->id,
            'branch_id' => $this->branch->id,
            'tenant_id' => $this->tenant->id,
            'opened_at' => now(),
            'opening_amount' => 0,
        ]);

        $own = $this->makePurchase([
            'created_by' => $this->cajero->id,
            'cash_register_shift_id' => $shift->id,
        ]);
        $ownAtt = $own->attachments()->create([
            'tenant_id' => $own->tenant_id,
            'original_name' => 'propio.pdf',
            'path' => "tenants/{$own->tenant_id}/purchases/{$own->id}/propio.pdf",
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
        ]);
        Storage::disk('local')->put($ownAtt->path, 'fake');

        $otherCajero = $this->makeUser('caja2@test.local', 'cajero', $this->branch->id);
        $othersPurchase = $this->makePurchase(['created_by' => $otherCajero->id]);
        $othersAtt = $othersPurchase->attachments()->create([
            'tenant_id' => $othersPurchase->tenant_id,
            'original_name' => 'ajeno.pdf',
            'path' => "tenants/{$othersPurchase->tenant_id}/purchases/{$othersPurchase->id}/ajeno.pdf",
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
        ]);
        Storage::disk('local')->put($othersAtt->path, 'fake');

        $this->actingAs($this->cajero);

        // Puede ver el suyo.
        $this->get(route('caja.compras.adjuntos.download', [$this->tenant->slug, $own->id, $ownAtt->id]))
            ->assertOk();
        $this->get(route('caja.compras.adjuntos.preview', [$this->tenant->slug, $own->id, $ownAtt->id]))
            ->assertOk();

        // NO puede ver el de otro cajero (aunque Compras muestra todas las compras
        // de la sucursal en el índice, los ADJUNTOS solo son visibles para quien
        // registró la compra — regla decidida en la spec).
        $this->get(route('caja.compras.adjuntos.download', [$this->tenant->slug, $othersPurchase->id, $othersAtt->id]))
            ->assertForbidden();
        $this->get(route('caja.compras.adjuntos.preview', [$this->tenant->slug, $othersPurchase->id, $othersAtt->id]))
            ->assertForbidden();

        // Puede eliminar el suyo (dentro de su turno abierto).
        $this->delete(route('caja.compras.adjuntos.destroy', [$this->tenant->slug, $own->id, $ownAtt->id]))
            ->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($ownAtt->path);

        // NO puede eliminar el de otro cajero.
        $this->delete(route('caja.compras.adjuntos.destroy', [$this->tenant->slug, $othersPurchase->id, $othersAtt->id]))
            ->assertForbidden();
    }

    public function test_admin_empresa_access_to_purchase_attachments_unaffected_by_caja_routes(): void
    {
        Storage::fake('local');
        $purchase = $this->makePurchase(['created_by' => $this->cajero->id]);
        $att = $purchase->attachments()->create([
            'tenant_id' => $purchase->tenant_id,
            'original_name' => 'x.pdf',
            'path' => "tenants/{$purchase->tenant_id}/purchases/{$purchase->id}/x.pdf",
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
        ]);
        Storage::disk('local')->put($att->path, 'fake');

        $this->actingAs($this->adminEmpresa);
        $this->get(route('empresa.compras.adjuntos.download', [$this->tenant->slug, $purchase->id, $att->id]))->assertOk();
        $this->delete(route('empresa.compras.adjuntos.destroy', [$this->tenant->slug, $purchase->id, $att->id]))
            ->assertRedirect();
        Storage::disk('local')->assertMissing($att->path);
    }
```

Add `use App\Models\CashRegisterShift;` to the top of this file too, and simplify the inline FQCN accordingly.

- [ ] **Step 2: Run the tests to verify they fail (route not found)**

```bash
vendor/bin/sail artisan test --compact --filter=test_caja_can_only_view_own
```

Expected: FAIL — `caja.gastos.adjuntos.*`/`caja.compras.adjuntos.*` routes don't exist yet.

- [ ] **Step 3: Split `authorizeAccess()` in `ExpenseAttachmentController.php`**

Replace the whole file's `authorizeAccess` usage and add the new method. Full new content for `app/Http/Controllers/ExpenseAttachmentController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\CashRegisterShift;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Services\ExpenseAttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseAttachmentController extends Controller
{
    /**
     * Descarga autenticada de un adjunto. Valida tenant ownership
     * (y branch ownership si el usuario es admin-sucursal, o branch+dueño
     * si es cajero) antes de servir el archivo desde el disco privado.
     *
     * Acepta el gasto soft-deleted (withTrashed) para que la auditoría
     * pueda seguir consultando los archivos.
     */
    public function download(Expense $gasto, ExpenseAttachment $attachment): StreamedResponse
    {
        $this->authorizeView($gasto, $attachment);

        if (! Storage::disk(ExpenseAttachmentService::disk())->exists($attachment->path)) {
            abort(404, 'Archivo no encontrado.');
        }

        return Storage::disk(ExpenseAttachmentService::disk())
            ->download($attachment->path, $attachment->original_name, [
                'Content-Type' => $attachment->mime_type,
            ]);
    }

    /**
     * Preview en línea (no descarga). Sirve el archivo con
     * Content-Disposition: inline para que el navegador lo muestre
     * directamente (img tag o iframe). Usado por el viewer modal.
     */
    public function preview(Expense $gasto, ExpenseAttachment $attachment): Response
    {
        $this->authorizeView($gasto, $attachment);

        $disk = Storage::disk(ExpenseAttachmentService::disk());
        if (! $disk->exists($attachment->path)) {
            abort(404, 'Archivo no encontrado.');
        }

        $contents = $disk->get($attachment->path);

        return response($contents, 200, [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($attachment->original_name).'"',
            'Content-Length' => (string) $attachment->size_bytes,
            // Cache breve para no recargar el archivo cada vez que el usuario
            // abre el viewer dentro de la misma sesión.
            'Cache-Control' => 'private, max-age=300',
            // Endurecimiento: el navegador no debe ejecutar el archivo como otra cosa.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Expense $gasto, ExpenseAttachment $attachment): RedirectResponse
    {
        $this->authorizeMutation($gasto, $attachment);

        // El hook 'deleting' del modelo borra el archivo físico.
        $attachment->delete();

        return back()->with('success', 'Adjunto eliminado.');
    }

    /**
     * Ver/descargar/previsualizar. admin-empresa/superadmin: cualquiera de
     * su tenant. admin-sucursal: solo de su sucursal. cajero: solo de su
     * sucursal Y sus propios gastos (mismo filtro que ya usa
     * Caja\GastoController@index: branch_id + user_id).
     */
    private function authorizeView(Expense $expense, ExpenseAttachment $attachment): void
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if ($expense->tenant_id !== $tenant->id) {
            abort(403);
        }

        if ($attachment->expense_id !== $expense->id || $attachment->tenant_id !== $tenant->id) {
            abort(403);
        }

        if ($user->hasRole('admin-sucursal') && ! $user->hasRole('superadmin')) {
            if ($expense->branch_id !== $user->branch_id) {
                abort(403);
            }

            return;
        }

        if ($user->hasRole('cajero') && ! $user->hasRole('superadmin')) {
            if ($expense->branch_id !== $user->branch_id || $expense->user_id !== $user->id) {
                abort(403);
            }
        }
    }

    /**
     * Eliminar. Además de authorizeView, el cajero solo puede sobre gastos
     * ligados a su turno abierto (mismo campo que ya calcula `can_manage`
     * en Caja\GastoController@index: cash_register_shift_id).
     */
    private function authorizeMutation(Expense $expense, ExpenseAttachment $attachment): void
    {
        $this->authorizeView($expense, $attachment);

        $user = Auth::user();
        if ($user->hasRole('cajero') && ! $user->hasRole('superadmin')) {
            $shift = CashRegisterShift::where('user_id', $user->id)->whereNull('closed_at')->first();
            if (! $shift || $expense->cash_register_shift_id !== $shift->id) {
                abort(403, 'Solo puedes eliminar adjuntos de tus gastos del turno abierto.');
            }
        }
    }
}
```

- [ ] **Step 4: Split `authorizeAccess()` in `PurchaseAttachmentController.php`**

Full new content for `app/Http/Controllers/PurchaseAttachmentController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\CashRegisterShift;
use App\Models\Purchase;
use App\Models\PurchaseAttachment;
use App\Services\PurchaseAttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Espejo de ExpenseAttachmentController. Una sola implementación atiende
 * admin-empresa, admin-sucursal y cajero — el gating fino vive en
 * authorizeView()/authorizeMutation().
 */
class PurchaseAttachmentController extends Controller
{
    public function download(Purchase $compra, PurchaseAttachment $attachment): StreamedResponse
    {
        $this->authorizeView($compra, $attachment);

        if (! Storage::disk(PurchaseAttachmentService::disk())->exists($attachment->path)) {
            abort(404, 'Archivo no encontrado.');
        }

        return Storage::disk(PurchaseAttachmentService::disk())
            ->download($attachment->path, $attachment->original_name, [
                'Content-Type' => $attachment->mime_type,
            ]);
    }

    public function preview(Purchase $compra, PurchaseAttachment $attachment): Response
    {
        $this->authorizeView($compra, $attachment);

        $disk = Storage::disk(PurchaseAttachmentService::disk());
        if (! $disk->exists($attachment->path)) {
            abort(404, 'Archivo no encontrado.');
        }

        return response($disk->get($attachment->path), 200, [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($attachment->original_name).'"',
            'Content-Length' => (string) $attachment->size_bytes,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Purchase $compra, PurchaseAttachment $attachment): RedirectResponse
    {
        $this->authorizeMutation($compra, $attachment);

        // El hook 'deleting' del modelo borra el archivo físico.
        $attachment->delete();

        return back()->with('success', 'Adjunto eliminado.');
    }

    /**
     * Ver/descargar/previsualizar. admin-empresa/superadmin: cualquiera de
     * su tenant. admin-sucursal: solo de su sucursal. cajero: solo de su
     * sucursal — SIN filtro de dueño (Caja\PurchaseController@index ya
     * muestra todas las compras de la sucursal a cualquier cajero, a
     * diferencia de Gastos).
     *
     * OJO: esta regla es la contraria a la de gastos a propósito — no es un
     * descuido. Ver docs/superpowers/specs/2026-07-17-adjuntos-unificados-design.md.
     */
    private function authorizeView(Purchase $purchase, PurchaseAttachment $attachment): void
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if ($purchase->tenant_id !== $tenant->id) {
            abort(403);
        }

        if ($attachment->purchase_id !== $purchase->id || $attachment->tenant_id !== $tenant->id) {
            abort(403);
        }

        if ($user->hasRole('admin-sucursal') && ! $user->hasRole('superadmin')) {
            if ($purchase->branch_id !== $user->branch_id) {
                abort(403);
            }

            return;
        }

        if ($user->hasRole('cajero') && ! $user->hasRole('superadmin')) {
            if ($purchase->branch_id !== $user->branch_id || $purchase->created_by !== $user->id) {
                abort(403);
            }
        }
    }

    /**
     * Eliminar. Además de authorizeView, el cajero solo puede sobre compras
     * ligadas a su turno abierto (mismo campo que ya calcula `can_manage`
     * en Caja\PurchaseController@index: cash_register_shift_id).
     */
    private function authorizeMutation(Purchase $purchase, PurchaseAttachment $attachment): void
    {
        $this->authorizeView($purchase, $attachment);

        $user = Auth::user();
        if ($user->hasRole('cajero') && ! $user->hasRole('superadmin')) {
            $shift = CashRegisterShift::where('user_id', $user->id)->whereNull('closed_at')->first();
            if (! $shift || $purchase->cash_register_shift_id !== $shift->id) {
                abort(403, 'Solo puedes eliminar adjuntos de tus compras del turno abierto.');
            }
        }
    }
}
```

Note: in `authorizeView()` for Purchases, the `test_caja_can_only_view_own_purchase_attachments_in_own_branch` test above asserts a cajero **cannot** see another cajero's purchase attachments — this **does** require the `created_by` check inside `authorizeView` (not just branch), even though `Caja\PurchaseController@index` shows other cajeros' purchases in the list. Read the spec's "Caja: el visor debe funcionar ahí también" section again if this seems contradictory: the list index shows the *record* (folio, total, provider) to any cajero in the branch, but the *attachments* (potentially containing bank/invoice details) are scoped to the owner. This is intentional per the spec's `authorizeView` design table row.

- [ ] **Step 5: Add the 6 new routes in `routes/web.php`**

Find (inside the caja group, right after `Route::post('gastos/ia/borrador', ...)`):

```php
                Route::get('gastos', [CajaGastoController::class, 'index'])->name('gastos.index');
                Route::post('gastos', [CajaGastoController::class, 'store'])->name('gastos.store');
                Route::post('gastos/ia/borrador', [AiExpenseDraftController::class, 'store'])->name('gastos.ia.store');
                Route::get('compras', [CajaPurchaseController::class, 'index'])->name('compras.index');
                Route::post('compras', [CajaPurchaseController::class, 'store'])->name('compras.store');
                Route::post('compras/ia/borrador', [AiPurchaseDraftController::class, 'store'])->name('compras.ia.store');

                // Corrección de compras propias (turno abierto): editar, cancelar, pagar.
                Route::put('compras/{compra}', [CajaPurchaseController::class, 'update'])->whereNumber('compra')->name('compras.update');
                Route::patch('compras/{compra}/cancelar', [CajaPurchaseController::class, 'cancel'])->whereNumber('compra')->name('compras.cancel');
                Route::post('compras/{compra}/pagos', [CajaPurchaseController::class, 'storePayment'])->whereNumber('compra')->name('compras.pagos.store');
                Route::delete('compras/{compra}/pagos/{pago}', [CajaPurchaseController::class, 'destroyPayment'])->whereNumber('compra')->whereNumber('pago')->name('compras.pagos.destroy');

                // Corrección de gastos propios (turno abierto): editar, cancelar.
                Route::put('gastos/{gasto}', [CajaGastoController::class, 'update'])->whereNumber('gasto')->name('gastos.update');
                Route::delete('gastos/{gasto}', [CajaGastoController::class, 'destroy'])->whereNumber('gasto')->name('gastos.destroy');
                Route::get('historial', [CajaHistorialController::class, 'index'])->name('historial');
                Route::get('pagos', [CajaPagosController::class, 'index'])->name('pagos');
            });
```

Replace with (adds the 6 new routes, grouped near each module's other correction routes):

```php
                Route::get('gastos', [CajaGastoController::class, 'index'])->name('gastos.index');
                Route::post('gastos', [CajaGastoController::class, 'store'])->name('gastos.store');
                Route::post('gastos/ia/borrador', [AiExpenseDraftController::class, 'store'])->name('gastos.ia.store');
                Route::get('compras', [CajaPurchaseController::class, 'index'])->name('compras.index');
                Route::post('compras', [CajaPurchaseController::class, 'store'])->name('compras.store');
                Route::post('compras/ia/borrador', [AiPurchaseDraftController::class, 'store'])->name('compras.ia.store');

                // Corrección de compras propias (turno abierto): editar, cancelar, pagar.
                Route::put('compras/{compra}', [CajaPurchaseController::class, 'update'])->whereNumber('compra')->name('compras.update');
                Route::patch('compras/{compra}/cancelar', [CajaPurchaseController::class, 'cancel'])->whereNumber('compra')->name('compras.cancel');
                Route::post('compras/{compra}/pagos', [CajaPurchaseController::class, 'storePayment'])->whereNumber('compra')->name('compras.pagos.store');
                Route::delete('compras/{compra}/pagos/{pago}', [CajaPurchaseController::class, 'destroyPayment'])->whereNumber('compra')->whereNumber('pago')->name('compras.pagos.destroy');

                // Adjuntos de compras propias (ver: sucursal; eliminar: turno abierto).
                Route::get('compras/{compra}/adjuntos/{attachment}', [PurchaseAttachmentController::class, 'download'])->whereNumber('compra')->whereNumber('attachment')->name('compras.adjuntos.download');
                Route::get('compras/{compra}/adjuntos/{attachment}/preview', [PurchaseAttachmentController::class, 'preview'])->whereNumber('compra')->whereNumber('attachment')->name('compras.adjuntos.preview');
                Route::delete('compras/{compra}/adjuntos/{attachment}', [PurchaseAttachmentController::class, 'destroy'])->whereNumber('compra')->whereNumber('attachment')->name('compras.adjuntos.destroy');

                // Corrección de gastos propios (turno abierto): editar, cancelar.
                Route::put('gastos/{gasto}', [CajaGastoController::class, 'update'])->whereNumber('gasto')->name('gastos.update');
                Route::delete('gastos/{gasto}', [CajaGastoController::class, 'destroy'])->whereNumber('gasto')->name('gastos.destroy');

                // Adjuntos de gastos propios (ver: sucursal + dueño; eliminar: turno abierto).
                Route::get('gastos/{gasto}/adjuntos/{attachment}', [ExpenseAttachmentController::class, 'download'])->whereNumber('gasto')->whereNumber('attachment')->name('gastos.adjuntos.download');
                Route::get('gastos/{gasto}/adjuntos/{attachment}/preview', [ExpenseAttachmentController::class, 'preview'])->whereNumber('gasto')->whereNumber('attachment')->name('gastos.adjuntos.preview');
                Route::delete('gastos/{gasto}/adjuntos/{attachment}', [ExpenseAttachmentController::class, 'destroy'])->whereNumber('gasto')->whereNumber('attachment')->name('gastos.adjuntos.destroy');

                Route::get('historial', [CajaHistorialController::class, 'index'])->name('historial');
                Route::get('pagos', [CajaPagosController::class, 'index'])->name('pagos');
            });
```

`ExpenseAttachmentController` and `PurchaseAttachmentController` are already imported at the top of `routes/web.php` (lines 43 and 45) — no new `use` statements needed.

Verify:

```bash
vendor/bin/sail artisan route:list --name=adjuntos | grep caja
```

Expected: 6 rows (`caja.gastos.adjuntos.download`, `.preview`, `.destroy`, `caja.compras.adjuntos.download`, `.preview`, `.destroy`).

- [ ] **Step 6: Run pint**

```bash
vendor/bin/sail bin pint --dirty --format agent
```

Expected: `{"result":"pass"}`.

- [ ] **Step 7: Run the new tests to verify they pass**

```bash
vendor/bin/sail artisan test --compact --filter=test_caja_can_only_view_own
vendor/bin/sail artisan test --compact --filter=test_admin_sucursal_access_to_expense_attachments_unaffected_by_caja_routes
vendor/bin/sail artisan test --compact --filter=test_admin_empresa_access_to_purchase_attachments_unaffected_by_caja_routes
```

Expected: all passed.

- [ ] **Step 8: Full regression on both attachment-owning test files**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Sucursal/GastoControllerTest.php tests/Feature/Compras/PurchaseControllerTest.php
```

Expected: all passed — this confirms the `authorizeAccess` → `authorizeView`/`authorizeMutation` split didn't change behavior for admin-empresa/admin-sucursal on any of the pre-existing tests (`test_attachment_preview_returns_inline_disposition`, `test_attachment_download_is_protected_by_role`, `test_attachment_destroy_removes_file_from_disk`, `test_attachments_are_stored_and_listed`, `test_attachment_destroy_removes_file`).

- [ ] **Step 9: Commit**

```bash
git status --short
git add app/Http/Controllers/ExpenseAttachmentController.php app/Http/Controllers/PurchaseAttachmentController.php routes/web.php tests/Feature/Sucursal/GastoControllerTest.php tests/Feature/Compras/PurchaseControllerTest.php
git commit -m "$(cat <<'EOF'
feat(caja): rutas de adjuntos para gastos/compras propios + fix de autorización

6 rutas nuevas (caja.gastos.adjuntos.*, caja.compras.adjuntos.*) reusando
ExpenseAttachmentController/PurchaseAttachmentController. Requirió separar
authorizeAccess() en authorizeView()/authorizeMutation() (mismo patrón que
PaymentReceiptController): sin esto, un cajero habría podido ver/borrar
adjuntos de gastos/compras de OTRO cajero de su misma sucursal — el gap
existía en el código desde antes pero era inalcanzable sin estas rutas.

Reglas de ver (authorizeView): gastos = sucursal + dueño (igual que
Caja\GastoController@index filtra su listado); compras = solo sucursal, sin
filtro de dueño en el LISTADO pero SÍ en los adjuntos (a diferencia de
gastos, Compras muestra todas las compras de la sucursal a cualquier
cajero, pero sus adjuntos quedan acotados al dueño — decisión de la spec).
Eliminar (authorizeMutation) exige además turno abierto en ambos módulos.
admin-empresa/admin-sucursal/superadmin sin cambios de comportamiento
(verificado con tests dedicados + regresión completa de ambos archivos).

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: Fix Caja frontend wiring for Gastos and Compras

**Files:**
- Modify: `resources/js/Pages/Caja/Gastos/Index.vue`
- Modify: `resources/js/Pages/Caja/Compras/Index.vue`

**Interfaces:**
- Consumes: the 6 routes from Task 8, and `CompraFormModal.vue`'s 3 attachment-route props from Task 5.

This fixes the two pre-existing bugs the spec documents (`Pages/Caja/Gastos/Index.vue` pointing `GastoDetailModal`/`GastoFormModal` at the wrong route names, and `Pages/Caja/Compras/Index.vue` missing the 3 `adjunto*` keys in `cajaCompraRoutes` entirely).

- [ ] **Step 1: Fix `Pages/Caja/Gastos/Index.vue`**

Find:

```html
        <!-- Form (efectivo fijo, sin selector de sucursal ni método de pago) -->
        <GastoFormModal
            :show="formOpen"
            :mode="editId ? 'edit' : 'create'"
            :tenant-slug="tenant.slug"
            :categories="categories"
            :allow-branch-select="false"
            :fixed-branch-id="branchId"
            :expense="editId ? selected : null"
            :ai-proposal="aiProposal"
            :ai-draft-id="aiDraftId"
            :ai-attachments="aiAttachments"
            :ai-transcription="aiTranscription"
            :submit-route-name="editId ? 'caja.gastos.update' : 'caja.gastos.store'"
            attachment-destroy-route-name="caja.gastos.store"
            @close="formOpen = false; editId = null; resetAi()"
            @success="formOpen = false; editId = null; resetAi()" />

        <GastoDetailModal
            :show="detailOpen"
            :expense="selected"
            :tenant-slug="tenant.slug"
            preview-route-name="caja.gastos.index"
            download-route-name="caja.gastos.index"
            :can-edit="selected?.can_manage ?? false"
            :can-delete="selected?.can_manage ?? false"
            :payment-methods="paymentMethods"
            @close="detailOpen = false"
            @edit="onEditGasto"
            @delete="onDeleteGasto" />
```

Replace with (fixes the wrong route names and adds the 2 missing props on `GastoFormModal`):

```html
        <!-- Form (efectivo fijo, sin selector de sucursal ni método de pago) -->
        <GastoFormModal
            :show="formOpen"
            :mode="editId ? 'edit' : 'create'"
            :tenant-slug="tenant.slug"
            :categories="categories"
            :allow-branch-select="false"
            :fixed-branch-id="branchId"
            :expense="editId ? selected : null"
            :ai-proposal="aiProposal"
            :ai-draft-id="aiDraftId"
            :ai-attachments="aiAttachments"
            :ai-transcription="aiTranscription"
            :submit-route-name="editId ? 'caja.gastos.update' : 'caja.gastos.store'"
            attachment-preview-route-name="caja.gastos.adjuntos.preview"
            attachment-download-route-name="caja.gastos.adjuntos.download"
            attachment-destroy-route-name="caja.gastos.adjuntos.destroy"
            @close="formOpen = false; editId = null; resetAi()"
            @success="formOpen = false; editId = null; resetAi()" />

        <GastoDetailModal
            :show="detailOpen"
            :expense="selected"
            :tenant-slug="tenant.slug"
            preview-route-name="caja.gastos.adjuntos.preview"
            download-route-name="caja.gastos.adjuntos.download"
            :can-edit="selected?.can_manage ?? false"
            :can-delete="selected?.can_manage ?? false"
            :payment-methods="paymentMethods"
            @close="detailOpen = false"
            @edit="onEditGasto"
            @delete="onDeleteGasto" />
```

- [ ] **Step 2: Fix `Pages/Caja/Compras/Index.vue`**

Find:

```js
const cajaCompraRoutes = {
    cancel: 'caja.compras.cancel',
    pagoStore: 'caja.compras.pagos.store',
    pagoDestroy: 'caja.compras.pagos.destroy',
};
```

Replace with:

```js
const cajaCompraRoutes = {
    cancel: 'caja.compras.cancel',
    pagoStore: 'caja.compras.pagos.store',
    pagoDestroy: 'caja.compras.pagos.destroy',
    adjuntoDownload: 'caja.compras.adjuntos.download',
    adjuntoPreview: 'caja.compras.adjuntos.preview',
    adjuntoDestroy: 'caja.compras.adjuntos.destroy',
};
```

Then find:

```html
        <CompraFormModal :open="compraOpen" :purchase="editFromDetail ? selected : null" cash-mode
            :providers="providers" :purchase-products="purchaseProducts"
            :fixed-branch-id="branchId" :ai-result="compraAiResult"
            :routes="{ store: 'caja.compras.store', update: 'caja.compras.update' }"
            @close="compraOpen = false; compraAiResult = null; editFromDetail = false" />
```

Replace with:

```html
        <CompraFormModal :open="compraOpen" :purchase="editFromDetail ? selected : null" cash-mode
            :providers="providers" :purchase-products="purchaseProducts"
            :fixed-branch-id="branchId" :ai-result="compraAiResult"
            :routes="{ store: 'caja.compras.store', update: 'caja.compras.update' }"
            attachment-preview-route-name="caja.compras.adjuntos.preview"
            attachment-download-route-name="caja.compras.adjuntos.download"
            attachment-destroy-route-name="caja.compras.adjuntos.destroy"
            @close="compraOpen = false; compraAiResult = null; editFromDetail = false" />
```

- [ ] **Step 3: Build**

```bash
vendor/bin/sail npm run build
```

Expected: green build.

- [ ] **Step 4: Regression**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Sucursal/GastoControllerTest.php tests/Feature/Compras/PurchaseControllerTest.php
```

Expected: all passed (Task 8 already added and passed the caja-specific tests against the new routes; this task only fixes the Vue wiring that calls them).

- [ ] **Step 5: Manual verification**

As `cajero@eltoro.test`: register a gasto with a photo, then open it from "Mis Gastos" and confirm the thumbnail/viewer/delete now work (they didn't before this task — this was the reported bug). Do the same for "Mis Compras" (register a compra with a PDF attachment, open "Ver", confirm the attachment section — previously silently hidden — now appears and works).

- [ ] **Step 6: Commit**

```bash
git status --short
git add resources/js/Pages/Caja/Gastos/Index.vue resources/js/Pages/Caja/Compras/Index.vue
git commit -m "$(cat <<'EOF'
fix(caja): corrige wiring de rutas de adjuntos en Gastos y Compras

Pages/Caja/Gastos/Index.vue apuntaba (por error, probable copy-paste) a
caja.gastos.index/caja.gastos.store en vez de las rutas reales de adjuntos,
y ni siquiera pasaba attachment-preview/download-route-name a GastoFormModal.
Pages/Caja/Compras/Index.vue no tenía las 3 llaves de adjuntos en
cajaCompraRoutes en absoluto. Con las rutas de la tarea anterior, ambos ya
apuntan a lo correcto — un cajero ahora sí puede ver/gestionar los adjuntos
de sus propios gastos y compras.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: Documentation + spec status + final full regression

**Files:**
- Modify: `docs/modulos/comprobantes-pago.md`
- Modify: `docs/modulos/gastos.md`
- Modify: `docs/modulos/compras.md`
- Modify: `docs/superpowers/specs/2026-07-17-adjuntos-unificados-design.md` (status header only)
- Modify: `docs/README.md` (if it lists these specs/status — check before editing)

**Interfaces:** none — this task doesn't change runtime behavior, it closes out the feature per this repo's standing documentation policy (`../CLAUDE.md`: "Every feature change updates its living doc", "Status headers must be truthful").

- [ ] **Step 1: Update `docs/modulos/comprobantes-pago.md`**

Read the file first. Add a short paragraph (near the top, after the intro, or in "Principios / Decisiones" if there's a UI-focused row already) noting: the ver/gestionar panel (`PaymentReceiptsPanel.vue`) now renders real thumbnails and a full-screen viewer via the shared `AttachmentsPicker.vue`/`AttachmentViewerModal.vue` components (also used by Gastos and Compras), and a new `preview()` endpoint (`*.pagos.receipts.preview` / `*.cobros.receipts.preview`, inline `Content-Disposition`) backs the thumbnails/viewer — mirrors `ExpenseAttachmentController@preview`.

- [ ] **Step 2: Update `docs/modulos/gastos.md`**

Read the file first. If it describes the attachment UI (camera/file picker, thumbnail grid), add a line noting it's now implemented via the shared `AttachmentsPicker.vue` component (`mode="staged"`), not bespoke to this module. If it doesn't describe UI at all (backend-focused doc), add one sentence under whatever section covers `ExpenseAttachmentController` noting Caja now has its own attachment routes (`caja.gastos.adjuntos.*`) scoped to sucursal + dueño for viewing, plus turno abierto for eliminar — previously Caja had no working access to gasto attachments at all.

- [ ] **Step 3: Update `docs/modulos/compras.md`**

Read the file first. Same treatment as gastos.md: note the shared `AttachmentsPicker.vue` adoption, and that Caja now has `caja.compras.adjuntos.*` routes scoped to sucursal (no dueño filter for viewing, matching the module's existing "todas las compras de la sucursal" visibility rule) plus turno abierto for eliminar.

- [ ] **Step 4: Flip the spec's status header**

In `docs/superpowers/specs/2026-07-17-adjuntos-unificados-design.md`, change:

```
**Estado:** Aprobado — pendiente de plan
```

to:

```
**Estado:** Implementado (fecha de hoy) — ver docs/modulos/comprobantes-pago.md, docs/modulos/gastos.md, docs/modulos/compras.md
```

(Use the actual current date, not a placeholder.)

- [ ] **Step 5: Check and update `docs/README.md` if needed**

```bash
grep -n "adjuntos-unificados\|comprobantes-pago\|Estado del sistema" docs/README.md
```

If `docs/README.md` has an "Estado del sistema" table row for comprobantes de pago / Gastos / Compras, update it to reflect the unified attachment UI is live. If this spec isn't listed there at all (specs aren't always individually indexed — check the existing pattern for other specs in this table before adding a row), leave it as-is; don't invent a new table structure.

- [ ] **Step 6: Final full regression pass**

```bash
vendor/bin/sail artisan test --compact
```

Expected: full suite green (this is the same command every prior task in this feature (T1-T9) has used to close out — run it once at the very end of this plan to confirm the cumulative set of changes across all 9 tasks didn't introduce any regression anywhere else in the app).

```bash
vendor/bin/sail npm run build
```

Expected: green (final full-project build check).

```bash
vendor/bin/sail bin pint --dirty --format agent
```

Expected: `{"result":"pass"}`.

- [ ] **Step 7: Commit**

```bash
git status --short
git add docs/modulos/comprobantes-pago.md docs/modulos/gastos.md docs/modulos/compras.md docs/superpowers/specs/2026-07-17-adjuntos-unificados-design.md docs/README.md
git commit -m "$(cat <<'EOF'
docs(adjuntos): documenta componente compartido + cierra spec como implementada

Actualiza comprobantes-pago.md, gastos.md y compras.md para reflejar
AttachmentsPicker.vue/AttachmentViewerModal.vue compartidos y las rutas
nuevas de Caja. Flip del Estado de la spec a Implementado.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review Notes

**Spec coverage:**
- Clip button unchanged → no task touches it (confirmed by omission — correct).
- `AttachmentsPicker.vue` (staged + immediate, drag&drop, uploading state, empty state) → Task 3.
- `AttachmentViewerModal.vue` move + both import sites → Task 1.
- `PaymentReceiptsPanel.vue` adoption + preview() backend → Tasks 2, 3.
- `GastoFormModal.vue` adoption → Task 4.
- `CompraFormModal.vue` attachment props + adoption, wired for Empresa/Sucursal/Caja → Tasks 5, 6, 9.
- `CompraDetailModal.vue` visor swap → Task 7.
- Caja backend routes + authorizeView/authorizeMutation split → Task 8.
- Caja frontend bug fixes (Gastos wrong routes, Compras missing routes) → Task 9.
- Documentation + spec status → Task 10.

**Type/name consistency check:** `AttachmentsPicker.vue`'s props (`mode`, `attachments`, `newFiles`, `maxCount`, `allowedMimes`, `maxBytes`, `previewUrl`, `downloadUrl`, `canAdd`, `canDelete`, `uploading`, `uploadError`, `emptyStateText`) and emits (`update:newFiles`, `remove-existing`, `files-selected`) as defined in Task 3 are used identically in Task 3 (`PaymentReceiptsPanel.vue`, which maps its own `canManage`/`canDelete` computeds onto the picker's `can-add`/`can-delete`), Task 4 (`GastoFormModal.vue`, passes neither and relies on both defaulting to `true`), and Task 5 (`CompraFormModal.vue`, same). Caught and fixed during self-review: an earlier draft of this plan collapsed both into a single `canManage` prop, which would have silently broken the existing "caja can add but never delete a cobro-global receipt" rule from a previous task.

**No placeholders:** every step above shows the literal code to write or the literal command to run — no "add appropriate tests" or "similar to Task N" shorthand.
