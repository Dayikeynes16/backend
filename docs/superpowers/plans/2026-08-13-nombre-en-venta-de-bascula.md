# Nombre en la venta desde la báscula — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el operador pueda poner un nombre a la venta desde la báscula —dictándolo o escribiéndolo— y que ese nombre se vea junto al folio en la cola de la Mesa de Trabajo.

**Architecture:** El nombre viaja en `sales.contact_name`, que ya existe, añadido como campo `nullable` a la API de básculas. El dictado usa un endpoint nuevo de la Scale API que reusa `AssistantTranscriber` (Whisper). La báscula manda el audio **siempre a la nube**, aunque venda contra el hub, porque ya guarda esas credenciales para el fallback — así el hub queda intacto.

**Tech Stack:** Laravel 13 · PHP 8.5 · PostgreSQL 18 · Vue 3 + Inertia 2 · PHPUnit 12 · Sail | Kotlin · Jetpack Compose · Retrofit · JUnit

Spec: [2026-08-13-nombre-en-venta-de-bascula-design.md](../specs/2026-08-13-nombre-en-venta-de-bascula-design.md)

## Global Constraints

- **La API de básculas no puede romperse.** `contact_name` entra como `nullable`; una venta sin el campo debe crearse exactamente como hoy. Hay un test dedicado a esto (Tarea 1) y no puede eliminarse.
- **`carniceria-hub` no se toca.** Ni una línea, ni una release. Reenvía el payload completo (`backendClient.js:20`) y solo valida que haya `items`.
- **No se toca la cartera de clientes.** `customer_id` sigue en `null`; nada escribe en `customers`. El nombre es una etiqueta.
- **El audio va a `config.cloud`**, nunca al servidor activo de venta.
- Backend por Sail: `./vendor/bin/sail artisan ...`, `./vendor/bin/sail bin pint --dirty --format agent`. El build de assets corre en el host: `npm run build`.
- Android necesita **Java 17** (el JDK por defecto del equipo es el 26 y Gradle falla con un error críptico): `JAVA_HOME=$(/usr/libexec/java_home -v 17) ./gradlew ...`
- Idioma: UI y docs en español; identificadores en inglés.

## Dos obstáculos que descubrí y que este plan resuelve

**1. El interceptor de Retrofit fuerza `Content-Type: application/json`** (`Api.kt:49`). Si el endpoint de transcripción usara ese mismo cliente, el multipart saldría con la cabecera equivocada y el servidor lo rechazaría. La Tarea 5 crea un cliente separado en vez de modificar el existente: el camino de las ventas no se toca.

**2. No existe el permiso `RECORD_AUDIO`** en el manifest (solo `INTERNET`, `CAMERA`, `REQUEST_INSTALL_PACKAGES`). La Tarea 5 lo añade y pide el permiso en runtime, siguiendo el patrón que ya usa `QrScannerScreen.kt:57` para la cámara.

## Orden de despliegue

Las fases no son intercambiables:

1. **Fase A (backend, Tareas 1-3)** puede desplegarse sola. No cambia nada visible salvo que la cola muestra el nombre cuando existe — y todavía no existe ninguno.
2. **Fase B (báscula, Tareas 4-5)** requiere que la Fase A **esté desplegada en producción**: la báscula llamará a `POST /api/v1/transcribe`, que hasta entonces devuelve 404.

## Estructura de archivos

**Crear**
| Archivo | Responsabilidad |
|---|---|
| `app/Http/Controllers/Api/TranscriptionController.php` | Endpoint de transcripción para la Scale API |
| `tests/Feature/Api/ScaleTranscriptionTest.php` | Tests del endpoint |
| `tests/Feature/Api/SaleContactNameTest.php` | `contact_name` en la API de ventas |
| `bascula-android/…/data/TranscriptionClient.kt` | Cliente multipart aparte, para no tocar el de ventas |
| `bascula-android/…/ui/pos/components/ContactNameField.kt` | Campo con botón de micrófono |
| `bascula-android/…/audio/VoiceRecorder.kt` | Grabación a m4a |

**Modificar**
| Archivo | Cambio |
|---|---|
| `app/Http/Controllers/Api/SaleController.php` | `contact_name` en validación y `create` |
| `routes/api.php:37-43` | Ruta del endpoint |
| `config/ai.php` | Límite de dictados por hora |
| `resources/js/Pages/Caja/Workbench.vue:141` | Nombre en la tarjeta |
| `resources/js/Pages/Sucursal/Workbench.vue` | Ídem |
| `resources/js/Components/Caja/SaleDetail.vue` | Línea en el detalle |
| `bascula-android/…/data/Models.kt:54-61` | `contactName` en `CreateSaleRequest` |
| `bascula-android/…/ui/pos/PosViewModel.kt:18-60,469-481` | Estado y envío |
| `bascula-android/…/ui/pos/PosScreen.kt` | Montar el campo |
| `bascula-android/app/src/main/AndroidManifest.xml` | `RECORD_AUDIO` |

---

# Fase A — Backend

### Task 1: `contact_name` en la API de básculas

**Files:**
- Modify: `app/Http/Controllers/Api/SaleController.php:20-28` (validación) y el `Sale::create` dentro de la transacción
- Test: `tests/Feature/Api/SaleContactNameTest.php` (crear)

**Interfaces:**
- Produces: la Scale API acepta `contact_name` (string, ≤255, opcional) en `POST /api/v1/sales` y lo persiste en `sales.contact_name`.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Api/SaleContactNameTest.php`. Usa el mismo montaje que `tests/Feature/Api/SaleIdempotencyTest.php` (léelo antes: crea tenant, branch, category, product y una `ApiKey` con `key_hash` = sha256 de la clave en claro).

```php
<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `contact_name` es el nombre que la báscula pone a la venta. Es opcional a
 * propósito: las básculas viejas no lo mandan y deben seguir funcionando
 * exactamente igual.
 */
class SaleContactNameTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    private Product $product;

    private string $rawKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Test', 'slug' => 'test-tenant', 'status' => 'active']);
        $this->branch = Branch::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Sucursal 1',
            'address' => 'A',
            'status' => 'active',
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Res',
            'status' => 'active',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'name' => 'Costilla',
            'price' => 170,
            'unit_type' => 'kg',
            'sale_mode' => 'weight',
            'status' => 'active',
        ]);

        $this->rawKey = 'csa_test_'.str_repeat('x', 20);
        ApiKey::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'bascula de prueba',
            'key_hash' => hash('sha256', $this->rawKey),
            'last_used_at' => null,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function postSale(array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/sales', array_merge([
            'payment_method' => 'cash',
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ], $extra), ['X-Api-Key' => $this->rawKey]);
    }

    public function test_guarda_el_nombre_cuando_viene(): void
    {
        $this->postSale(['contact_name' => 'Doña Mary'])->assertCreated();

        $this->assertSame('Doña Mary', Sale::withoutGlobalScopes()->latest('id')->first()->contact_name);
    }

    /**
     * La garantía para las básculas viejas: no mandan el campo y todo sigue igual.
     * Este test no puede eliminarse.
     */
    public function test_una_venta_sin_el_campo_se_crea_igual_que_antes(): void
    {
        $this->postSale()->assertCreated();

        $sale = Sale::withoutGlobalScopes()->latest('id')->first();

        $this->assertNull($sale->contact_name);
        $this->assertSame('340.00', $sale->total);
    }

    public function test_ignora_un_nombre_vacio(): void
    {
        $this->postSale(['contact_name' => '   '])->assertCreated();

        $this->assertNull(Sale::withoutGlobalScopes()->latest('id')->first()->contact_name);
    }

    public function test_rechaza_un_nombre_demasiado_largo(): void
    {
        $this->postSale(['contact_name' => str_repeat('a', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact_name');
    }

    public function test_no_crea_ni_toca_clientes(): void
    {
        $this->postSale(['contact_name' => 'Doña Mary'])->assertCreated();

        $this->assertSame(0, \App\Models\Customer::withoutGlobalScopes()->count());
        $this->assertNull(Sale::withoutGlobalScopes()->latest('id')->first()->customer_id);
    }
}
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Api/SaleContactNameTest.php`
Expected: fallan `test_guarda_el_nombre_cuando_viene`, `test_ignora_un_nombre_vacio` y `test_rechaza_un_nombre_demasiado_largo`. Los otros dos pasan ya (es el comportamiento actual, y ahí está la gracia: son la red de seguridad).

- [ ] **Step 3: Añadir el campo a la validación**

En `app/Http/Controllers/Api/SaleController.php`, dentro de `$request->validate([...])` del método `store`, añadir tras `'origin_name'`:

```php
            'contact_name' => 'nullable|string|max:255',
```

- [ ] **Step 4: Persistirlo**

Localizar el `Sale::create([...])` dentro de la transacción (`grep -n "Sale::create" app/Http/Controllers/Api/SaleController.php`) y añadir al array:

```php
                // Nombre libre puesto desde la báscula para identificar la venta
                // en la cola. NO es un cliente: `customer_id` sigue en null.
                'contact_name' => trim((string) $request->input('contact_name')) ?: null,
```

El `trim(...) ?: null` es lo que hace pasar `test_ignora_un_nombre_vacio`: un campo que el operador tocó y dejó en blanco no debe guardarse como cadena vacía.

- [ ] **Step 5: Correr los tests**

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Api/SaleContactNameTest.php tests/Feature/Api/SaleIdempotencyTest.php`
Expected: PASS todos.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Api/SaleController.php tests/Feature/Api/SaleContactNameTest.php
git commit -m "feat(api-basculas): acepta contact_name opcional en la creacion de ventas"
```

---

### Task 2: Endpoint de transcripción para la Scale API

**Files:**
- Create: `app/Http/Controllers/Api/TranscriptionController.php`
- Modify: `routes/api.php:37-43`, `config/ai.php`
- Test: `tests/Feature/Api/ScaleTranscriptionTest.php` (crear)

**Interfaces:**
- Consumes: `App\Services\Ai\Assistant\AssistantTranscriber::transcribe(UploadedFile): string` (existe, no se toca).
- Produces: `POST /api/v1/transcribe` (nombre de ruta `api.transcribe`), multipart con campo `audio`, responde `{ "text": "..." }`.

- [ ] **Step 1: Ver dónde encaja la clave de configuración**

Run: `grep -n "expenses\|assistant" config/ai.php | head -20`

Localiza la sección de `expenses` (de ahí sale `max_audio_bytes`) y la de `assistant`. Añadirás una sección `scale` al mismo nivel.

- [ ] **Step 2: Escribir el test que falla**

Crear `tests/Feature/Api/ScaleTranscriptionTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\Branch;
use App\Models\Tenant;
use App\Services\Ai\Assistant\AssistantTranscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Tests\TestCase;

/**
 * Dictado de la báscula: audio → texto. La báscula se autentica con X-Api-Key,
 * así que no puede usar el endpoint del asistente (sesión web).
 */
class ScaleTranscriptionTest extends TestCase
{
    use RefreshDatabase;

    private string $rawKey;

    private ApiKey $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test-tenant', 'status' => 'active']);
        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Sucursal 1',
            'address' => 'A',
            'status' => 'active',
        ]);

        $this->rawKey = 'csa_test_'.str_repeat('x', 20);
        $this->apiKey = ApiKey::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'bascula de prueba',
            'key_hash' => hash('sha256', $this->rawKey),
            'last_used_at' => null,
        ]);
    }

    private function fakeAudio(): UploadedFile
    {
        return UploadedFile::fake()->create('nota.m4a', 40, 'audio/mp4');
    }

    private function mockTranscriber(string $returns = 'Doña Mary'): void
    {
        $mock = Mockery::mock(AssistantTranscriber::class);
        $mock->shouldReceive('transcribe')->andReturn($returns);
        $this->app->instance(AssistantTranscriber::class, $mock);
    }

    public function test_devuelve_el_texto_transcrito(): void
    {
        $this->mockTranscriber('Doña Mary');

        $this->post('/api/v1/transcribe', ['audio' => $this->fakeAudio()], ['X-Api-Key' => $this->rawKey])
            ->assertOk()
            ->assertJsonPath('text', 'Doña Mary');
    }

    public function test_recorta_los_espacios_del_texto(): void
    {
        $this->mockTranscriber("  Doña Mary \n");

        $this->post('/api/v1/transcribe', ['audio' => $this->fakeAudio()], ['X-Api-Key' => $this->rawKey])
            ->assertOk()
            ->assertJsonPath('text', 'Doña Mary');
    }

    public function test_sin_api_key_es_rechazado(): void
    {
        $this->post('/api/v1/transcribe', ['audio' => $this->fakeAudio()])
            ->assertUnauthorized();
    }

    public function test_exige_el_audio(): void
    {
        $this->post('/api/v1/transcribe', [], ['X-Api-Key' => $this->rawKey])
            ->assertStatus(422)
            ->assertJsonValidationErrors('audio');
    }

    public function test_rechaza_un_formato_no_soportado(): void
    {
        $this->post(
            '/api/v1/transcribe',
            ['audio' => UploadedFile::fake()->create('nota.txt', 10, 'text/plain')],
            ['X-Api-Key' => $this->rawKey],
        )->assertStatus(422)->assertJsonValidationErrors('audio');
    }

    public function test_limita_los_dictados_por_hora_y_por_api_key(): void
    {
        config(['ai.scale.transcribe_per_hour' => 2]);
        $this->mockTranscriber();

        $this->post('/api/v1/transcribe', ['audio' => $this->fakeAudio()], ['X-Api-Key' => $this->rawKey])->assertOk();
        $this->post('/api/v1/transcribe', ['audio' => $this->fakeAudio()], ['X-Api-Key' => $this->rawKey])->assertOk();
        $this->post('/api/v1/transcribe', ['audio' => $this->fakeAudio()], ['X-Api-Key' => $this->rawKey])
            ->assertStatus(429);
    }

    /** Dos básculas de la misma sucursal no se comen la cuota entre sí. */
    public function test_el_limite_es_por_api_key_no_global(): void
    {
        config(['ai.scale.transcribe_per_hour' => 1]);
        $this->mockTranscriber();

        $otraKey = 'csa_test_'.str_repeat('y', 20);
        ApiKey::create([
            'tenant_id' => $this->apiKey->tenant_id,
            'branch_id' => $this->apiKey->branch_id,
            'name' => 'segunda bascula',
            'key_hash' => hash('sha256', $otraKey),
            'last_used_at' => null,
        ]);

        $this->post('/api/v1/transcribe', ['audio' => $this->fakeAudio()], ['X-Api-Key' => $this->rawKey])->assertOk();
        $this->post('/api/v1/transcribe', ['audio' => $this->fakeAudio()], ['X-Api-Key' => $this->rawKey])->assertStatus(429);

        // La otra báscula conserva su cuota.
        $this->post('/api/v1/transcribe', ['audio' => $this->fakeAudio()], ['X-Api-Key' => $otraKey])->assertOk();
    }

    public function test_si_whisper_falla_responde_503_y_no_revienta(): void
    {
        $mock = Mockery::mock(AssistantTranscriber::class);
        $mock->shouldReceive('transcribe')->andThrow(new \RuntimeException('openai down'));
        $this->app->instance(AssistantTranscriber::class, $mock);

        $this->post('/api/v1/transcribe', ['audio' => $this->fakeAudio()], ['X-Api-Key' => $this->rawKey])
            ->assertStatus(503)
            ->assertJsonStructure(['message']);
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('scale-transcribe:api-key:'.$this->apiKey->id);
        Mockery::close();
        parent::tearDown();
    }
}
```

**Nota sobre `RefreshDatabase` y el rate limiter:** el limitador vive en caché, no en base de datos, así que no se limpia solo entre tests. En `phpunit.xml` el `CACHE_STORE` es `array`, que sí se reinicia por test — el `tearDown` es cinturón y tirantes. Si algún test falla por cuota agotada, ese es el motivo.

- [ ] **Step 3: Correr el test para verificar que falla**

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Api/ScaleTranscriptionTest.php`
Expected: FAIL — la ruta no existe (404 en vez de 200/422).

