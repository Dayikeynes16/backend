# Asistente Conversacional con IA

Asistente interno de texto + voz para `admin-empresa`, `admin-sucursal` y (con toolset operativo) `cajero`. Interpreta lenguaje natural y lo traduce a **herramientas (tools) predefinidas en PHP — nunca SQL libre** — para consultar el negocio y preparar registros. Toda escritura pasa por borrador + confirmación humana. Experiencia única en `/{tenant}/asistente` (mini-app), con item "Asistente" en la navegación de Empresa, Sucursal y Caja.

> Diseño original y decisiones: [`docs/arquitectura/ia-asistente.md`](../arquitectura/ia-asistente.md). Este documento describe lo implementado (F0–F4).

## Responsabilidades

- Responder preguntas del negocio (ventas, gastos, compras, deudas, turnos, catálogo) con cifras que salen de **queries reales**, renderizadas por la UI desde el JSON del tool (nunca del texto del LLM).
- Preparar **borradores** de escritura (gasto, proveedor, compra, abono, categorías de gasto) que el usuario revisa, edita y confirma en una tarjeta.
- Aceptar dictado por voz (Whisper) y adjuntos de imagen (recibo/factura) con extracción por visión.
- Mantener trazabilidad completa: sesiones, mensajes, tool ejecutado, parámetros, resultado, tokens, costo y latencia.

**No hace:** no ejecuta SQL libre, no decide autorización (vive en PHP), no confirma escrituras (la confirmación es una petición HTTP separada del usuario), no realiza acciones destructivas (borrados/cancelaciones masivas, refunds, reapertura de turnos) ni tiene tools para `superadmin`. Los cambios de precio SÍ existen desde F5 pero solo como borrador confirmable de admin-sucursal.

## Principios inviolables

1. **La IA no decide autorización.** `ToolRegistry` filtra tools por rol antes de exponerlas al modelo, y cada tool re-valida con `authorize()` al ejecutarse.
2. **La IA no genera cifras finales.** Los números vienen de queries (reutilizando `SalesMetrics` y los servicios de dominio); la UI los pinta desde el JSON del tool como "data cards".
3. **Toda escritura pasa por draft + confirmación humana.** No existe ninguna tool de confirmación en el registry (invariante testeado).
4. **`branch_id` se fuerza en el servidor.** Para `admin-sucursal` y `cajero`, `AbstractAssistantTool::resolveBranch()` ignora lo que pida el modelo y usa `$user->branch_id`.

## Arquitectura

```
Pages/Asistente/App.vue (experiencia única, todos los roles)
  │  POST /{tenant}/{rol}/asistente/sesiones/{s}/mensajes  (HTTP síncrono, sin streaming)
  ▼
Asistente\AssistantAppController (único; trait HandlesAssistantChat)
  │  rate limit (60/h/user, 1000/día/tenant) + budget cap mensual (402 si se agota)
  ▼
AssistantOrchestrator
  │  gpt-4o-mini · temp 0 · function calling · loop máx 5 iteraciones
  │  contexto: system prompt + envelope del tenant (sin IDs internos) + 8 turnos
  │  texto del usuario envuelto en delimitadores <<< >>> (anti prompt-injection)
  ▼
ToolRegistry (singleton, filtrado por rol)
  ├─ 11 read tools → execute() → ToolResult → data card en la UI
  └─ 10 write tools → prepareDraft() → assistant_drafts → AssistantDraftCard
                                          │ Confirmar (2ª petición HTTP)
                                          ▼
                      AssistantDraftController@confirm → DraftConfirmerRegistry
                      re-valida payload + lockForUpdate + single-use → Writer de dominio
```

Piezas backend en `app/Services/Ai/`:

| Pieza | Archivo | Rol |
|---|---|---|
| Orquestador | `Assistant/AssistantOrchestrator.php` | Loop de function calling, contexto, costos, persistencia |
| Registro de tools | `Assistant/ToolRegistry.php` (singleton en `AppServiceProvider`) | Expone al modelo solo las tools del rol |
| Cliente OpenAI | `OpenAiClient.php` | `chatWithTools()`, `transcribeAudio()` (HTTP propio, sin SDK) |
| Cliente TTS | `OpenAiClient::synthesizeSpeech()` + `Assistant/AssistantSpeechSynthesizer.php` | Voz de salida con OpenAI (`gpt-4o-mini-tts`, voz `nova`), ACTIVA en la UI desde 2026-07-07; `ElevenLabsClient` queda en el repo sin uso |
| Transcripción | `Assistant/AssistantTranscriber.php` | Whisper `whisper-1`, español |
| Borradores | `Assistant/Drafts/` (AssistantDraftService, DraftConfirmerRegistry, Confirmers) | Ciclo pending → ready → consumed/cancelled/expired |

