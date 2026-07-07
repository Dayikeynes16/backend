# Asistente IA — Mini-app móvil conversacional — Diseño

**Fecha:** 2026-07-06
**Estado:** F0–F4 implementados (F0–F1 el 2026-07-06; F2, F3 y F4 el 2026-07-07) — ver [doc vivo](../../modulos/asistente-ia.md). Solo F5 (extensiones: cajero, retiros, precios) pendiente con spec aparte (D4). Planes: `../plans/2026-07-06-asistente-mini-app-f0-f1.md`, `../plans/2026-07-06-asistente-cobro-fifo-f2.md`, `../plans/2026-07-07-asistente-pago-proveedor-f3.md`, `../plans/2026-07-07-asistente-modo-simple-f4.md`
**Autor:** colaboración con Claude (exploración de código + propuesta)

## En palabras simples (léelo primero)

Hoy el asistente de IA vive como una página más dentro del panel administrativo:
tiene sidebar, lista de sesiones y un chat que funciona bien en escritorio pero
regular en teléfono. Este diseño propone una **mini-app del asistente** dentro del
mismo sistema: una ruta nueva (`/{tenant}/asistente`) a **pantalla completa,
pensada primero para el teléfono del dueño del negocio**, con botones grandes,
dictado por voz, tarjetas con cifras grandes y confirmaciones explícitas.

**No se reescribe nada del cerebro del asistente.** El orquestador, las 15 tools,
los borradores con confirmación humana y las validaciones del backend se reutilizan
tal cual. Lo que se construye es: (1) una ruta y layout nuevos, (2) una
descomposición del componente de chat actual en piezas reutilizables, (3) dos
capacidades nuevas que hoy el asistente no tiene — **cobro FIFO a clientes** y
**pago a cuenta FIFO a proveedores** — implementadas como servicios de backend +
borradores confirmables, y (4) un "modo simple" de entrada con acciones grandes.
La página actual del asistente **no se toca ni se elimina** hasta validar la nueva.

---

## 1. Diagnóstico: cómo funciona el asistente hoy

### 1.1 Backend

- **Orquestador**: `app/Services/Ai/Assistant/AssistantOrchestrator.php` —
  `handleUserMessage()` (`:48`) persiste el mensaje del usuario, arma el system
  prompt (anti prompt-injection, prohibido inventar cifras y confirmar escrituras,
  `:348-362`) + envelope de contexto (rol, sucursales visibles, métodos de pago;
  admin-sucursal no ve otras sucursales, `:376-379`), y corre un loop de function
  calling con `gpt-4o-mini`, `temperature 0`, **máx 5 iteraciones**
  (`config('ai.assistant.max_tool_iterations')`). Historial: últimos 8 turnos.
- **Ejecución de tools** (`executeToolCall`, `:202`): registry → `authorize()` →
  `validate()` → `execute()` (lectura) o `prepareDraft()` (escritura). Cada paso
  se persiste como mensaje `role='tool'` con `tool_result`, `tool_status` y
  telemetría. Las **cards** del frontend se derivan del `tool_result`
  (`{kind, tool_name, data, summary}`); no se guardan aparte.
- **Registro**: `app/Providers/AppServiceProvider.php:43-70` (`ToolRegistry` +
  `DraftConfirmerRegistry`). El registry filtra tools por rol (`forUser()`).
  **Todas las tools declaran `rolesAllowed() = ['admin-empresa','admin-sucursal']`.**
- **Tools de lectura (9)**: `consultar_ventas`, `consultar_gastos`,
  `consultar_productos_top`, `consultar_turnos`, `consultar_clientes`
  (deuda/top compradores), `consultar_productos`, `consultar_compras`,
  `consultar_cuentas_por_pagar`, `consultar_categorias_gasto`.
- **Tools de escritura (6, todas draft-first)**: `preparar_borrador_gasto`,
  `preparar_borrador_proveedor`, `preparar_borrador_compra`,
  `preparar_borrador_abono` (pago a UNA compra de proveedor),
  `preparar_borrador_categoria_gasto`, `editar_categoria_gasto`.
  `AbstractPrepareDraftTool::execute()` lanza excepción a propósito: ninguna tool
  escribe; la escritura real solo ocurre al confirmar el draft.
