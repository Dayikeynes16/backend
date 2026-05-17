# Gastos

Registro trazable de gastos operativos de la empresa (servicios, insumos, nómina, mantenimiento, transporte, renta, etc.) con archivos adjuntos como evidencia. Aislamiento multi-tenant estricto.

## Responsabilidades

- Mantener un catálogo de **categorías** y **subcategorías** a nivel tenant.
- Registrar gastos con monto, concepto, subcategoría, fecha/hora editable, sucursal opcional, descripción y adjuntos.
- Conservar evidencia (tickets, facturas, comprobantes) en almacenamiento privado, accesible sólo a usuarios autorizados.
- Soft-delete con motivo para no perder información financiera.
- Filtros y agregaciones para reporte (base preparada para utilidad real en el dashboard).

## Modelo de datos

```
expense_categories  (tenant_id, name, description?, aliases?, status, created_by, timestamps)
└─ expense_subcategories (tenant_id, expense_category_id, name, description?, aliases?, status, created_by, timestamps)
   └─ expenses (tenant_id, branch_id, expense_subcategory_id, user_id, updated_by?,
                cancelled_by?, concept, amount(12,2), payment_method?, expense_at,
                description?, deleted_at, cancellation_reason?, timestamps)
      └─ expense_attachments (expense_id, tenant_id, uploaded_by?, original_name,
                                path, mime_type, size_bytes, timestamps)
```

### Decisiones

| Decisión | Razón |
|---|---|
| Categorías/subcategorías a nivel tenant | Reportes consolidados agrupan por id, sin duplicación entre sucursales. |
| `branch_id` **NOT NULL** en `expenses` | Cada gasto pertenece a una sucursal. Reportes por sucursal limpios sin huecos. Gastos verdaderamente corporativos se asignan a la sucursal matriz. |
| Soft delete del gasto | Trazabilidad financiera: nunca perder un registro. |
| `tenant_id` denormalizado en `expense_attachments` | Validar acceso a la descarga sin JOIN. |
| `expense_at` separado de `created_at` | El usuario puede capturar gastos de días anteriores. **El usuario solo elige el día**; el sistema estampa la hora actual del registro. |
| Adjuntos en disco `local` (privado) | Tickets/facturas son sensibles — sólo descarga/preview autenticado. |
| Categorías y gastos NO se siembran por defecto en producción | Cada empresa crea las suyas según su operación. `DemoSeeder` (sólo local/testing) sí siembra un catálogo realista con descripciones y aliases para alimentar el flujo IA. |
| `description` y `aliases` en categorías/subcategorías | Mejora la clasificación automática por IA (Fase 0 del flujo "Registrar gasto con IA"). `aliases` evita duplicados ("Gasolina" vs "Combustible" vs "Diésel"). Editables sólo por admin-empresa. |
| `payment_method` opcional en `expenses` | Reutiliza `App\Enums\PaymentMethod` (`cash`, `card`, `transfer`). Se omite `credit` en gastos. Es nullable: gastos viejos y captura express pueden quedar sin método. |

## Roles y permisos

| Acción | superadmin | admin-empresa | admin-sucursal | cajero |
|---|:-:|:-:|:-:|:-:|
| Ver/editar/eliminar gastos del tenant | ✅ | ✅ todas las sucursales | sólo su sucursal | ❌ |
| Crear gasto (con sucursal obligatoria) | ✅ | ✅ elige sucursal | ✅ forzado a la suya | ❌ |
| CRUD categorías y subcategorías | ✅ | ✅ | ❌ (sólo lectura) | ❌ |
| Descargar / previsualizar adjuntos | ✅ | ✅ todas | ✅ sólo de su sucursal | ❌ |

Implementación: middleware `role:...` en cada grupo de rutas + checks manuales `tenant_id`/`branch_id` en cada controller (igual que `WithdrawalController`).

## Rutas

### Empresa (admin-empresa)