## Tools disponibles

### Lectura (11) — se auto-ejecutan y pintan una data card

| Función | Clase | Consulta |
|---|---|---|
| `consultar_ventas` | `SalesSummaryTool` | Ventas netas/brutas, tickets, promedio, canceladas, delta vs periodo previo (reutiliza `SalesMetrics`) + cobranza del periodo con abonos a ventas de días anteriores (misma semántica que el dashboard) |
| `consultar_gastos` | `ExpenseSummaryTool` | Total, conteo, top subcategorías, por método; filtra por categoría/subcategoría |
| `consultar_productos_top` | `TopProductsTool` | Más vendidos por ingreso |
| `consultar_turnos` | `ShiftStatusTool` | Turnos abiertos + cortes recientes |
| `consultar_clientes` | `CustomerStatsTool` | Deuda pendiente (fiado) o top compradores |
| `consultar_cliente_detalle` | `CustomerDetailTool` | Detalle de UN cliente: ventas recientes con artículos (qué llevó y a qué precio), deuda y últimos abonos. Incluye cajero. Resolución difusa de nombre |
| `consultar_productos` | `ProductDetailsTool` | Precio, costo, unidad, categoría, presentaciones |
| `consultar_ventas_producto` | `ProductSalesTool` | Ventas de UN producto en un periodo con desglose por precio de venta (detecta precios preferenciales/distintos). Fecha canónica de Métricas |
| `consultar_compras` | `PurchaseSummaryTool` | Total comprado (CMV), top proveedores, saldo pendiente |
| `consultar_cuentas_por_pagar` | `AccountsPayableTool` | Saldo a proveedores + top adeudos |
| `consultar_categorias_gasto` | `ExpenseCategoriesTool` | Catálogo de categorías/subcategorías con conteos |

### Escritura (10) — solo preparan borrador, nunca persisten el registro final

| Función | Clase | Borrador de | Notas |
|---|---|---|---|
| `preparar_borrador_gasto` | `PrepareExpenseDraftTool` | Gasto | Texto o foto de recibo (visión vía `AiExpenseDraftService::extractProposal`) |
| `preparar_borrador_proveedor` | `PrepareProviderDraftTool` | Proveedor | Detecta duplicados (nombre/RFC) y los muestra antes de confirmar |
| `preparar_borrador_compra` | `PreparePurchaseDraftTool` | Compra | Texto multi-línea o foto de factura; empareja proveedor por nombre, no inventa |
| `preparar_borrador_abono` | `PreparePayablePaymentDraftTool` | Pago a compra | Resuelve por folio o proveedor; avisa si excede el saldo |
| `preparar_cobro_cliente` | `PrepareCustomerPaymentDraftTool` | Cobro global a cliente (FIFO) | Resuelve cliente por nombre (candidatos explícitos si es ambiguo); el desglose FIFO lo calcula `CustomerGlobalPaymentService::preview()`. **Confirmar exige turno abierto** y que el cliente sea de la sucursal del turno (D2); `apply()` re-calcula la distribución al confirmar |
| `preparar_pago_proveedor_cuenta` | `PrepareProviderAccountPaymentDraftTool` | Pago a cuenta a proveedor (FIFO) | Sin folio de compra ("págale 5000 a X"); desglose vía `PurchasePaymentService::previewAccountPayment()` con excedente a favor. Sin turno requerido (como el flujo web); branch forzado para admin-sucursal. Si hay folio concreto, la IA usa `preparar_borrador_abono` |
| `preparar_retiro_caja` | `PrepareCashWithdrawalDraftTool` | Retiro de caja | admin-sucursal y cajero (D6); confirmar exige turno abierto propio y cuelga el retiro de ese turno |
| `preparar_cambio_precio` | `PrepareProductPriceDraftTool` | Cambio de precio base | Solo admin-sucursal, solo productos de SU sucursal, solo `price` (D7); warnings de bajo-costo y cambio >50% |
| `preparar_borrador_categoria_gasto` | `PrepareExpenseCategoryDraftTool` | Categoría/subcategoría | Avisa colisiones de nombre |
| `editar_categoria_gasto` | `PrepareExpenseCategoryEditDraftTool` | Edición de categoría | Renombrar, descripción, activar/inactivar |

Cada tool declara `jsonSchema()` con `additionalProperties: false`. `ToolResult` separa `data` (para la card) de `forModel()` (payload acotado para la IA, con la instrucción de que no puede confirmar).

## Flujo de confirmación de borradores