- [ ] **Step 4: Añadir la configuración**

En `config/ai.php`, al mismo nivel que `expenses` y `assistant`:

```php
    /*
    | Dictado desde la báscula (Scale API). El límite es por API key: cada
    | báscula tiene la suya, así que dos equipos de la misma sucursal no se
    | consumen la cuota entre sí.
    */
    'scale' => [
        'transcribe_per_hour' => env('AI_SCALE_TRANSCRIBE_PER_HOUR', 120),
    ],
```

- [ ] **Step 5: Escribir el controlador**

Crear `app/Http/Controllers/Api/TranscriptionController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\Assistant\AssistantTranscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Dictado de la báscula: recibe un audio y devuelve el texto.
 *
 * Existe aparte del endpoint del asistente porque aquél exige sesión web y la
 * báscula se autentica con `X-Api-Key`. La transcripción en sí es la misma:
 * `AssistantTranscriber` (Whisper, español), que no persiste el audio.
 *
 * El límite es **por API key**, no por sucursal: cada báscula tiene la suya, y
 * un equipo con un botón atascado no debe dejar sin dictado al de al lado.
 */
class TranscriptionController extends Controller
{
    public function store(Request $request, AssistantTranscriber $transcriber): JsonResponse
    {
        // `api_key_id` lo inyecta AuthenticateApiKey en el request.
        $limiterKey = 'scale-transcribe:api-key:'.$request->input('api_key_id');
        $perHour = (int) config('ai.scale.transcribe_per_hour', 120);

        if (RateLimiter::tooManyAttempts($limiterKey, $perHour)) {
            $retryAfter = RateLimiter::availableIn($limiterKey);

            return response()->json([
                'message' => 'Has excedido el límite de dictados por hora.',
            ], 429)->header('Retry-After', $retryAfter);
        }

        $maxAudioKb = (int) (config('ai.expenses.max_audio_bytes', 10 * 1024 * 1024) / 1024);

        $request->validate([
            'audio' => [
                'required',
                'file',
                // Mismo set tolerante que el resto de flujos de voz: los
                // grabadores producen extensiones inconsistentes y Whisper
                // rechaza por su cuenta lo que no entiende.
                'mimes:webm,ogg,oga,mp3,mpga,m4a,mp4,wav,flac,aac',
                'max:'.$maxAudioKb,
            ],
        ], [
            'audio.required' => 'Falta el audio.',
            'audio.mimes' => 'Formato de audio no permitido.',
            'audio.max' => 'El audio no puede superar '.round($maxAudioKb / 1024).' MB.',
        ]);

        RateLimiter::hit($limiterKey, 3600);

        try {
            $text = $transcriber->transcribe($request->file('audio'));
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No se pudo transcribir el audio.',
            ], 503);
        }

        return response()->json(['text' => trim($text)]);
    }
}
```