- **Borradores**: `AssistantDraftService` crea con `status=Pending→Ready`,
  `branch_id = $user->branch_id`, TTL 6 h (`ai:expire-drafts` cada hora).
  `AssistantDraftController@confirm` (`app/Http/Controllers/Ai/…:33`) es el único
  punto de escritura: anti-IDOR (user+tenant → 404), `authorize()` del confirmer,
  **re-validación completa del payload editado**, single-use con
  `lockForUpdate()` sobre `status=Ready` no expirado (doble clic → 409), y
  delegación al Writer/servicio de dominio (`markConsumed()` guarda morph al
  registro creado).
- **Límites**: 60 msg/h por usuario, 1000/día por tenant (429); presupuesto
  mensual `ai_monthly_budget_cents` (default $50 USD) → 402 al agotarse.
- **Voz**: `POST …/asistente/transcribir` (trait `TranscribesAssistantAudio`) →
  `AssistantTranscriber` → `whisper-1` en español; el audio no se persiste; el
  texto vuelve al input para revisión. TTS (ElevenLabs) completo en backend pero
  apagado en UI.
- **Rutas**: duplicadas por rol — `empresa.asistente.*` (`routes/web.php:176-183`)
  y `sucursal.asistente.*` (`:396-403`), 7 rutas cada grupo. Los controllers
  `Empresa\AsistenteController` y `Sucursal\AsistenteController` son casi
  idénticos (difieren solo en la página Inertia y un redirect); comparten los
  traits de voz y el `AssistantDraftController`. **Cajero no tiene asistente.**

### 1.2 Frontend

- Dos páginas gemelas de 43 líneas (`Pages/Empresa/Asistente.vue`,
  `Pages/Sucursal/Asistente.vue`): montan `AsistenteChat` en su layout de rol y
  le pasan un mapa `routes` con nombres Ziggy. Ese desacople por props es la
  pieza clave: **no hay ni una ruta hardcodeada en `Components/Asistente/`**.
- `Components/Asistente/AsistenteChat.vue` (670 líneas) es un monolito: panel de
  sesiones + hilo + burbujas + dispatch de cards + input + voz + adjunto + código
  muerto de TTS (`:152-238`). Estado local con refs, sin Pinia, sin streaming
  (petición-respuesta síncrona con "Pensando…" y update optimista).
- **9 cards por `kind`** (mapa en `AsistenteChat.vue:33-44`, `<component :is>`):
  sales_summary, expense_summary, top_products, shift_status,
  customer_debt/customer_top_buyers, product_details, purchase_summary,
  accounts_payable, expense_categories. Fallback: JSON en `<pre>`.
- `AssistantDraftCard.vue` (296 líneas) + **6 cuerpos editables** por
  `draft_type` (gasto, compra con líneas dinámicas, proveedor, abono,
  categoría, edición de categoría). Confirmar = segunda petición HTTP explícita;
  maneja 422/409, duplicados, `missing_fields`, `warnings`.
- Voz: `composables/useAudioRecorder.js` (MediaRecorder, mime por navegador,
  90 s máx) — bien aislado y reutilizado en los 4 modales de captura IA.
- Adjuntos del chat: 1 imagen por file input, **sin cámara**. La captura rica
  (5 fotos + cámara + voz) vive aparte en 4 modales `*CapturaIAModal` duplicados;
  `CameraCaptureModal.vue` y `utils/device.js#isMobileDevice()` sí son
  reutilizables.
- **Móvil hoy**: `grid-cols-1` apila el panel de sesiones ARRIBA del chat, con
  `min-h-[600px]`. Usable pero incómodo: no hay diseño móvil dedicado.
- Layouts: cada rol tiene el suyo con sidebar inline (no hay componente Sidebar
  común). **No existe un layout "blank autenticado"**; `GuestLayout` es solo para
  invitados. Hay precedentes útiles: layout anidado (`MetricsLayout`) y selección
  dinámica de layout por rol (`Pages/Agenda/Index.vue:24-33`).

### 1.3 FIFO hoy

