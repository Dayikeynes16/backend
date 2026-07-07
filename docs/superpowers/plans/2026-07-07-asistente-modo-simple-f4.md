# Asistente Mini-App — F4: modo simple + quick actions — Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Punto de entrada para usuarios con poca experiencia (spec §7): pantalla inicial con 5 acciones grandes que inyectan prompts o mini-diálogos guiados al pipeline normal del chat, y chips de acción sugerida bajo las cards de resultados. Cero lógica de negocio nueva.

**Architecture:** Todo frontend sobre `useAssistantChat` (los botones componen una frase y llaman `chat.send()`). Único cambio backend: `AssistantAppController@index` auto-crea la primera sesión del usuario (la mini-app no debe fallar con "crea una sesión primero" al primer tap). El modo simple aparece cuando el hilo está vacío; preferencia en localStorage (`assistant-simple-home`); "Hablar con el asistente" lo descarta y un botón de inicio en el header lo restaura. QuickActions se muestran solo bajo la última respuesta del asistente (no en el historial). Ambas piezas viven en `Components/Asistente/app/` — la clásica no las monta (el modo simple es de la mini-app), pero QuickActions se integra en `MessageThread`, así que aplica a ambas superficies (D3).

### Task 1: Auto-sesión en la mini-app (backend, TDD)
- Test en `tests/Feature/Ai/AssistantAppControllerTest.php`: `test_index_auto_creates_first_session` (usuario sin sesiones → GET asistente.index → 1 sesión creada y activa) y `test_index_does_not_duplicate_sessions` (con sesión existente → sigue habiendo 1).
- `AssistantAppController`: alias del trait (`index as renderChatIndex`) y override que crea `AiAssistantSession` si el usuario no tiene ninguna.
- [ ] Test rojo → implementar → verde → pint + commit.

### Task 2: `QuickActions.vue` + integración en `MessageThread`
- `resources/js/Components/Asistente/app/QuickActions.vue`: mapa `kind → [{label, prompt}]` (sales_summary, expense_summary, top_products, customer_debt, accounts_payable, purchase_summary, shift_status); chips táctiles que hacen `chat.inputText = prompt; chat.send()`; deshabilitados mientras `chat.sending`.
- `MessageThread.vue`: `lastAssistantId` computed; tras las cards del último item assistant, `<QuickActions :kind="primer kind no-draft" :chat="chat" />`.
- [ ] Implementar + build + commit.

### Task 3: `SimpleHome.vue` + integración en `App.vue`
- `resources/js/Components/Asistente/app/SimpleHome.vue`: 5 acciones grandes —
  1. "¿Cómo va el negocio?" → envía prompt de resumen de ventas.
  2. "Registrar algo" → expande sub-botones "Un gasto" / "Una compra" → prompts que producen draft cards editables (las tools manejan campos faltantes).
  3. "Cobrar una deuda" → mini-diálogo guiado (nombre + monto + método) que compone "El cliente X pagó $Y en Z." y lo envía; atajo "¿Quién me debe?".
  4. "Pagar a proveedor" → mini-diálogo equivalente ("Págale $Y al proveedor X por Z.").
  5. "Hablar con el asistente" → emite `dismiss` (persiste `assistant-simple-home=0`).
- `Pages/Asistente/App.vue`: `showSimpleHome = pref && !chat.messages.length && !chat.sending`; `<SimpleHome v-if>` en lugar de `MessageThread`; `ChatInputBar` siempre visible; botón "inicio" (casa) en header-actions que re-activa el modo simple (y abre sesión nueva si el hilo tiene mensajes).
- [ ] Implementar + build + commit.

### Task 4: Docs + verificación
- `docs/modulos/asistente-ia.md` (sección mini-app: modo simple + quick actions + auto-sesión; F4 implementada), `docs/README.md`, spec Estado F4.
- [ ] Suites Ai + build + suite completa → PASS; commit.