- [ ] **Step 6: Registrar la ruta**

En `routes/api.php`, dentro del grupo `Route::prefix('v1')->middleware('auth.apikey')`, tras la línea de `sales/{sale}`:

```php
        Route::post('transcribe', [TranscriptionController::class, 'store'])->name('api.transcribe');
```

Y el import arriba del archivo, junto a los otros controladores de `Api`:

```php
use App\Http\Controllers\Api\TranscriptionController;
```

- [ ] **Step 7: Correr los tests**

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Api/ScaleTranscriptionTest.php`
Expected: PASS (8 tests).

- [ ] **Step 8: Verificar que no se rompió el rate limit general de la Scale API**

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Api/ tests/Feature/ApiKeyLastUsedTest.php`
Expected: PASS. El middleware ya limita a 60 req/min por key; el nuevo limitador es independiente y convive con él.

- [ ] **Step 9: Commit**

```bash
./vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Api/TranscriptionController.php routes/api.php config/ai.php tests/Feature/Api/ScaleTranscriptionTest.php
git commit -m "feat(api-basculas): endpoint de transcripcion con limite por api key"
```

---

### Task 3: El nombre visible en la Mesa de Trabajo

**Files:**
- Modify: `resources/js/Pages/Caja/Workbench.vue:141` (zona del folio)
- Modify: `resources/js/Pages/Sucursal/Workbench.vue` (misma zona; localizar con `grep -n "sale.folio" resources/js/Pages/Sucursal/Workbench.vue`)
- Modify: `resources/js/Components/Caja/SaleDetail.vue` (añadir la línea que Sucursal ya tiene en `SaleDetail.vue:449`)

**Interfaces:**
- Consumes: `sale.contact_name` — la venta ya viaja con el campo a Inertia; no hace falta tocar controladores.

- [ ] **Step 1: Comprobar que el dato llega**

Run: `./vendor/bin/sail artisan tinker --execute="echo json_encode(App\Models\Sale::withoutGlobalScopes()->whereNotNull('contact_phone')->first()?->only(['folio','contact_name','contact_phone']));"`

Expected: un JSON con las tres claves (o `null` si no hay ventas web en la base local). Lo que importa es que `contact_name` es un atributo normal del modelo, no algo que haya que añadir al `select`.

- [ ] **Step 2: Mostrar el nombre en la tarjeta de Caja**

En `resources/js/Pages/Caja/Workbench.vue`, la línea 141 es:

```vue
<span class="text-sm font-bold text-gray-900">{{ sale.folio }}</span>
```

Añadir justo después:

```vue
<span v-if="sale.contact_name"
    class="inline-flex min-w-0 items-center gap-1 rounded-full bg-violet-50 px-2 py-0.5 text-xs font-semibold text-violet-700 ring-1 ring-inset ring-violet-600/20"
    :title="`A nombre de ${sale.contact_name}`">
    <svg class="h-3 w-3 shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
        <path d="M10 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm0 2c-3.04 0-7 1.52-7 4.5V17h14v-1.5c0-2.98-3.96-4.5-7-4.5Z" />
    </svg>
    <span class="max-w-[140px] truncate">{{ sale.contact_name }}</span>
</span>
```

**Por qué violeta y no el azul del cliente:** el badge azul con ese mismo icono ya significa "cliente asignado" en el chip de WhatsApp (`SaleWhatsappPhoneChip.vue:32`). Usar el mismo color para una etiqueta que **no** es un cliente entrenaría a confundirlos.

- [ ] **Step 3: Repetir en Sucursal**

Run: `grep -n "sale.folio" resources/js/Pages/Sucursal/Workbench.vue`

Añadir el mismo bloque tras el `<span>` del folio. Si la estructura difiere, respeta la del archivo: lo que importa es que el nombre quede junto al folio y se trunque.

- [ ] **Step 4: Añadir la línea al detalle de Caja**

En `resources/js/Components/Caja/SaleDetail.vue`, localizar dónde se muestran los datos de origen de la venta (`grep -n "origin_name" resources/js/Components/Caja/SaleDetail.vue`) y añadir en el mismo bloque:

```vue
<p v-if="sale.contact_name" class="text-xs text-gray-500">
    <span class="font-semibold">A nombre de:</span> {{ sale.contact_name }}
</p>
```

- [ ] **Step 5: Compilar**

Run: `npm run build`
Expected: build sin errores.

- [ ] **Step 6: Verlo funcionando**

```bash
./vendor/bin/sail artisan tinker --execute="App\Models\Sale::withoutGlobalScopes()->whereIn('status',['active','pending'])->first()?->forceFill(['contact_name' => 'Doña Mary'])->save();"
```