```
GET    /{tenant}/empresa/gastos                              empresa.gastos.index
POST   /{tenant}/empresa/gastos                              empresa.gastos.store
PUT    /{tenant}/empresa/gastos/{gasto}                      empresa.gastos.update
DELETE /{tenant}/empresa/gastos/{gasto}                      empresa.gastos.destroy

POST   /{tenant}/empresa/gastos/categorias                   empresa.gastos.categorias.store
PUT    /{tenant}/empresa/gastos/categorias/{category}        empresa.gastos.categorias.update
DELETE /{tenant}/empresa/gastos/categorias/{category}        empresa.gastos.categorias.destroy

POST   /{tenant}/empresa/gastos/subcategorias                empresa.gastos.subcategorias.store
PUT    /{tenant}/empresa/gastos/subcategorias/{subcategory}  empresa.gastos.subcategorias.update
DELETE /{tenant}/empresa/gastos/subcategorias/{subcategory}  empresa.gastos.subcategorias.destroy

GET    /{tenant}/empresa/gastos/{gasto}/adjuntos/{attachment}  empresa.gastos.adjuntos.download
DELETE /{tenant}/empresa/gastos/{gasto}/adjuntos/{attachment}  empresa.gastos.adjuntos.destroy
```

### Sucursal (admin-sucursal)

```
GET    /{tenant}/sucursal/gastos                              sucursal.gastos.index
POST   /{tenant}/sucursal/gastos                              sucursal.gastos.store
PUT    /{tenant}/sucursal/gastos/{gasto}                      sucursal.gastos.update
DELETE /{tenant}/sucursal/gastos/{gasto}                      sucursal.gastos.destroy

GET    /{tenant}/sucursal/gastos/{gasto}/adjuntos/{attachment}  sucursal.gastos.adjuntos.download
DELETE /{tenant}/sucursal/gastos/{gasto}/adjuntos/{attachment}  sucursal.gastos.adjuntos.destroy
```

## Validaciones

| Campo | Reglas |
|---|---|
| `concept` | required, string, max 160 |
| `amount` | required, numeric, min 0.01, max 99,999,999.99 |
| `expense_subcategory_id` | required, exists del tenant actual con `status='active'` |
| `expense_date` | required, formato `Y-m-d`, ≤ mañana (tolerancia TZ) |
| `payment_method` | nullable, `Rule::enum(PaymentMethod)` — slugs `cash` / `card` / `transfer` |
| `description` | nullable, string, max 1000 |
| `branch_id` | **required**; debe pertenecer al tenant; admin-sucursal se fuerza a su branch |
| `attachments[]` | array, max 5 elementos por gasto |
| `attachments.*` | mimes:jpg,jpeg,png,webp,pdf · mimetypes verificados · max 5 MB |

### Categorías y subcategorías

| Campo | Reglas |
|---|---|
| `name` | required, string, max 120, único dentro del tenant (para categorías) o dentro de la categoría padre (para subcategorías) |
| `description` | nullable, string, max 500. Texto interno que orienta a la IA al clasificar gastos. |
| `aliases` | nullable, array, max 10 elementos. Cada uno string max 60. Se normalizan: trim, drop vacíos, dedupe case-insensitive. Si queda vacío se persiste `null`. |
| `status` | required en `update`, `active` / `inactive` |

> **Fecha vs hora:** el form recibe sólo `expense_date` (YYYY-MM-DD). El backend
> compone `expense_at = expense_date + now()->format('H:i:s')`. Así, capturar
> un gasto del día anterior estampa la hora actual del registro automáticamente.

## Almacenamiento de archivos

- Disco: `local` (privado, `storage/app/private`).
- Path: `tenants/{tenant_id}/expenses/{expense_id}/{uuid}.{ext}`.
- Subida vía `App\Services\ExpenseAttachmentService::attach()`.
- **Dos endpoints** de servido:
  - `download` — `Storage::download()` con `Content-Disposition: attachment` (descarga forzada).
  - `preview` — `Storage::get()` con `Content-Disposition: inline` (visualiza en `<img>` o `<iframe>` sin descargar).