1. La tool crea una fila en `assistant_drafts` (`AssistantDraftService`, status `pending` → `ready`); los adjuntos van a disco privado `tenants/{id}/assistant_drafts/{id}/`.
2. El frontend renderiza `AssistantDraftCard.vue` con el cuerpo por tipo (`ExpenseDraftCardBody`, `PurchaseDraftCardBody` con editor de líneas, etc.), editable, con **Confirmar / Cancelar**. Confirmar se habilita solo si los campos requeridos están completos.
3. **Confirmar es una segunda petición HTTP** a `AssistantDraftController@confirm` — el único punto que ejecuta la escritura real:
   - anti-IDOR (`user_id` + `tenant_id`),
   - `DraftConfirmerRegistry` despacha al confirmador del tipo, que `authorize()` (gates de sucursal como `branch_admin_expense_categories_enabled`) y **re-valida todo el payload editado** server-side,
   - `DB::transaction` + `lockForUpdate` + filtro `status=ready && expires_at>now` → consumo **single-use e idempotente** (anti doble-clic),
   - el confirmador delega en los Writers de dominio (`ExpenseWriter`, `ProviderWriter`, `PurchaseWriter`, `PurchasePaymentService`, `CustomerGlobalPaymentService`, `ExpenseCategoryWriter`) y audita.
4. Cancelar marca `cancelled` y purga archivos. TTL: 6 horas; `php artisan ai:expire-drafts` (programado cada hora) expira y limpia.

## Voz

- **Dictado (entrada) — activo.** Botón de micrófono → `useAudioRecorder` (MediaRecorder, máx 90 s) → `POST asistente/transcribir` → Whisper. El texto cae al input **para revisión antes de enviar**; el audio no se persiste.
- **Lectura en voz alta (salida) — deshabilitada en la UI desde 2026-05-18** ("voz no satisfactoria"). El backend está completo (`ElevenLabsClient`, endpoint `.../voz` que recibe el ID del mensaje — nunca texto libre — como defensa de la API key) y testeado; se re-habilita descomentando la ruta `speak` en `Pages/{Empresa,Sucursal}/Asistente.vue`.

## Seguridad

- **Tenant:** `BelongsToTenant`/`TenantScope` en `AiAssistantSession`, `AiAssistantMessage`, `AssistantDraft`; el envelope no expone IDs internos a OpenAI.
- **Sucursal:** `resolveBranch()` fuerza la sucursal del `admin-sucursal`; el envelope le oculta las demás sucursales.
- **Rol:** filtrado en registry + re-autorización por tool + middleware `role:` en rutas.
- **Anti prompt-injection:** delimitadores `<<< >>>`, system prompt que trata el input como datos, y —la defensa real— no existe ninguna tool peligrosa.
- **Costos:** rate limit por usuario y tenant, presupuesto mensual por tenant (`tenants.ai_monthly_budget_cents`, default $50 USD/mes), telemetría de tokens/costo/latencia por mensaje.

## Límites (config/ai.php)

| Parámetro | Valor |
|---|---|
| Modelo del chat | `gpt-4o-mini` (temp 0); visión en drafts usa `gpt-4o` |
| Input máximo | 2 000 caracteres |
| Historial | 8 turnos (sin re-inyectar resultados de tools históricos) |
| Iteraciones de tools por mensaje | 5 |
| TTL de borradores | 6 h |
| Rate limit | 60 msg/h/usuario · 1 000 msg/día/tenant |
| Presupuesto | `ai_monthly_budget_cents` por tenant (402 al agotarse) |

## Rutas y frontend

- **Unificación (2026-07-08):** una sola experiencia. Las URLs clásicas `/{tenant}/empresa/asistente` y `/{tenant}/sucursal/asistente` solo **redirigen** a `/{tenant}/asistente`; sus endpoints, controllers, páginas y `AsistenteChat.vue` fueron eliminados.
- Rutas neutras de la **mini-app**: `/{tenant}/asistente` (`asistente.*`), grupo multi-rol `role:admin-empresa|admin-sucursal|superadmin` (cajero excluido por decisión D1). Mismos sufijos salvo `voz` (TTS no expuesto en la mini-app).
- Controlador único: `Asistente/AssistantAppController` (trait `Concerns/HandlesAssistantChat`). `Ai/AssistantDraftController` (confirm/cancel) sin cambios.
- Frontend: `Pages/Asistente/App.vue` compone `useAssistantChat` + `Components/Asistente/chat/{MessageThread,ChatInputBar,SessionsPanel,ToolResultCard}.vue`; data cards por tool y `AssistantDraftCard.vue`. Comunicación por HTTP normal (axios), sin SSE ni polling; UI optimista al enviar.