Abre la Mesa de Trabajo de Caja: esa venta debe mostrar el badge violeta junto al folio, y la línea al abrir el detalle. Comprueba también que las **otras** ventas (sin nombre) se ven exactamente como antes.

- [ ] **Step 7: Commit**

```bash
git add resources/js/
git commit -m "feat(ventas): muestra el nombre de la venta junto al folio en la mesa de trabajo"
```

---

# Fase B — Báscula Android

**Requiere la Fase A desplegada en producción.** Antes de empezar, comprueba que `POST /api/v1/transcribe` responde 422 (y no 404) contra el backend real, con una API key válida y sin adjuntar audio.

### Task 4: El campo de texto y su envío

Sin dictado todavía: primero que el nombre viaje y aparezca en la web. Así la Tarea 5 depura solo el audio.

**Files:**
- Modify: `app/src/main/java/com/bascula/app/data/Models.kt:54-61`
- Modify: `app/src/main/java/com/bascula/app/ui/pos/PosViewModel.kt` (estado ~línea 18-60; envío ~línea 469-481)
- Create: `app/src/main/java/com/bascula/app/ui/pos/components/ContactNameField.kt`
- Modify: `app/src/main/java/com/bascula/app/ui/pos/PosScreen.kt`
- Test: `app/src/test/java/com/bascula/app/data/CreateSaleRequestTest.kt` (crear)

**Interfaces:**
- Produces: `CreateSaleRequest.contactName: String?` serializado como `contact_name`; `PosState.contactName: String`; `PosViewModel.onContactNameChange(String)`.

- [ ] **Step 1: Escribir el test que falla**

Crear `app/src/test/java/com/bascula/app/data/CreateSaleRequestTest.kt`:

```kotlin
package com.bascula.app.data

import com.google.gson.Gson
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Test

/**
 * El nombre viaja como `contact_name`. Cuando no hay nombre, la clave **no**
 * debe aparecer en el JSON: la API lo trata como opcional y no queremos
 * cambiar el payload que mandan las ventas de siempre.
 */
class CreateSaleRequestTest {

    private val gson = Gson()

    private fun request(contactName: String?) = CreateSaleRequest(
        items = listOf(SaleItem(productId = 1, quantity = 2.0, presentationId = null)),
        paymentMethod = "cash",
        originName = "Bascula 2",
        contactName = contactName,
        clientReference = "ref-1"
    )

    @Test
    fun `serializa el nombre como contact_name`() {
        val json = gson.toJson(request("Doña Mary"))

        assertEquals(true, json.contains("\"contact_name\":\"Doña Mary\""))
    }

    @Test
    fun `omite la clave cuando no hay nombre`() {
        val json = gson.toJson(request(null))

        assertFalse(json.contains("contact_name"))
    }
}
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run:
```bash
cd "/Users/sebas/Documents/version 2/bascula-android"
JAVA_HOME=$(/usr/libexec/java_home -v 17) ./gradlew testDebugUnitTest --tests "com.bascula.app.data.CreateSaleRequestTest"
```
Expected: FAIL de compilación — `CreateSaleRequest` no tiene `contactName`.

- [ ] **Step 3: Añadir el campo al modelo**

En `app/src/main/java/com/bascula/app/data/Models.kt`, `CreateSaleRequest` pasa a:

```kotlin
data class CreateSaleRequest(
    val items: List<SaleItem>,
    @SerializedName("payment_method") val paymentMethod: String,
    @SerializedName("origin_name") val originName: String?,
    // Nombre libre para identificar la venta en la cola de la Mesa de Trabajo.
    // No es un cliente: el backend no toca `customers` con esto.
    @SerializedName("contact_name") val contactName: String? = null,
    // Llave de idempotencia generada por la báscula. El hub deduplica por ella;
    // la nube no la valida ni la persiste, así que enviarla siempre es inocuo.
    @SerializedName("client_reference") val clientReference: String
)
```

Gson omite por defecto las propiedades `null`, que es justo lo que pide el segundo test.

- [ ] **Step 4: Correr el test**

Run: `JAVA_HOME=$(/usr/libexec/java_home -v 17) ./gradlew testDebugUnitTest --tests "com.bascula.app.data.CreateSaleRequestTest"`
Expected: PASS (2 tests).

- [ ] **Step 5: Añadir el estado al ViewModel**

En `PosViewModel.kt`, dentro de `data class PosState`, junto a los campos de la venta en curso (tras `val lines: List<SaleLine>`):

```kotlin
    /** Nombre libre de la venta en curso. Se limpia al cobrar, como las líneas. */
    val contactName: String = "",
```

Y como método público de `PosViewModel` (junto a las demás acciones de la venta):

```kotlin
    fun onContactNameChange(value: String) {
        // 255 es el límite de la columna en el backend; recortar aquí evita un
        // 422 después de que el operador ya cobró.
        _state.update { it.copy(contactName = value.take(255)) }
    }
```

- [ ] **Step 6: Enviarlo con la venta**

En el `CreateSaleRequest(...)` de `PosViewModel.kt` (~línea 470), añadir:

```kotlin
            contactName = s.contactName.trim().ifBlank { null },
```

Y donde se limpia la venta tras cobrar (busca dónde se resetea `lines = emptyList()`), añadir `contactName = ""` al mismo `copy`. Si no lo haces, el nombre de la venta anterior se queda pegado a la siguiente — el error más probable de esta tarea.

- [ ] **Step 7: Crear el campo de UI**

Crear `app/src/main/java/com/bascula/app/ui/pos/components/ContactNameField.kt`:

```kotlin
package com.bascula.app.ui.pos.components

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.ImeAction

/**
 * Campo "A nombre de" de la venta en curso.
 *
 * El micrófono se añade en la tarea siguiente; de momento es un campo de texto
 * normal, que es también el modo en que funciona cuando no hay nube.
 */