- **Cobro global de clientes: sin servicio dedicado.** El bucle FIFO está
  **duplicado inline** en `Sucursal/CustomerPaymentController.php:63-146` y
  `Api/Hub/CustomerPaymentController.php:103-189`:
  `pg_advisory_xact_lock(branch_id)` + `lockForUpdate`, ventas `accountable()`
  con saldo orden `created_at asc`, folio `CG-#####`, un `Payment` hijo por venta
  + `SalePaymentService::recalculate()`, cambio solo en efectivo, **exige turno
  abierto del usuario**. No hay endpoint de preview ni idempotencia por
  `client_reference` (esta solo existe en pagos unitarios del hub).
- **Proveedores: sí hay servicio** — `PurchasePaymentService`:
  `applyAccountPayment()` (`:173`, FIFO a cuenta por `purchased_at asc`, excedente
  como pago huérfano `purchase_id=null`), `applyPayment()` (a una compra),
  `recalculate()`, `cancelPayment()` (idempotente). Prohíbe método `credit`.
  Tampoco hay preview. Trait `HandlesProviderPayments` compartido
  Empresa/Sucursal; Sucursal fuerza `branch_id`
  (`Sucursal/ProviderPaymentController.php:51-54`).
- **En el asistente**: solo `preparar_borrador_abono` (una compra puntual →
  `applyPayment`). **No existe tool de cobro a clientes ni de pago a cuenta FIFO
  a proveedores, ni de retiros de caja, ni de precios** (el system prompt prohíbe
  precios explícitamente).
- Retiros de caja: `Sucursal/WithdrawalController` sin servicio, solo grupo
  sucursal, exige turno abierto del usuario.

## 2. Qué se reutiliza / qué se extrae / qué falta

**Se reutiliza sin cambios:** `AssistantOrchestrator`, las 15 tools, sesiones/
mensajes/drafts (modelos, servicio, controller de drafts), las 9 cards,
`AssistantDraftCard` + 6 cuerpos, `useAudioRecorder`, `CameraCaptureModal`,
transcripción Whisper, rate limits y presupuesto.

**Se extrae (refactor sin cambio de comportamiento):**
- Frontend: `useAssistantChat()` (estado + send optimista + transcripción +
  adjunto + errores, hoy dentro del monolito) y subcomponentes `MessageThread`,
  `MessageBubble`, `ChatInputBar`, `SessionsPanel`, `ToolResultCard` (dispatcher
  de kinds). `AsistenteChat.vue` pasa a componerlos → la página clásica queda
  idéntica.
- Backend: `CustomerGlobalPaymentService` con `preview()` y `apply()`, extraído
  del código duplicado web+hub (paga deuda técnica existente y habilita al
  asistente); `PurchasePaymentService::previewAccountPayment()` (misma query que
  `applyAccountPayment` sin persistir).
- Backend: unificar los `AsistenteController` gemelos en un controller neutro (o
  base compartida) que sirva también a la mini-app.

**Falta construir:** ruta+layout+página de la mini-app, modo simple, QuickActions,
2 tools write + 2 confirmers + 2 tipos de draft (cobro cliente, pago a cuenta
proveedor) + 2 cuerpos de card con desglose FIFO.

## 3. Ruta y layout recomendados

Grupo propio multi-rol siguiendo el patrón de agenda (`web.php:546`):

```php
Route::middleware('role:admin-empresa|admin-sucursal|superadmin') // + cajero según D1
    ->prefix('asistente')->name('asistente.')->group(function () {
        Route::get('/', [AssistantAppController::class, 'index'])->name('index');
        Route::post('/sesiones', [AssistantAppController::class, 'createSession'])->name('sesiones.store');
        Route::post('/sesiones/{session}/mensajes', [AssistantAppController::class, 'sendMessage'])->name('mensajes.store');
        Route::post('/transcribir', [AssistantAppController::class, 'transcribe'])->name('transcribir');
        Route::post('/drafts/{draft}/confirmar', [AssistantDraftController::class, 'confirm'])->name('drafts.confirm');
        Route::post('/drafts/{draft}/cancelar', [AssistantDraftController::class, 'cancel'])->name('drafts.cancel');
    });
```

