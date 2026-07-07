# Asistente Mini-App — F3: pago a cuenta FIFO a proveedores desde el chat — Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** "Págale $5,000 a Carnes del Norte" → borrador con el desglose FIFO entre las compras pendientes más antiguas del proveedor; al confirmar se ejecuta `PurchasePaymentService::applyAccountPayment()` existente (spec §9 de `2026-07-06-asistente-mini-app-design.md`).

**Architecture:** Simétrico a F2. `PurchasePaymentService` gana `previewAccountPayment()` (misma query FIFO de `applyAccountPayment` sin persistir, incluyendo el excedente a favor). Tool `preparar_pago_proveedor_cuenta` (draft type `provider_account_payment`) resuelve el proveedor por nombre (catálogo tenant-wide; candidatos explícitos si es ambiguo) y calcula el desglose acotado a la sucursal si el usuario es admin-sucursal. Confirmer sin requisito de turno (los pagos a proveedor nunca lo exigen en web para empresa/sucursal); fuerza `branch_id` para admin-sucursal, prohíbe `credit` (ya lo hace el servicio) y permite excedente (queda como pago huérfano a favor). La tool existente `preparar_borrador_abono` (a UNA compra) se conserva.

**Red de regresión:** `tests/Feature/Compras/ProviderPaymentControllerTest.php` (13 tests, incluye FIFO a cuenta y scoping por sucursal).

### Task 1: `previewAccountPayment()` + tests
- Create método en `app/Services/PurchasePaymentService.php`: `previewAccountPayment(Provider $provider, float $amount, ?int $branchId = null): array` → `{purchases: [{purchase_id, folio, date, amount_pending, amount_to_apply, remaining_after}], total_pending, amount_to_apply, surplus}`. Misma query que apply (status != cancelled, pending>0, branch opcional, orden purchased_at/id) sin lock.
- Test: `tests/Feature/Services/PurchasePaymentServicePreviewTest.php` — FIFO más antigua primero, branch filter, surplus cuando excede, equivalencia preview↔apply.
- [x] Implementar + PASS + commit.

### Task 2: Enum + tool `preparar_pago_proveedor_cuenta` + test
- `AssistantDraftType::ProviderAccountPayment = 'provider_account_payment'` (label 'Pago a cuenta a proveedor').
- `app/Services/Ai/Assistant/Tools/PrepareProviderAccountPaymentDraftTool.php` (espejo de F2): schema `{provider_name, amount, payment_method(cash|card|transfer), reference, notes, paid_at}`; resuelve proveedor exacto→parcial con candidatos (cada uno con `total_pending` en el scope); distribution vía preview (branch para admin-sucursal); warnings: sin compras pendientes / excedente a favor. `options.providers` = proveedores con saldo en el scope + `payment_methods`.
- Registro en `AppServiceProvider`. Test espejo de `PrepareCustomerPaymentDraftToolTest`.
- [x] Tests → FAIL → implementar → PASS → commit.

### Task 3: Confirmer + test
- `ProviderAccountPaymentDraftConfirmer`: rules provider_id exists (tenant), amount gt:0, payment_method in cash/card/transfer, reference max 120, notes max 500, paid_at nullable date. confirm(): `branch_id = admin-sucursal ? user->branch_id : null`; `applyAccountPayment()` (sus ValidationException salen como 422 de campo); `markConsumed(draft, $created[0])`; mensaje con montos reales (aplicado a N compras + excedente). Sin turno requerido.
- Test espejo de `AssistantCustomerPaymentConfirmTest`: happy FIFO 2 compras, excedente crea pago huérfano, credit 422, admin-sucursal solo su sucursal, single-use 409, otro user 404.
- [x] Tests → FAIL → implementar → PASS → pint + commit.

### Task 4: Frontend
- `resources/js/Components/Asistente/ProviderAccountPaymentDraftCardBody.vue` (proveedor con saldo, monto, método, referencia, fecha, notas + desglose FIFO con excedente y nota de snapshot).
- `AssistantDraftCard.vue`: buildForm/meta/bodyComponent/canConfirm para `provider_account_payment`.
- `useAssistantChat.js`: `preparar_pago_proveedor_cuenta → 'assistant_draft'`.
- [x] Build + commit.

### Task 5: Docs + verificación
- `docs/modulos/asistente-ia.md` (tabla write tools → 8, mini-app pendientes), `docs/modulos/compras.md` (nuevo consumidor del servicio), `docs/README.md`, spec Estado F3.
- [x] Suites: Ai + Compras/ProviderPaymentControllerTest + Services → PASS; suite completa → PASS; commit.