@Composable
fun ContactNameField(
    value: String,
    onValueChange: (String) -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true
) {
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text("A nombre de (opcional)") },
        placeholder = { Text("Doña Mary") },
        singleLine = true,
        enabled = enabled,
        modifier = modifier.fillMaxWidth()
    )
}
```

- [ ] **Step 8: Montarlo en la pantalla**

En `PosScreen.kt`, localiza el bloque de la venta en curso, cerca del total y el botón de cobrar (`grep -n "total\|Cobrar" app/src/main/java/com/bascula/app/ui/pos/PosScreen.kt`). Añade encima del botón de cobrar:

```kotlin
                ContactNameField(
                    value = state.contactName,
                    onValueChange = viewModel::onContactNameChange,
                    enabled = !state.submitting
                )
```

con su import: `import com.bascula.app.ui.pos.components.ContactNameField`.

- [ ] **Step 9: Compilar y probar en el dispositivo**

Run: `JAVA_HOME=$(/usr/libexec/java_home -v 17) ./gradlew assembleDebug`
Expected: BUILD SUCCESSFUL.

Instala y comprueba: arma una venta, escribe "Doña Mary", cobra. En la Mesa de Trabajo web esa venta debe aparecer con el badge violeta. Después arma **otra** venta sin escribir nada: el campo debe estar vacío y la venta llegar sin nombre.

- [ ] **Step 10: Commit**

```bash
git add app/src/main/java/com/bascula/app/data/Models.kt \
        app/src/main/java/com/bascula/app/ui/pos/ \
        app/src/test/java/com/bascula/app/data/CreateSaleRequestTest.kt
git commit -m "feat(pos): campo 'a nombre de' en la venta"
```

---

### Task 5: Dictado por voz

**Files:**
- Modify: `app/src/main/AndroidManifest.xml:4-8`
- Create: `app/src/main/java/com/bascula/app/audio/VoiceRecorder.kt`
- Create: `app/src/main/java/com/bascula/app/data/TranscriptionClient.kt`
- Modify: `app/src/main/java/com/bascula/app/ui/pos/components/ContactNameField.kt`
- Modify: `app/src/main/java/com/bascula/app/ui/pos/PosViewModel.kt`

**Interfaces:**
- Consumes: `POST /api/v1/transcribe` de la Tarea 2 → `{ "text": "..." }`.
- Produces: `TranscriptionClient.transcribe(baseUrl, apiKey, file): Result<String>`; `PosState.dictation: DictationState`; `PosViewModel.startDictation()` / `stopDictation()`.

- [ ] **Step 1: Añadir el permiso**

En `app/src/main/AndroidManifest.xml`, junto a los otros `uses-permission`:

```xml
    <uses-permission android:name="android.permission.RECORD_AUDIO" />
```

- [ ] **Step 2: Escribir el grabador**

Crear `app/src/main/java/com/bascula/app/audio/VoiceRecorder.kt`:

```kotlin
package com.bascula.app.audio

import android.content.Context
import android.media.MediaRecorder
import android.os.Build
import java.io.File

/**
 * Grabación de una nota de voz corta a m4a (AAC en contenedor MPEG-4).
 *
 * m4a porque es lo que Android graba de forma nativa y está en la lista que
 * acepta el backend. El archivo va a la caché: es de un solo uso y se borra en
 * cuanto se transcribe.
 */
class VoiceRecorder(private val context: Context) {

    private var recorder: MediaRecorder? = null
    private var output: File? = null

    fun start(): Boolean = try {
        val file = File.createTempFile("dictado_", ".m4a", context.cacheDir)
        val rec = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            MediaRecorder(context)
        } else {
            @Suppress("DEPRECATION")
            MediaRecorder()
        }

        rec.setAudioSource(MediaRecorder.AudioSource.MIC)
        rec.setOutputFormat(MediaRecorder.OutputFormat.MPEG_4)
        rec.setAudioEncoder(MediaRecorder.AudioEncoder.AAC)
        rec.setAudioSamplingRate(16_000)   // suficiente para voz; menos bytes que subir
        rec.setOutputFile(file.absolutePath)
        rec.prepare()
        rec.start()

        recorder = rec
        output = file
        true
    } catch (e: Exception) {
        release()
        false
    }

    /** Devuelve el archivo grabado, o null si la grabación fue inválida. */
    fun stop(): File? {
        return try {
            recorder?.stop()
            output
        } catch (e: Exception) {
            // stop() lanza si se grabó menos de ~1 segundo: no hay audio útil.
            output?.delete()
            null
        } finally {
            release()
        }
    }

    fun cancel() {
        try {
            recorder?.stop()
        } catch (e: Exception) {
            // Ignorado: estamos cancelando.
        }
        output?.delete()
        release()
    }

    private fun release() {
        recorder?.release()
        recorder = null
        output = null
    }
}
```

- [ ] **Step 3: Escribir el cliente de transcripción**

Crear `app/src/main/java/com/bascula/app/data/TranscriptionClient.kt`:

```kotlin
package com.bascula.app.data

import com.google.gson.Gson
import com.google.gson.annotations.SerializedName
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.asRequestBody
import java.io.File
import java.util.concurrent.TimeUnit

data class TranscriptionResponse(@SerializedName("text") val text: String)

/**
 * Sube un audio y devuelve el texto.
 *
 * Cliente propio, separado de [ApiClient], por un motivo concreto: el
 * interceptor de aquél fuerza `Content-Type: application/json` (Api.kt), lo que
 * rompería el multipart. Antes que modificar el cliente por el que pasan todas
 * las ventas, este flujo trae el suyo.
 *
 * Va **siempre contra la nube**, aunque la venta viaje al hub: el hub no expone
 * este endpoint, y la báscula guarda las credenciales de nube igualmente para
 * el respaldo.
 */
object TranscriptionClient {

    private val client = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        // El audio sube y Whisper tarda: más margen que en el resto de llamadas.
        .readTimeout(45, TimeUnit.SECONDS)
        .build()