- Dentro del grupo raíz `{tenant}` existente (`web`, `resolve.tenant`, `auth`,
  `ensure.tenant`) — hereda todo el aislamiento actual.
- `AssistantAppController` reutiliza la lógica de los `AsistenteController`
  actuales (idealmente extraída a una base/trait común); el scoping por
  rol/sucursal **no depende de la ruta**: lo resuelven las tools
  (`resolveBranch()`), `AssistantDraftService` (`branch_id` forzado) y los
  confirmers, todo server-side.
- Las rutas `empresa.asistente.*` y `sucursal.asistente.*` **se conservan** hasta
  deprecar el asistente clásico.
- **Layout nuevo `Layouts/AssistantAppLayout.vue`**: sin sidebar; header compacto
  (logo + nombre del negocio/sucursal + botón "Salir al panel"); alto `100dvh`
  con `env(safe-area-inset-*)`; slot inferior para la barra de input fija.
- **Volver al panel**: botón permanente en el header → `route('dashboard')`, que
  ya redirige según rol (`web.php:103-118`).

## 4. Cambios en el sidebar

Un item por layout (arrays estáticos `navLinks`/`baseNavLinks`):
- `EmpresaLayout.vue` y `SucursalLayout.vue`: item destacado "Asistente" →
  `asistente.index` (bloque visualmente diferenciado en la parte superior);
  el item actual se renombra "Asistente clásico" durante la transición.
- `CajeroLayout.vue`: solo si se aprueba D1.

## 5. Estructura de componentes propuesta

```
resources/js/
├── Pages/Asistente/App.vue                 # página Inertia de la mini-app
├── Layouts/AssistantAppLayout.vue          # header mínimo + 100dvh + safe areas
├── composables/useAssistantChat.js         # extraído de AsistenteChat.vue
└── Components/Asistente/
    ├── AsistenteChat.vue                   # refactor: compone las piezas, misma API
    ├── chat/MessageThread.vue              # hilo + burbujas + stick-to-bottom
    ├── chat/ChatInputBar.vue               # textarea + micrófono + adjunto + enviar
    ├── chat/SessionsPanel.vue              # bottom-sheet móvil / columna ≥lg
    ├── chat/ToolResultCard.vue             # dispatcher kind→card (sale del monolito)
    ├── app/SimpleHome.vue                  # modo simple: acciones grandes
    ├── app/QuickActions.vue                # chips de acción sugerida bajo cards
    ├── CustomerPaymentDraftCardBody.vue    # nuevo: desglose FIFO cliente
    └── ProviderAccountPaymentDraftCardBody.vue # nuevo: desglose FIFO proveedor
```

Las 9 cards y `AssistantDraftCard` no se mueven; las cards ganan un slot/prop
opcional de QuickActions (botones que envían un prompt predefinido al mismo hilo,
p. ej. "Ver por sucursal", "Productos más vendidos").

## 6. Flujo mobile-first

- Base 360–430 px: header 48-56 px; hilo con scroll; **barra inferior fija**
  (textarea 1 línea con auto-grow + micrófono grande ~48 px + enviar + clip).
- Cards: cifra grande protagonista (p. ej. total vendido hoy), 2-3 métricas
  secundarias, comparativa con el periodo anterior y 2-3 QuickActions táctiles.
  Sin tablas administrativas; máximos 4-5 renglones por card.
- Sesiones: icono "historial" en el header abre un bottom-sheet; nunca ocupan la
  pantalla principal (corrige el principal defecto móvil actual).
- Dictado: mismo flujo actual (transcribe → texto editable antes de enviar);
  mientras graba, la barra inferior se convierte en estado "Grabando… mm:ss" con
  botón detener grande.
- Adjuntos: en móvil `<input capture="environment">` (patrón ya usado por los
  modales de captura vía `isMobileDevice()`); en desktop `CameraCaptureModal`.
- Tablet/desktop: mismo layout centrado `max-w-2xl`; en ≥lg aparece
  `SessionsPanel` como columna izquierda.
