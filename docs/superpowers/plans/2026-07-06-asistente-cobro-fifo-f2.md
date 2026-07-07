# Asistente Mini-App — F2: cobro FIFO a clientes desde el chat — Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** El usuario dice "Juan Pérez pagó $1,500 en efectivo" y el asistente prepara un borrador con el desglose FIFO server-side; al confirmar, el cobro se registra por el mismo camino que la caja (spec §8 de `2026-07-06-asistente-mini-app-design.md`, decisión D2: solo con turno abierto).

**Architecture:** Se extrae el FIFO duplicado de `Sucursal/CustomerPaymentController` y `Api/Hub/CustomerPaymentController` a un servicio único `CustomerGlobalPaymentService` (`preview()` de solo lectura + `apply()` transaccional con advisory lock; usa `withoutGlobalScopes` + filtros explícitos para servir a web, hub y asistente por igual). Sobre él: tool `preparar_cobro_cliente` (draft type `customer_global_payment`) y confirmer que exige turno abierto del usuario y que el cliente pertenezca a la sucursal de ese turno; `apply()` re-calcula la distribución de forma autoritativa al confirmar.

**Decisión de diseño (desviación del spec §8):** en vez de responder 409 cuando la distribución cambió entre preview y confirm, `apply()` re-calcula siempre al confirmar: el sobrepago no-efectivo se rechaza con 422 (el card muestra el error y sigue editable) y en efectivo el excedente es cambio — igual que en una caja real; el mensaje de confirmación devuelve los montos reales aplicados. El 409 con re-confirmación creaba un callejón sin salida en el card (el snapshot nunca se actualiza). Se anota en el spec.

**Tech Stack:** Laravel 13, PHPUnit, Vue 3. Comandos vía Sail (`vendor/bin/sail artisan test --compact`, `sail npm run build`, `sail bin pint --dirty`).

---

### Task 1: `CustomerGlobalPaymentService` + refactor de los 2 controllers

**Files:**
- Create: `app/Services/CustomerGlobalPaymentService.php` (flat en `app/Services/` como `SalePaymentService` — el spec decía `Services/Payments/` pero la convención del repo es plana)
- Modify: `app/Http/Controllers/Sucursal/CustomerPaymentController.php` (`store()` delega; `destroy()`/`show()` intactos)
- Modify: `app/Http/Controllers/Api/Hub/CustomerPaymentController.php` (`store()` delega; `index()`/`destroy()` intactos)
- Red de regresión: `tests/Feature/Api/Hub/CustomerPaymentApiTest.php` (7 tests existentes)

Métodos del servicio (final class, constructor con `SalePaymentService`):
- `preview(Customer, float $amountReceived, string $method, array $excludedSaleIds = []): array` → `{sales: [{sale_id, folio, date, amount_pending, amount_to_apply, remaining_after}], total_pending, amount_to_apply, change_given, remaining_debt}`. Sin locks, sin aborts (cambio solo `cash`).
- `apply(Customer, User, array{amount_received, method, excluded_sale_ids?, notes?}): array{customer_payment, applied, affected_sale_ids}` — el código EXACTO del transaction actual: `pg_advisory_xact_lock(branch_id)`, `lockForUpdate`, abort 422 sin saldo / sobrepago no-cash, folio `CG-#####` con `withTrashed()->withoutGlobalScopes()`, `Payment` hijos + `SalePaymentService::recalculate`, `sales_affected_count`.
- `broadcastSaleUpdates(array $saleIds): void` — `SaleUpdated::dispatch` con try/catch+log por venta.
- privado `pendingSales(Customer, array $excluded)` — `Sale::withoutGlobalScopes()` + `tenant_id/customer_id/branch_id` explícitos + `accountable()` + `amount_pending>0` + orden `created_at asc`.

- [ ] Step 1: crear el servicio (código completo arriba descrito, extraído verbatim de `Sucursal/CustomerPaymentController.php:58-155`).
- [ ] Step 2: `Sucursal\CustomerPaymentController@store` conserva check de branch (403), turno abierto (403) y `RegisterCustomerPaymentRequest`; delega en `apply()` dentro del mismo try/catch `HttpException→json`; broadcast post-commit vía servicio; respuesta 201 idéntica.
- [ ] Step 3: `Api\Hub\CustomerPaymentController@store` conserva validación inline y turno (409); delega en `apply()`; broadcast con su helper; respuesta idéntica.
- [ ] Step 4: `sail artisan test --compact tests/Feature/Api/Hub/CustomerPaymentApiTest.php` → 7 PASS (regresión pura).
- [ ] Step 5: pint + commit `refactor(clientes): extraer CustomerGlobalPaymentService del FIFO duplicado web/hub`.

### Task 2: Test del servicio (preview + equivalencia)

