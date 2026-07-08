# Asistente Mini-App — F5: cajero, retiros y precios — Diseño

**Fecha:** 2026-07-07
**Estado:** Implementado (2026-07-07) — ver [doc vivo](../../modulos/asistente-ia.md)
**Autor:** colaboración con Claude (exploración + decisiones del usuario)

## En palabras simples

El asistente ya opera para dueños y encargados (F0–F4). F5 lo extiende: (1) el
**cajero** entra al asistente con un juego de herramientas operativo de caja,
(2) los **retiros de efectivo** se registran desde el chat con borrador y
confirmación, y (3) el admin de sucursal puede **cambiar el precio base** de un
producto desde el chat ("sube el bistec a $240") con borrador que muestra
precio actual → nuevo.

## Decisiones (resueltas 2026-07-07)

- **D5 — Cajero: operativo de caja.** Tools del cajero: `consultar_turnos`
  (SOLO sus turnos/cortes), `consultar_clientes`, `preparar_cobro_cliente`,
  `preparar_retiro_caja`, y `preparar_borrador_gasto` / `preparar_borrador_compra`
  **solo si** su sucursal tiene `cashier_expenses_enabled` /
  `cashier_purchases_enabled` (mismos toggles que la web). **Sin** resúmenes de
  ventas, métricas, compras/proveedores ni categorías.
- **D6 — Retiros: admin-sucursal y cajero.** Siempre con turno abierto propio
  (el retiro se cuelga de ese turno), igual que la web (PR #11).
- **D7 — Precios: solo precio base, solo admin-sucursal** (los productos son
  por sucursal y empresa no los gestiona en web). Presentaciones quedan fuera.

## Diseño

### Scoping por rol (punto único)
- `AbstractAssistantTool::resolveBranch()` fuerza la sucursal propia también
  para **cajero** (hoy solo admin-sucursal). El envelope del orquestador oculta
  las demás sucursales igual que a admin-sucursal.
- `ShiftStatusTool`: si el usuario es cajero, filtra además `user_id` — ve solo
  SUS turnos abiertos y SUS cortes recientes (no los totales de otros).
- Gasto/Compra para cajero: `authorize()` de las tools y confirmers exige el
  toggle de sucursal correspondiente (defensa en profundidad, como los
  controllers de Caja).
- Cobro global: `PrepareCustomerPaymentDraftTool` y su confirmer fuerzan branch
  para cajero igual que para admin-sucursal (el hub ya deja cobrar al cajero).

### Retiros (`preparar_retiro_caja` → draft `cash_withdrawal`)
- Schema `{amount, reason}`; card editable; warning si no hay turno abierto.
- Confirmer: turno abierto propio (403 si no), crea `CashWithdrawal`
  (`shift_id` del turno, `user_id`, `amount`, `reason`, `created_at` now).

### Precios (`preparar_cambio_precio` → draft `price_change`)
- Schema `{product_name, new_price}`. Resuelve producto por nombre en la
  sucursal del usuario (exacto → parcial; candidatos con precio actual si es
  ambiguo). Card: precio actual → nuevo, unidad, warnings ("queda por debajo
  del costo", "cambio mayor al 50%").
- Confirmer: producto de la sucursal del usuario (403 si no), actualiza SOLO
  `price`. System prompt: se retira "modificar precios" de la lista de acciones
  imposibles (ahora es un borrador confirmable).

### Frontend
- Rutas `asistente.*` aceptan cajero; item "Asistente" en `CajeroLayout`.
- `SimpleHome` filtra acciones por rol (cajero no ve "¿Cómo va el negocio?" ni
  "Pagar a proveedor"; sí "Cobrar una deuda", "Registrar algo" y "Retirar
  efectivo" — este último se agrega como acción para roles con turno).
- Card bodies nuevos: `CashWithdrawalDraftCardBody`, `PriceChangeDraftCardBody`.

### Seguridad
Sin superficie nueva: mismo grupo `{tenant}`, drafts single-use, re-validación
en confirmers, branch siempre forzado server-side. El costo IA del cajero corre
contra los mismos rate limits y presupuesto del tenant.

### Tests
Acceso cajero a la mini-app (antes 403 → ahora 200); registry por rol (cajero
sin `consultar_ventas`); gasto/compra gated por toggle; retiro (happy, sin
turno 403, single-use); precio (resolución, otra sucursal, actualización, bajo
costo warning); regresión completa.