- Estados: envío optimista; "Pensando…" existente; banner de error con
  reintento; toast al confirmar/cancelar borradores; mensajes claros para 429
  (límite por hora) y 402 (presupuesto agotado).

## 7. Modo simple

- `SimpleHome.vue` como pantalla inicial: 5 botones grandes —
  "¿Cómo va el negocio?", "Registrar algo", "Cobrar una deuda",
  "Pagar a proveedor", "Hablar con el asistente".
- Cada botón inyecta un prompt inicial o un mini-diálogo guiado de 1-2 pasos
  (p. ej. "Cobrar una deuda" → pide nombre y monto por voz o texto → envía
  "El cliente X pagó $Y en efectivo" al pipeline normal del chat). **No hay
  lógica nueva de negocio: es azúcar de entrada sobre el mismo asistente.**
- No limita nada: debajo de los botones está el input libre; tras la primera
  interacción la pantalla es el chat normal.
- Persistencia de preferencia (arrancar en modo simple o chat): localStorage en
  F4; columna de usuario solo si se pide después.

## 8. Cobro a clientes con FIFO (nuevo)

"Juan Pérez pagó $1,500 en efectivo" →

1. **Tool** `preparar_cobro_cliente` (write, draft): resuelve el cliente por
   nombre — si hay varios candidatos razonables, devuelve la lista y **exige
   selección explícita** en la card —, toma monto y método, y llama a
   `CustomerGlobalPaymentService::preview()`: distribución FIFO (ventas
   afectadas con folio/fecha/pendiente/aplicado), cambio si es efectivo, saldo
   restante. La IA solo interpreta intención y recopila datos; **no calcula**.
2. Draft type `customer_global_payment` con snapshot del preview en `payload`.
3. **Card** (`CustomerPaymentDraftCardBody`): cliente, monto (editable), método
   (editable, limitado a `payment_methods_enabled`), lista simple de ventas con
   monto aplicado, cambio y saldo final.
4. **Confirmer** `CustomerGlobalPaymentDraftConfirmer`: re-valida rol/branch/
   turno abierto, **re-calcula la distribución al confirmar** (la deuda pudo
   cambiar) y ejecuta `CustomerGlobalPaymentService::apply()` en
   `DB::transaction` con el advisory lock existente. Draft single-use
   (mecanismo existente) cubre el doble-submit.
   *(Nota de implementación 2026-07-07: se descartó el 409 por "distribución
   materialmente distinta" que proponía este punto — creaba un callejón sin
   salida en la card porque el snapshot no se actualiza. En su lugar, `apply()`
   es autoritativo: el sobrepago con método ≠ efectivo se rechaza 422 con la
   card aún editable, y en efectivo el excedente es cambio, como en una caja
   real; el mensaje de confirmación devuelve los montos reales aplicados.)*
5. Post-commit: mismos eventos `SaleUpdated` que el flujo actual.

Prerequisito: extraer `CustomerGlobalPaymentService` de los dos controllers
duplicados **como refactor puro con los tests actuales en verde** antes de añadir
`preview()`.

## 9. Pago a cuenta de proveedores con FIFO (nuevo)

Simétrico: tool `preparar_pago_proveedor_cuenta` →
`PurchasePaymentService::previewAccountPayment()` (nuevo) → draft type
`provider_account_payment` con desglose por compra (folio `CMP-…`, fecha,
pendiente, aplicado) y excedente a favor si lo hay → confirmer re-valida
(rol, branch forzado para admin-sucursal, método ≠ credit) y delega en
`applyAccountPayment()` existente. La tool actual `preparar_borrador_abono`
(compra puntual) se conserva; la nueva cubre "págale $5,000 a Carnes del Norte".

## 10. Endpoints y tools: existentes vs faltantes

**Existen y se reutilizan:** 9 read tools + 6 write tools (§1.1), endpoints de
sesiones/mensajes/transcribir/confirmar/cancelar (hoy duplicados por rol; se
agrega el grupo neutro `asistente.*` apuntando a la misma lógica), confirmers y
Writers de dominio, `applyAccountPayment` y todo el flujo de cobro global (como
lógica, no como servicio).