### Mini-app móvil (`/{tenant}/asistente`)

Experiencia a pantalla completa, mobile-first, para dueños/encargados (spec
[`2026-07-06-asistente-mini-app-design.md`](../superpowers/specs/2026-07-06-asistente-mini-app-design.md)):

- `Pages/Asistente/App.vue` con layout dedicado `Layouts/AssistantAppLayout.vue`: **sin sidebar administrativo**, header compacto (logo + negocio/sucursal) y botón permanente "Salir al panel" → `route('dashboard')` (redirige según rol). Altura `100dvh` con safe-areas.
- Móvil (<lg): chat ocupa toda la pantalla; las sesiones viven en un bottom-sheet abierto desde el header. Desktop (≥lg): columna de sesiones a la izquierda, chat centrado.
- Es la **experiencia única** del asistente desde 2026-07-08 (D3 cumplida: el clásico fue absorbido y sus URLs redirigen aquí).
- **Modo simple (F4):** con el hilo vacío, la mini-app muestra `SimpleHome` — 5 acciones grandes ("¿Cómo va el negocio?", "Registrar algo", "Cobrar una deuda" y "Pagar a proveedor" con mini-diálogo guiado nombre+monto+método, "Hablar con el asistente") que componen una frase y la envían por el pipeline normal del chat (misma seguridad, mismos borradores). Preferencia en localStorage (`assistant-simple-home`); botón de inicio en el header la restaura. `AssistantAppController@index` auto-crea la primera sesión del usuario para que el primer tap nunca falle.
- **Quick actions (F4):** chips de acción sugerida (`app/QuickActions.vue`) bajo la última card de resultados — envían prompts predefinidos ("Productos más vendidos", "Cobrar una deuda", "Pagar a un proveedor", …). Viven en `MessageThread` compartido.
- F2 (cobro FIFO a clientes), F3 (pago a cuenta FIFO a proveedores), F4 (modo simple + quick actions) y F5 (cajero + retiros + precios, spec `2026-07-07-asistente-f5-extensiones-design.md`) implementadas 2026-07-07. El modo simple se adapta por rol (cajero sin resumen de negocio ni pagos a proveedor; roles con turno ven "Retirar efectivo").

## Persistencia

| Tabla | Contenido |
|---|---|
| `ai_assistant_sessions` | Sesiones por usuario (título, message_count, last_message_at) |
| `ai_assistant_messages` | Mensajes user/assistant/tool con tool_name/params/result/status + telemetría (tokens, cost_cents, latency_ms, error) |
| `assistant_drafts` | Borradores generales (type, status, payload, adjuntos, morph al registro creado, expires_at) |

Nota: el módulo de **captura IA en formularios** (modal foto+voz+texto de Gastos/Compras/Categorías/Agenda, disponible también para cajero) es independiente del chat: usa `ai_expense_drafts`/`ai_purchase_drafts`/`ai_category_drafts` y sus propios controladores `Ai/*DraftController`. Comparten `OpenAiClient`, Whisper y los servicios de extracción por visión. Ver [`docs/modulos/gastos.md`](gastos.md).

## Estado y pendientes

| Fase | Contenido | Estado |
|---|---|---|
| F0 | Infra: tablas, orquestador, rate limit, budget, UI de chat | ✅ |
| F1 | Read tools admin-empresa | ✅ (9 tools, la spec pedía 5) |
| F2 | Read tools admin-sucursal (branch forzado) | ✅ |
| F3 | Write tools draft+confirm | ✅ (6 tools; la spec pedía 2) |
| F4 | Voz — dictado Whisper / TTS ElevenLabs | ✅ dictado · TTS off en UI |
| F5 | Configuración asistida (`SuggestThemeColorsTool`) | ❌ pendiente |
| F6 | Hardening: purga mensajes >90 días, suite adversarial de prompt-injection, panel de observabilidad de costos IA | ⚠️ parcial (existe budget cap + `ai:expire-drafts`) |

Pendientes opcionales anotados: pago "a cuenta" FIFO desde el chat, merge de aliases en categorías, PDF en visión (hoy solo `image/*`), streaming de respuestas.

## Tests

`tests/Feature/Ai/` (23 archivos): sesiones y persistencia, IDOR entre usuarios y tenants, ejecución de tools y cards, `unknown_tool`, budget 402, scoping de sucursal (admin-sucursal no puede ver otra sucursal aunque lo pida explícitamente), transcripción, TTS, cada Prepare*DraftTool y cada confirmador, expiración de drafts, y `AssistantAppControllerTest` (mini-app: acceso empresa/sucursal, 403 cajero, redirect de sesión, envío por rutas neutras, IDOR de sesión).