**Files:** Create `tests/Feature/Services/CustomerGlobalPaymentServiceTest.php`

- [ ] Tests: `preview_distributes_fifo_oldest_first` (2 ventas, monto parcial → primera saldada, segunda parcial), `preview_returns_change_only_for_cash`, `apply_matches_preview_distribution` (mismos montos por venta), `apply_rejects_non_cash_overpayment` (422), `apply_aborts_without_pending_sales` (422). Usa `SeedsMetricsData` + `makeCompletedSale(['amount_paid'=>0,'amount_pending'=>X])` con `created_at` escalonados.
- [ ] Correr → PASS; commit `test(clientes): cobertura de CustomerGlobalPaymentService`.

### Task 3: Enum + tool `preparar_cobro_cliente`

**Files:**
- Modify: `app/Enums/AssistantDraftType.php` (+`CustomerGlobalPayment = 'customer_global_payment'`, label 'Cobro a cliente')
- Create: `app/Services/Ai/Assistant/Tools/PrepareCustomerPaymentDraftTool.php` (espejo de `PreparePayablePaymentDraftTool`)
- Modify: `app/Providers/AppServiceProvider.php` (registrar tool)
- Test: `tests/Feature/Ai/PrepareCustomerPaymentDraftToolTest.php`

Tool: schema `{customer_name, amount, payment_method(cash|card|transfer), notes}` (todos nullable, required-all, `additionalProperties:false`); `validate()` resuelve cliente por nombre (exacto → ILIKE parcial; 1 match = resuelto, >1 = candidatos con deuda c/u); `prepareDraft()` crea draft, calcula `distribution` vía `preview()` cuando hay cliente+monto+método, arma warnings (sin deuda / cambio en efectivo / sobrepago no-cash) y card con `options.customers` (clientes con deuda del scope, para el select) y `options.payment_methods`. Branch forzado para admin-sucursal en la búsqueda.

- [ ] Tests primero (tool resuelve cliente exacto, candidatos ambiguos, missing fields, admin-sucursal no ve clientes de otra sucursal, distribution presente) → FAIL → implementar → PASS → commit.

### Task 4: Confirmer + registro

**Files:**
- Create: `app/Services/Ai/Assistant/Drafts/Confirmers/CustomerGlobalPaymentDraftConfirmer.php`
- Modify: `app/Providers/AppServiceProvider.php` (registrar confirmer)
- Test: `tests/Feature/Ai/AssistantCustomerPaymentConfirmTest.php` (espejo de `AssistantPayablePaymentConfirmTest`)

Confirmer: `rules()` = customer_id exists (tenant + branch para admin-sucursal), amount_received gt:0, method in cash/card/transfer, notes max 500. `confirm()`: turno abierto del usuario (403 si no — D2), cliente en la sucursal del turno (403), método habilitado en la sucursal (`ValidationException`), `apply()` re-calcula autoritativo (HttpException 422 del servicio → `ValidationException` en `amount_received`), `markConsumed`, broadcast con `DB::afterCommit` (estamos dentro de la transacción del draft controller), mensaje con montos reales (aplicado, ventas, cambio).

- [ ] Tests primero: happy path FIFO (2 ventas → Payments hijos correctos + CustomerPayment + draft consumed), sin turno 403, cliente de otra sucursal que el turno 403, sobrepago transfer 422 (draft sigue Ready), doble confirmación 409, método no habilitado en branch 422 → FAIL → implementar → PASS → pint + commit.

### Task 5: Frontend

**Files:**
- Create: `resources/js/Components/Asistente/CustomerPaymentDraftCardBody.vue` (select cliente con deuda, monto, método, notas, y desglose FIFO del snapshot con nota si el form ya no coincide)
- Modify: `resources/js/Components/Asistente/AssistantDraftCard.vue` (buildForm + meta + bodyComponent + canConfirm para `customer_global_payment`; pasar `:preview` al body; mostrar `data.message` del confirm como texto de éxito)
- Modify: `resources/js/composables/useAssistantChat.js` (`preparar_cobro_cliente → 'assistant_draft'` en guessKindFromToolName)

- [ ] Implementar, `sail npm run build`, commit.

### Task 6: Docs + verificación final

- [ ] `docs/modulos/asistente-ia.md`: tool nueva en la tabla de write tools, draft type, invariante D2; `docs/modulos/clientes-cobro-global.md`: sección del servicio compartido y el nuevo consumidor asistente; `docs/README.md` estado; spec §8 anotar la desviación (re-cálculo autoritativo en vez de 409) y estado F2 implementado.
- [ ] `sail artisan test --compact tests/Feature/Ai/ tests/Feature/Api/Hub/CustomerPaymentApiTest.php tests/Feature/Services/` → PASS; suite completa → PASS; commit docs.