    private val gson = Gson()

    suspend fun transcribe(baseUrl: String, apiKey: String, audio: File): Result<String> =
        withContext(Dispatchers.IO) {
            try {
                val body = MultipartBody.Builder()
                    .setType(MultipartBody.FORM)
                    .addFormDataPart(
                        "audio",
                        audio.name,
                        audio.asRequestBody("audio/mp4".toMediaType())
                    )
                    .build()

                val request = Request.Builder()
                    .url(baseUrl.trimEnd('/') + "/api/v1/transcribe")
                    .addHeader("X-Api-Key", apiKey)
                    .addHeader("Accept", "application/json")
                    .post(body)
                    .build()

                client.newCall(request).execute().use { response ->
                    val raw = response.body?.string().orEmpty()

                    if (!response.isSuccessful) {
                        return@withContext Result.failure(
                            IllegalStateException(errorMessageFor(response.code))
                        )
                    }

                    val parsed = gson.fromJson(raw, TranscriptionResponse::class.java)
                    Result.success(parsed.text)
                }
            } catch (e: Exception) {
                Result.failure(e)
            }
        }

    private fun errorMessageFor(code: Int): String = when (code) {
        401 -> "La báscula no está autorizada para dictar."
        429 -> "Demasiados dictados. Escribe el nombre."
        503 -> "El dictado no está disponible ahora."
        else -> "No se pudo transcribir (error $code)."
    }
}
```

- [ ] **Step 4: Estado del dictado en el ViewModel**

En `PosViewModel.kt`, junto a las demás clases de estado del archivo:

```kotlin
/** En qué punto está el dictado del nombre. */
enum class DictationStatus { IDLE, RECORDING, TRANSCRIBING }

data class DictationState(
    val status: DictationStatus = DictationStatus.IDLE,
    val error: String = ""
) {
    val busy: Boolean get() = status != DictationStatus.IDLE
}
```

Y en `PosState`, junto a `contactName`:

```kotlin
    val dictation: DictationState = DictationState(),
```

- [ ] **Step 5: Las acciones de dictado**

En `PosViewModel`, junto a `onContactNameChange`:

```kotlin
    private val recorder by lazy { VoiceRecorder(getApplication()) }

    /**
     * El dictado va contra la nube aunque estemos vendiendo contra el hub: el
     * hub no expone este endpoint. Si la báscula no tiene credenciales de nube
     * configuradas, no hay dictado — el campo se escribe a mano.
     */
    val canDictate: Boolean get() = _state.value.config.cloud.isSet

    fun startDictation() {
        if (!canDictate || _state.value.dictation.busy) return

        if (recorder.start()) {
            _state.update { it.copy(dictation = DictationState(DictationStatus.RECORDING)) }
        } else {
            _state.update {
                it.copy(dictation = DictationState(DictationStatus.IDLE, "No se pudo usar el micrófono."))
            }
        }
    }

    fun stopDictation() {
        if (_state.value.dictation.status != DictationStatus.RECORDING) return

        val file = recorder.stop()
        if (file == null) {
            _state.update {
                it.copy(dictation = DictationState(DictationStatus.IDLE, "Grabación demasiado corta."))
            }
            return
        }

        _state.update { it.copy(dictation = DictationState(DictationStatus.TRANSCRIBING)) }

        val cloud = _state.value.config.cloud

        viewModelScope.launch {
            val result = TranscriptionClient.transcribe(cloud.baseUrl, cloud.apiKey, file)
            file.delete()

            result.fold(
                onSuccess = { text ->
                    _state.update {
                        it.copy(
                            // El texto reemplaza lo escrito: el operador dictó a
                            // propósito. Queda editable.
                            contactName = text.take(255),
                            dictation = DictationState(DictationStatus.IDLE)
                        )
                    }
                },
                onFailure = { e ->
                    _state.update {
                        it.copy(
                            dictation = DictationState(
                                DictationStatus.IDLE,
                                e.message ?: "No se pudo transcribir."
                            )
                        )
                    }
                }
            )
        }
    }
```

`getApplication()` funciona sin más: `PosViewModel(application: Application) : AndroidViewModel(application)` (línea 145). No hace falta cambiar la firma del ViewModel.

- [ ] **Step 6: El botón de micrófono**

Sustituir el contenido de `ContactNameField.kt` por:

```kotlin
package com.bascula.app.ui.pos.components

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Mic
import androidx.compose.material.icons.filled.Stop
import androidx.compose.foundation.layout.size
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.bascula.app.ui.pos.DictationStatus

/**
 * Campo "A nombre de" con dictado.
 *
 * El campo se escribe siempre; el micrófono es un atajo que necesita nube. Si
 * no la hay, el botón no aparece y el operador teclea — la venta nunca se
 * bloquea por el nombre.
 */