**Faltan (backend):**
1. `CustomerGlobalPaymentService` (extracción + `preview()`).
2. `PurchasePaymentService::previewAccountPayment()`.
3. Tools `preparar_cobro_cliente` y `preparar_pago_proveedor_cuenta`;
   2 confirmers; 2 casos en `AssistantDraftType`; registro en
   `AppServiceProvider`.
4. Grupo de rutas `asistente.*` + `AssistantAppController`.
5. (Fase posterior, según D4) tool de retiro de caja; borrador de cambio de
   precios (hoy ni siquiera existe el concepto de draft de precios).

**Faltan (frontend):** mini-app completa (§5), 2 draft bodies FIFO, QuickActions,
SimpleHome, bottom-sheet de sesiones.

## 11. Riesgos técnicos y de seguridad

- **Turno abierto (D2):** el cobro global exige turno abierto del usuario; el
  dueño (admin-empresa) normalmente no tiene turno. Mantener la restricción
  server-side y que la UI lo explique — no ocultarla solo en frontend.
- **Carrera preview→confirm:** la deuda puede cambiar entre borrador y
  confirmación. Mitigación: re-cálculo en el confirmer + 409 con desglose nuevo.
- **Desambiguación por nombre:** riesgo de aplicar dinero al cliente/proveedor
  equivocado. La tool devuelve candidatos; la card exige selección explícita; el
  confirmer valida por ID, nunca por nombre.
- **Cajero (D1):** habilitar el rol amplía superficie; hoy TODAS las tools
  permiten solo admin-empresa/admin-sucursal — habría que revisar tool por tool
  antes de agregar `cajero` a `rolesAllowed()`.
- **Refactor que toca dinero:** la extracción del FIFO de clientes debe hacerse
  con los tests de cobro global existentes en verde ANTES de cambiar nada más
  (web y hub deben quedar byte-a-byte equivalentes en comportamiento).
- **Sin superficie nueva de IDOR/tenant:** la ruta nueva vive dentro del grupo
  `{tenant}` con los mismos middleware; drafts single-use, anti-IDOR (404) y
  re-validación del confirmer se reutilizan. La IA sigue sin acceso a SQL: solo
  tools tipadas con schema cerrado (`additionalProperties: false`).
- **Costo IA:** más usuarios y el modo simple aumentan el consumo; los rate
  limits (60/h usuario, 1000/día tenant) y el presupuesto mensual (402) aplican
  sin cambios — monitorear tras el lanzamiento.

## 12. Plan por fases

| Fase | Contenido | Riesgo |
|---|---|---|
| **F0** | Refactors sin cambio de comportamiento: extraer `useAssistantChat` + subcomponentes del monolito (asistente clásico queda igual); extraer `CustomerGlobalPaymentService` de web+hub con tests en verde | Medio (toca dinero, pero es refactor puro con red de tests) |
| **F1** | Mini-app mínima: grupo `asistente.*`, `AssistantAppController`, `AssistantAppLayout`, `Pages/Asistente/App.vue`, item de sidebar, chat móvil completo con paridad funcional (consultas, drafts existentes, voz, adjunto) | Bajo |
| **F2** | Cobro FIFO a clientes: `preview()`, tool + confirmer + draft type + card body | Alto (dinero) |
| **F3** | Pago a cuenta FIFO a proveedores: `previewAccountPayment()` + tool + confirmer + card body | Medio |
| **F4** | Modo simple (`SimpleHome`) + QuickActions en cards | Bajo |
| **F5** | Extensiones según decisiones: cajero, retiros de caja, cambios de precio, TTS, deep-links al panel clásico | — |

Cada fase con su plan en `docs/superpowers/plans/` y actualización de
`docs/modulos/asistente-ia.md` + `docs/README.md`.

## 13. Archivos a crear/modificar (principales)