- Ambos validan `tenant_id` y, para `admin-sucursal`, también `branch_id`.
- Eliminación física: hook `deleting` en `ExpenseAttachment` borra el archivo. El soft-delete del gasto **no** borra archivos (auditoría).
- Frontend: `Components/Gastos/AttachmentViewerModal.vue` usa el endpoint `preview` para imagen (img tag) y PDF (iframe). Botón secundario "Descargar" usa el endpoint `download`.

## UI

Default de listado: **gastos del día actual**. El backend aplica `expense_at >= today AND <= today` cuando no llegan `from`/`to`. Filtros disponibles: rango con presets (Hoy, Ayer, 7 días, Este mes, Mes pasado, Este año), Personalizado y un día específico (mismo `from=to`).

### `/empresa/gastos`

Pantalla con dos pestañas:

- **Gastos**: KPIs (Total filtrado, # Gastos, Promedio), filtros (DateField rango, sucursal, categoría → subcategoría dependiente, búsqueda), modales de form y detalle. Adjuntos visibles como badge azul con conteo.
- **Categorías**: lista de categorías con sus subcategorías. CRUD inline. Bloqueo de borrado si la categoría tiene subcategorías o la subcategoría tiene gastos. Empty state con CTA cuando no hay categorías.

### `/sucursal/gastos`

KPIs + filtros (DateField rango, categoría, subcategoría, búsqueda). Si el tenant no tiene categorías configuradas, banner ámbar indicando contactar al admin de empresa. Sucursal del gasto se fija automáticamente a `$user->branch_id`.

### Componentes reutilizables

- `Components/DateField.vue` — selector de fecha tipo iOS, popover, sin dependencias. Modos `single` y `range`. Presets integrados (Hoy/Ayer en single; Hoy/Ayer/7 días/Este mes/Mes pasado/Este año en range). **Reemplaza el input nativo `<input type="date">` en todo el sistema** — también lo usa `Metrics/DateRangeFilter.vue`.
- `Components/Gastos/GastoFormModal.vue` — form crear/editar con cascada categoría → subcategoría, fecha (sólo día), multi-upload con validación cliente, eliminación de adjuntos existentes.
- `Components/Gastos/GastoDetailModal.vue` — detalle con monto destacado y grid de adjuntos como thumbnails. Click abre el viewer.
- `Components/Gastos/AttachmentViewerModal.vue` — visor inline para imagen (img) y PDF (iframe). Navegación prev/next, descarga secundaria, atajos de teclado (Esc, ArrowLeft, ArrowRight).

## Auditoría y trazabilidad

| Campo | Significado |
|---|---|
| `expenses.user_id` | Quién creó el gasto |
| `expenses.updated_by` | Último usuario que lo editó |
| `expenses.cancelled_by` | Quién lo eliminó |
| `expenses.cancellation_reason` | Motivo opcional al eliminar |
| `expenses.created_at`/`updated_at`/`deleted_at` | Timestamps automáticos de Eloquent |
| `expense_attachments.uploaded_by` | Quién subió cada archivo |

En F2 se planea agregar tabla `expense_audit_log` con before/after JSON.

## Reportes (base preparada)

`expenses` tiene índices `(tenant_id, expense_at)` y `(branch_id, expense_at)` para listados temporales eficientes. Las agregaciones por subcategoría/sucursal/usuario quedan disponibles para el cálculo de utilidad real en el dashboard:

```
utilidad = ventas - costo de producción - gastos
```

## Comandos rápidos

```bash
# Resetear DB (módulo de Gastos arranca vacío — sin categorías sembradas)
php artisan migrate:fresh --seed

# Tests del módulo
php artisan test --filter='Empresa\\GastoControllerTest|Sucursal\\GastoControllerTest'
```

## Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Filtración cross-tenant de archivos | Disco privado + ruta de descarga que valida `tenant_id` y `branch_id`. Test feature `attachment download is protected by tenant`. |
| Archivos huérfanos al borrar | Hook `deleting` en `ExpenseAttachment` borra el archivo físico. Test `attachment destroy removes file from disk`. |
| Subcategoría borrada con gastos | `restrictOnDelete` a nivel BD + UX bloquea hard-delete con `withTrashed` count. Test `subcategory with expenses cannot be deleted`. |
| MIME falseado | Doble validación: `mimes` (extensión) + `mimetypes` (real). Test `attach rejects disallowed mime`. |
| Branch_id manipulado por admin-sucursal | Controller fuerza `$user->branch_id` y rechaza updates de otra sucursal con 403. Test `admin sucursal creates expense forced to own branch`. |

## Roadmap

- **F2**: integración con dashboard de utilidad real, exportación CSV/PDF, agregaciones por subcategoría/sucursal, tabla de auditoría con before/after.
- **F3**: aprobaciones por umbral, recurrencia (renta mensual), proveedores, registro rápido desde Caja.

## Registrar gasto con IA (iniciativa en curso)

Flujo paralelo al manual donde el usuario aporta foto/audio/texto del comprobante y la IA prerellena el formulario. La IA nunca persiste — sólo genera una propuesta editable que pasa por el mismo `GastoController@store` después de revisión humana.

**Fase 0 — preparación (este merge):**

- Migración `2026_05_17_000001_add_ai_classification_fields_to_expense_module.php` añade:
  - `expense_categories.description` (text) y `expense_categories.aliases` (json).
  - `expense_subcategories.description` y `expense_subcategories.aliases` (mismas reglas).
  - `expenses.payment_method` (string 20, nullable, valida contra `App\Enums\PaymentMethod`).
- UI de categorías permite editar descripción y aliases (separados por coma).
- `GastoFormModal` muestra selector de método de pago (botones efectivo/tarjeta/transferencia).
- `DemoSeeder` siembra catálogo realista (Transporte, Insumos, Servicios) con descripciones y aliases.

**Fase 1 — Captura por texto + foto con GPT-4o:**

- Migración `2026_05_17_000002_create_ai_expense_drafts_table.php`: tabla `ai_expense_drafts` con telemetría (`prompt_tokens`, `completion_tokens`, `latency_ms`), `attachment_paths` (json), `raw_response` y `parsed_proposal` (json), FKs a `expense_id` y `user_id`, scoped por `tenant_id`. Estados via `App\Enums\AiDraftStatus`: `pending` → `ready` | `failed`; `consumed` al confirmar el gasto; `expired` cuando F4 limpie drafts vencidos.
- `config/ai.php` configurable vía `OPENAI_API_KEY`, `AI_EXPENSES_MODEL` (default `gpt-4o`), `OPENAI_TIMEOUT`, `AI_EXPENSES_TEMPERATURE`, `AI_DRAFT_TTL_HOURS`.
- Endpoints `POST /{tenant}/empresa/gastos/ia/borrador` y `POST /{tenant}/sucursal/gastos/ia/borrador` (mismo controlador `App\Http\Controllers\Ai\ExpenseDraftController`):
  - Acepta `input_text` (max 2000 char) y/o `attachments[]` (jpg/png/webp, max 5 imágenes, 5 MB c/u). En F1 los PDF se rechazan.
  - Guarda imágenes en `tenants/{tenant_id}/ai_drafts/{draft_id}/{uuid}.{ext}` (disco privado).
  - Construye contexto vía `App\Services\Ai\ExpenseContextBuilder` (categorías activas + descripciones + aliases + sucursales visibles + reglas; sin datos sensibles).
  - Llama a OpenAI Chat Completions vía `App\Services\Ai\OpenAiClient` (wrapper sobre `Illuminate\Support\Facades\Http`, sin dependencias nuevas).
  - `App\Services\Ai\AiExpenseProposalParser` valida la respuesta: resuelve `expense_subcategory_id` contra el catálogo (descarta ids inventados), normaliza monto/fecha/payment_method.
  - Devuelve JSON `{ draft_id, status, proposal, attachments }`.
- Frontend:
  - `Components/Gastos/GastoCapturaIAModal.vue` — modal con textarea + cámara + upload de imágenes, loading state durante el análisis.
  - `composables/useExpenseAiDraft.js` — `submitDraft()` con `axios` y `applyProposalToForm()` que mapea la propuesta al form del modal.
  - `GastoFormModal` extendido con props `aiProposal`, `aiDraftId`, `aiAttachments`. Si la propuesta viene, prerellena el form, marca los campos sugeridos con badge ✨ + ring violeta, y muestra un banner con la confianza global (verde/ámbar/rojo).
  - Botón "Registrar con IA" (gradiente violeta-fucsia) en `Empresa/Gastos/Index.vue` y `Sucursal/Gastos/Index.vue`.
- `GastoController@store` ahora acepta `ai_draft_id` opcional. Al recibirlo, abre transacción, crea el `Expense`, mueve los archivos del draft vía `ExpenseAttachmentService::attachFromDraft()` y marca el draft como `consumed` con `expense_id`/`consumed_at`. Si el draft no pertenece al tenant o no está `ready`, lo ignora silenciosamente (captura manual sigue funcionando).

**Riesgos mitigados en F1:**

| Riesgo | Mitigación |
|---|---|
| IA inventa ids de subcategoría/sucursal | `AiExpenseProposalParser` valida cada id contra el catálogo del tenant. Test `draft_invented_subcategory_id_is_dropped`. |
| IA devuelve JSON malformado | `extractJsonFromResponse` tolera markdown ```json; si sigue inválido, draft pasa a `failed` y el endpoint devuelve 502 con mensaje neutro. |
| Inyección de prompt en `input_text` | Texto del usuario va envuelto en delimitadores `<<< ... >>>` aparte del system prompt. |
| Datos sensibles enviados a OpenAI | `ExpenseContextBuilder` filtra explícitamente (sólo `slug`/`name` del tenant, sólo categorías activas, sólo branches visibles, nada de gastos previos ni datos fiscales). |
| Draft consumido dos veces | `lockForUpdate()` en `resolveAiDraft` y status filtrado a `ready` — el segundo consumo no encuentra el draft. |
| Cross-tenant: usar draft de otro tenant | `where('tenant_id', $tenantId)` en `resolveAiDraft` lo descarta. Test `consuming_draft_from_other_tenant_is_ignored`. |
| API key faltante | `OpenAiClient::fromConfig()` lanza `RuntimeException` → 502 con mensaje neutro al usuario. |

**Fase 2 — Nota de voz con Whisper:**

- Migración `2026_05_17_000003_add_audio_to_ai_expense_drafts`: columnas `audio_path` (string nullable) y `audio_transcription` (text nullable) en `ai_expense_drafts`.
- `config/ai.php`: nuevos `transcription_model` (default `whisper-1`), `transcription_language` (default `es`), `max_audio_bytes` (default 10 MB), `max_audio_seconds` (default 90).
- `App\Services\Ai\OpenAiClient::transcribeAudio()` — POST multipart a `/v1/audio/transcriptions` con Whisper. Devuelve el texto transcrito.
- `AiExpenseDraftService` ahora acepta `?UploadedFile $audio`. Si llega, guarda el archivo en el mismo directorio del draft (`audio-{uuid}.{ext}`), transcribe con Whisper PRIMERO, combina la transcripción con `input_text` (delimitada con `[Nota de voz transcrita]`), y pasa el texto combinado al prompt multimodal de GPT-4o. La transcripción queda persistida en `audio_transcription` y se expone en la respuesta JSON al frontend.
- `ExpenseDraftController` acepta el campo `audio` en multipart, valida `mimes:webm,ogg,oga,mp3,mpga,m4a,mp4,wav,flac,aac` (extensión, no mimetype — los blobs de MediaRecorder no siempre tienen mime coherente entre navegadores) y tamaño ≤ 10 MB. Whisper rechaza por su cuenta los formatos que no entiende.
- Frontend:
  - `composables/useAudioRecorder.js` — wrapper sobre `MediaRecorder` API. Devuelve `isSupported`, `isRecording`, `duration` (segundos), `audioBlob`, `audioUrl`, `startRecording`, `stopRecording`, `reset`, `error`. Auto-stop a `maxSeconds`. Revoca objectURL en reset/unmount.
  - `GastoCapturaIAModal` añade sección "Nota de voz": botón redondo para iniciar; durante grabación muestra timer con punto pulsando + botón "Detener"; cuando hay grabación lista, reproductor `<audio controls>` + botón papelera para regrabar.
  - `useExpenseAiDraft.submitDraft` ahora acepta `audio` (Blob), lo añade al FormData como `audio` con nombre amigable según mime.
  - `GastoFormModal` recibe nueva prop `aiTranscription` y la muestra en el banner IA como texto en cursiva: `"Transcripción de tu nota de voz: ..."` — informativo, no editable.
- Costo aproximado por draft con voz: ~$0.006/min (Whisper) + costo de GPT-4o vision (≤ $0.05 por ticket típico). Total < $0.10 para un caso típico de 30s voz + 1 foto.

**Riesgos mitigados en F2:**

| Riesgo | Mitigación |
|---|---|
| MediaRecorder no soportado en el navegador | `recorder.isSupported` oculta la sección si la API no existe; el resto del modal sigue funcionando con sólo texto + imagen. |
| Usuario olvida detener la grabación | Auto-stop a `maxSeconds` (default 90s). Timer visible. |
| Audio sin permiso de micrófono | `recorder.error.value = 'Permiso de micrófono denegado.'` se muestra inline. |
| Whisper falla pero ya guardamos el audio | Draft pasa a `failed`, endpoint devuelve 502, archivos quedan en disco para debug (job F4 los limpia). |
| Transcripción contiene inyección de prompt | Se envuelve con `[Nota de voz transcrita]` antes del texto del usuario; queda dentro del bloque `<<< ... >>>` del prompt. |
| Audio con bytes binarios crudos | Validación `mimes` (por extensión) + límite de 10 MB. Whisper como filtro final. |

**Fase 3 — Crear categoría con IA (admin-empresa):**

Asistente que permite al admin-empresa describir en texto o voz qué tipo de gastos quiere agrupar y obtener una propuesta editable de categoría + subcategorías + aliases + qué incluye / qué no incluye. La IA prioriza reutilizar una categoría existente cuando detecta solapamiento.

- Migraciones:
  - `2026_05_18_000001_create_ai_category_drafts_table.php` — drafts del flujo, espejo de `ai_expense_drafts` con `expense_category_id` en lugar de `expense_id`. Comparte el enum `AiDraftStatus`.
  - `2026_05_18_000002_add_includes_excludes_to_expense_module.php` — añade `includes` y `excludes` (JSON nullable) a `expense_categories` y `expense_subcategories`. El campo `includes` lista qué entra; `excludes` lo que NO debe entrar. Útil tanto para humanos como para el clasificador de F1 (le aporta señal explícita de exclusión).
- Servicios:
  - `App\Services\Ai\CategoryContextBuilder` — arma el contexto con TODAS las categorías activas del tenant (nombre + descripción + aliases + includes + excludes + subcategorías) y reglas que priorizan reutilizar antes que duplicar.
  - `App\Services\Ai\AiCategoryProposalParser` — valida la respuesta de la IA y discrimina entre tres acciones: `crear_categoria`, `usar_existente`, `necesita_aclaracion`. Si la IA propone reutilizar un id que no existe en el tenant, degrada silenciosamente a `crear_categoria` con una alerta.
  - `App\Services\Ai\AiCategoryDraftService` — orquesta Whisper (opcional) + GPT-4o + persistencia, mismo patrón que `AiExpenseDraftService`.
- Endpoints:
  - `POST /{tenant}/empresa/gastos/categorias/ia/borrador` (`App\Http\Controllers\Ai\CategoryDraftController@store`) — recibe texto y/o audio, llama a la IA, devuelve `{ draft_id, status, proposal, audio_transcription }`.
  - `POST /{tenant}/empresa/gastos/categorias/ia/aplicar` (`Empresa\ExpenseCategoryController@storeFromAiDraft`) — bulk transaccional. Acepta `mode: create_new | use_existing`:
    - `create_new`: crea categoría + N subcategorías propuestas en una transacción.
    - `use_existing`: actualiza la categoría existente (mergea `aliases`/`includes`/`excludes` case-insensitive, opcionalmente reemplaza `description`) y crea las nuevas subcategorías dentro. Valida que ninguna subcategoría propuesta colisione con las existentes del destino.
  - Ambos endpoints exclusivos para `admin-empresa|superadmin` (middleware ya existente del grupo `empresa`).
- Frontend:
  - `composables/useCategoryAiDraft.js` — `submitDraft({ tenantSlug, text, audio })` + `applyDraft({ tenantSlug, payload })` con axios (los endpoints devuelven JSON puro).
  - `Components/Gastos/CategoryAICaptureModal.vue` — modal con textarea (ejemplo prominente en placeholder + bullets de guía) + grabación de voz reutilizando `useAudioRecorder`. Loading state mientras la IA analiza.
  - `Components/Gastos/CategoryAIReviewModal.vue` — modal de revisión con tres vistas:
    - **Necesita aclaración**: muestra las preguntas faltantes, el usuario vuelve a capturar.
    - **Usar existente**: tarjeta azul con la categoría real + mejoras editables (descripción mejorada, aliases/includes/excludes a sumar) + lista de subcategorías nuevas a agregar. Botón secundario "No, mejor crear una nueva" hace switch a `create_new` con los datos pre-rellenados.
    - **Crear nueva**: form completo editable (nombre, descripción, aliases comma-separated, includes/excludes, N subcategorías con add/remove).
  - Banner tricolor de confianza (verde/ámbar/rojo) y caja con la transcripción de voz si aplicó.
  - Entry point: botón grande "Crear categoría con IA" arriba del input manual en la tab "Categorías" de `Empresa/Gastos/Index.vue`. Tras guardar, `router.reload({ only: ['categories'] })` refresca la tab sin perder filtros.

**Riesgos mitigados en F3:**

| Riesgo | Mitigación |
|---|---|
| IA propone reutilizar id inexistente | Parser valida contra el tenant y degrada a `crear_categoria` con alerta visible. Test cubierto. |
| IA propone subcategoría que ya existe en la categoría destino | Validación case-insensitive en `storeFromAiDraft` con rollback y 422. Test cubierto. |
| Duplicados case-insensitive dentro del mismo payload | `hasDuplicateSubcategoryNames()` con normalización lowercase, 422 sin tocar BD. |
| Categoría con nombre duplicado en el tenant | `Rule::unique('expense_categories', 'name')->where(tenant_id)` sigue activa en el endpoint bulk. |
| Cross-tenant: `ai_draft_id` de otro tenant | `resolveAiDraft` filtra por `tenant_id` + `status=ready`; si no matchea, ignora silenciosamente y deja crear la categoría sin consumir draft. Test cubierto. |
| Mejoras pisan datos del usuario | `mergeStringList()` dedupe case-insensitive preservando el orden original; nunca borra elementos existentes. La descripción solo se reemplaza si el usuario la confirma (input no vacío en el form). |
| Inyección de prompt en mensaje | System prompt separado; texto del usuario va envuelto en `<<<>>>` dentro del bloque user. |
| Datos sensibles a OpenAI | `CategoryContextBuilder` solo envía nombre, descripción, aliases, includes/excludes y nombres de subcategorías — nada de gastos previos, montos, RFC o datos fiscales. |
| Catálogo grande (>50 categorías) | El contexto cabe holgadamente en 10–20 KB. Si crece más, F4 podrá compactarlo a `id + nombre + primer alias`. |
| admin-sucursal accede al endpoint | Mismo gating de roles que el resto de `/empresa/...`. Tests cubren 403. |

**Fases siguientes:** F3.3 conversacional pleno (reenvío automático con `parent_draft_id`), F3.4 sugerencias de admin-sucursal con buzón de aprobación, F4 prompt caching + budget por tenant + job limpieza 24h, F5 mejoras avanzadas.

Ver [docs/superpowers/specs/](../superpowers/specs/) para la spec detallada del flujo IA.