@Composable
fun ContactNameField(
    value: String,
    onValueChange: (String) -> Unit,
    dictationStatus: DictationStatus,
    dictationError: String,
    canDictate: Boolean,
    onStartDictation: () -> Unit,
    onStopDictation: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true
) {
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text("A nombre de (opcional)") },
        placeholder = { Text("Doña Mary") },
        singleLine = true,
        enabled = enabled && dictationStatus != DictationStatus.TRANSCRIBING,
        isError = dictationError.isNotEmpty(),
        supportingText = if (dictationError.isNotEmpty()) {
            { Text(dictationError) }
        } else {
            null
        },
        trailingIcon = if (!canDictate) {
            null
        } else {
            {
                when (dictationStatus) {
                    DictationStatus.TRANSCRIBING ->
                        CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.dp)

                    DictationStatus.RECORDING ->
                        IconButton(onClick = onStopDictation) {
                            Icon(Icons.Default.Stop, contentDescription = "Detener y transcribir")
                        }

                    DictationStatus.IDLE ->
                        IconButton(onClick = onStartDictation, enabled = enabled) {
                            Icon(Icons.Default.Mic, contentDescription = "Dictar el nombre")
                        }
                }
            }
        },
        modifier = modifier.fillMaxWidth()
    )
}
```

`material-icons-extended` ya está en `app/build.gradle.kts:113`, así que `Mic` y `Stop` compilan sin añadir dependencias. Se usa `Icons.Default` porque es la convención del resto de pantallas (`ConnectionScreen.kt:154`).

- [ ] **Step 7: Pedir el permiso y cablearlo**

En `PosScreen.kt`, sustituir la llamada de la Tarea 4 por:

```kotlin
                val context = LocalContext.current
                var micGranted by remember {
                    mutableStateOf(
                        ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO)
                            == PackageManager.PERMISSION_GRANTED
                    )
                }
                val micPermissionLauncher = rememberLauncherForActivityResult(
                    ActivityResultContracts.RequestPermission()
                ) { granted ->
                    micGranted = granted
                    if (granted) viewModel.startDictation()
                }

                ContactNameField(
                    value = state.contactName,
                    onValueChange = viewModel::onContactNameChange,
                    dictationStatus = state.dictation.status,
                    dictationError = state.dictation.error,
                    canDictate = viewModel.canDictate,
                    onStartDictation = {
                        if (micGranted) {
                            viewModel.startDictation()
                        } else {
                            micPermissionLauncher.launch(Manifest.permission.RECORD_AUDIO)
                        }
                    },
                    onStopDictation = viewModel::stopDictation,
                    enabled = !state.submitting
                )
```

Imports (el patrón es el mismo de `QrScannerScreen.kt:57`):

```kotlin
import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalContext
import androidx.core.content.ContextCompat
```

- [ ] **Step 8: Compilar y correr los tests**

Run:
```bash
JAVA_HOME=$(/usr/libexec/java_home -v 17) ./gradlew assembleDebug testDebugUnitTest
```
Expected: BUILD SUCCESSFUL y los tests existentes en verde.

- [ ] **Step 9: Probar en el dispositivo**

Instala el APK en la tablet y recorre:

1. Toca el micrófono la primera vez → debe pedir permiso; al conceder, empieza a grabar.
2. Di un nombre y toca detener → aparece el spinner y luego el texto en el campo.
3. Corrige el texto a mano → se edita sin problema.
4. Cobra → el nombre aparece en la Mesa de Trabajo web.
5. Arma otra venta → el campo debe estar vacío.
6. **Apaga el WiFi y cobra una venta escribiendo el nombre a mano** → debe subir igual por el hub (si lo hay) o quedar en cola; el micrófono puede fallar, el campo no.
7. Toca el micrófono y detén de inmediato → debe decir "Grabación demasiado corta", no romperse.

- [ ] **Step 10: Commit**

```bash
git add app/src/main/AndroidManifest.xml \
        app/src/main/java/com/bascula/app/audio/ \
        app/src/main/java/com/bascula/app/data/TranscriptionClient.kt \
        app/src/main/java/com/bascula/app/ui/pos/
git commit -m "feat(pos): dictado por voz del nombre de la venta"
```

---

### Task 6: Documentación

**Files:**
- Modify: `docs/modulos/ventas.md`
- Modify: `docs/api/endpoints.md`
- Modify: `docs/superpowers/specs/2026-08-13-nombre-en-venta-de-bascula-design.md` (cabecera `Estado:`)

- [ ] **Step 1: Documentar el endpoint**

En `docs/api/endpoints.md`, en la sección de la Scale API, añadir `POST /api/v1/transcribe`: multipart con `audio`, responde `{text}`, límite de 120 dictados/hora por API key, y que `POST /api/v1/sales` acepta `contact_name` opcional.

- [ ] **Step 2: Documentar el comportamiento**

En `docs/modulos/ventas.md`, junto a la sección "Teléfono y cliente" que ya existe, añadir un párrafo sobre el nombre de la venta: qué es, que **no** es un cliente, de dónde viene y dónde se ve. Enlaza al spec.

- [ ] **Step 3: Cerrar el spec**

Cambiar la cabecera del spec a `**Estado:** Implementado (fecha)` con enlace a `docs/modulos/ventas.md`.

- [ ] **Step 4: Commit**

```bash
git add docs/
git commit -m "docs(ventas): nombre de la venta desde la bascula"
```

---

## Riesgos y decisiones abiertas

| Riesgo | Mitigación |
|---|---|
| **El nombre se queda pegado entre ventas** | El fallo más probable de la Tarea 4: si el reset tras cobrar no limpia `contactName`, la venta siguiente hereda el nombre. El paso 9 de esa tarea lo comprueba a propósito |
| El interceptor de Retrofit rompe el multipart | Resuelto con un cliente propio (`TranscriptionClient`). **No modifiques `ApiClient`**: por ahí pasan todas las ventas |
| `PosViewModel` ya tiene 825 líneas | Este cambio le añade ~60. No es el momento de partirlo, pero si crece más, el estado de dictado es el primer candidato a salir a su propio archivo |
| Whisper devuelve la frase entera ("a nombre de doña Mary") | El campo es editable y el operador lo ve antes de cobrar. Si en uso real molesta, limpiar el prefijo con una regla simple antes de plantear GPT, que costaría por dictado |
| La transcripción tarda y el operador ya cobró | El campo se deshabilita mientras transcribe, pero **no** bloquea el botón de cobrar. Si cobra antes de que vuelva el texto, la venta sube sin nombre: es lo correcto — nunca hacer esperar al que está en la fila |
| Grabar en un mostrador ruidoso | Sin resolver por diseño: es un límite de Whisper, no del código. Por eso el texto es editable |

**Pendiente de decidir (fuera de este plan):** si el nombre debe salir en el ticket impreso. Se dejó fuera a propósito; si el uso real resulta ser "apartados que se recogen después", cobra sentido y sería una tarea aparte sobre `TicketPrinter.vue` y la config de ticket por sucursal.