**Crear:**
- `resources/js/Layouts/AssistantAppLayout.vue`
- `resources/js/Pages/Asistente/App.vue`
- `resources/js/composables/useAssistantChat.js`
- `resources/js/Components/Asistente/chat/{MessageThread,MessageBubble,ChatInputBar,SessionsPanel,ToolResultCard}.vue`
- `resources/js/Components/Asistente/app/{SimpleHome,QuickActions}.vue`
- `resources/js/Components/Asistente/{CustomerPaymentDraftCardBody,ProviderAccountPaymentDraftCardBody}.vue`
- `app/Http/Controllers/Asistente/AssistantAppController.php`
- `app/Services/Payments/CustomerGlobalPaymentService.php`
- `app/Services/Ai/Assistant/Tools/{PrepareCustomerPaymentDraftTool,PrepareProviderAccountPaymentDraftTool}.php`
- `app/Services/Ai/Assistant/Drafts/Confirmers/{CustomerGlobalPaymentDraftConfirmer,ProviderAccountPaymentDraftConfirmer}.php

**Modificar:**
- `routes/web.php` (grupo `asistente.*`)
- `app/Providers/AppServiceProvider.php` (registro de tools/confirmers)
- `app/Enums/AssistantDraftType.php` (+2 casos)
- `resources/js/Components/Asistente/AsistenteChat.vue` (pasa a componer piezas)
- `resources/js/Components/Asistente/AssistantDraftCard.vue` (+2 draft types)
- `app/Http/Controllers/Sucursal/CustomerPaymentController.php` y
  `app/Http/Controllers/Api/Hub/CustomerPaymentController.php` (delegar al servicio)
- `app/Services/PurchasePaymentService.php` (`previewAccountPayment`)
- `resources/js/Layouts/{EmpresaLayout,SucursalLayout}.vue` (item sidebar)
- Docs: `docs/modulos/asistente-ia.md`, `docs/README.md`, CLAUDE.md raíz si
  cambia algo a nivel arquitectura (nueva ruta multi-rol).

**Sin migraciones previstas** (los tipos de draft son un enum PHP; el payload es
JSON).

## 14. Pruebas

- **F0:** suite existente de cobro global y asistente en verde tras los
  refactors (regresión pura). Unit de `CustomerGlobalPaymentService::preview()`
  (distribución, cambio solo efectivo, exclusiones, cliente sin deuda) y test de
  equivalencia preview↔apply sobre el mismo estado.
- **F1:** feature tests del grupo `asistente.*`: acceso por rol (cajero 403 si
  no se habilita), usuario de otro tenant 404/403, index con props correctas,
  transcribir con rate limit propio.
- **F2/F3:** unit del preview FIFO; feature tool→draft (snapshot correcto,
  candidatos ambiguos, branch forzado para admin-sucursal); confirmer: re-valida
  rol/branch/turno, deuda cambiada → 409 con desglose nuevo, single-use (segunda
  confirmación 409), expirado 409, método de pago no habilitado 422; web y hub
  siguen pasando (servicio compartido).
- **Frontend:** el repo no tiene tests JS del asistente; verificación manual
  guiada a 360 px: voz, adjunto, confirmar/cancelar draft, bottom-sheet de
  sesiones, volver al panel, estados 429/402.

## 15. Decisiones (resueltas 2026-07-06)

- **D1 — ¿Cajero en la mini-app desde F1?** **Decidido: no.** Empresa y sucursal
  primero; cajero se evaluará en F5 tras revisar `rolesAllowed()` tool por tool.
- **D2 — Cobros de clientes por admin-empresa sin turno abierto:** **Decidido:
  opción (a)** — solo roles con turno abierto pueden confirmar cobros (el dueño
  consulta y prepara; alguien con turno confirma). Se evaluará (b) (elegir un
  turno abierto de la sucursal) más adelante.
- **D3 — ¿La mini-app sustituirá al asistente clásico?** **Decidido: sí, a largo
  plazo.** Mientras convivan, **ambas superficies comparten las mismas piezas**
  (componentes, composables, tools): todo cambio del asistente aplica a las dos.
  El refactor F0 es el mecanismo que lo garantiza — el clásico queda componiendo
  exactamente los mismos subcomponentes que usa la mini-app.
- **D4 — Retiros de caja y cambios de precio vía asistente:** **Decidido: fuera
  de F1–F4.** Requerirán spec propio (retiro exige turno; precios no tiene draft
  y el system prompt hoy lo prohíbe).
