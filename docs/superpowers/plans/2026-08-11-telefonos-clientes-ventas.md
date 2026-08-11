# Teléfonos y clientes en ventas — Plan de implementación

> **Plan ejecutado y cerrado.** Se conserva como historia del cambio. Para saber cómo funciona el sistema hoy: [docs/modulos/clientes-telefonos.md](../../modulos/clientes-telefonos.md).

**Goal:** Que capturar un teléfono en una venta resuelva siempre a un cliente de la sucursal — reutilizándolo si ya existe o creándolo sin nombre si no — con teléfonos normalizados a E.164 en todo el sistema y sin generar duplicados.

**Architecture:** Un único punto de normalización (`PhoneNormalizer`) aplicado por un mutator en `Customer::phone`, de modo que los cinco canales que crean clientes (CRUD web, hub, asistente IA, pedido web y el nuevo flujo POS) queden cubiertos sin tocarlos uno a uno. Sobre esa base, un servicio `ResolveCustomerByPhone` centraliza buscar-o-crear dentro de la sucursal, y un trait de controlador lo conecta a los tres canales de captura (Caja, Sucursal, Hub). La asignación sigue delegando en el `AssignCustomerToSale` existente; lo nuevo es un cálculo previo del impacto para pedir confirmación solo cuando la asignación cambiaría el total o el estado de la venta.

**Tech Stack:** Laravel 13 · PHP 8.5 · PostgreSQL 18 · Vue 3 + Inertia 2 · PHPUnit 12 · Sail

**Estado: COMPLETADO** (2026-08-11). Las diez tareas están implementadas y en `main` — PR [#50](https://github.com/Dayikeynes16/backend/pull/50) (tareas 1-7) y [#51](https://github.com/Dayikeynes16/backend/pull/51) (ajuste de `isPlausible` tras el dry-run en producción).

Documentación viva del resultado: [docs/modulos/clientes-telefonos.md](../../modulos/clientes-telefonos.md). Este plan queda como historia congelada; para saber cómo funciona hoy, ese es el documento.

**Lo que cambió respecto a lo planeado, y por qué:**

1. **`skip_assign`** (Tarea 6). Sin él, rechazar la confirmación dejaba al usuario sin poder mandar la nota por WhatsApp — una regresión de una función existente.
2. **Arreglo del costo de envío** (intercalado entre la 6 y la 7, commit `605b3c5`). `AssignCustomerToSale` y `SaleItemEditor` recalculaban `sales.total` sin `delivery_fee`. Bug preexistente que la captura automática habría vuelto cotidiano. Centralizado en `App\Support\SaleTotals`.
3. **`isPlausible`** (PR #51). El dry-run en producción reveló que ~43 de 94 teléfonos no eran teléfonos, y que una fusión iba a mezclar dos clientes distintos con basura coincidente (`+8556`). La normalización ahora deja intacto lo que no parece un teléfono.
4. **La Tarea 8 dejó de arreglar un bug**: el mutator de la Tarea 3 ya había corregido los duplicados del pedido web. Se hizo igual para eliminar la lógica duplicada.
5. **`sales:check-integrity`** (no planeado): comando de solo lectura para verificar totales en producción sin acceso a SQL.

**Hallazgos durante la ejecución que afectan a las tareas siguientes:**

1. `customer_product_prices` **no tiene `tenant_id`** (solo `customer_id`, `product_id`, `price`) y lleva `UNIQUE(customer_id, product_id)`. Cualquier código que reasigne precios entre clientes debe usar el trait `MergesCustomerPreferentialPrices`, no un `UPDATE` masivo. Afecta a los tests de las Tareas 5 y 6 que insertan precios preferenciales: **no pasar `tenant_id`**.
2. `customers:dedup` es **legacy**: el índice único vigente impide los duplicados exactos que buscaba. Los que sí ocurren son los de formato, que resuelve `customers:normalize-phones`.
3. `PhoneNormalizer::normalize` tiene **7 callers**, no 4 (ver Tarea 1, Step 6).

## Global Constraints

- **Todos los comandos van por Sail:** `./vendor/bin/sail artisan ...`, `./vendor/bin/sail bin pint ...`. El build de assets sí corre en el host (`npm run build`).
- **La cartera sigue siendo por sucursal.** El índice único se mantiene en `(tenant_id, branch_id, phone)`. Un mismo número puede existir como cliente distinto en dos sucursales. No convertir a cartera global del tenant.
- **`customers.phone` guarda siempre E.164** (`+52XXXXXXXXXX`). El formato legible se compone en el frontend.
- **`customers.name` permanece NOT NULL.** Los clientes sin nombre llevan placeholder + `name_pending = true`.
- **Ningún canal escribe `phone` sin pasar por `PhoneNormalizer`** — lo garantiza el mutator del modelo.
- **Nunca bypassear `AssignCustomerToSale`** para asignar cliente a una venta: es quien recalcula precios preferenciales, totales y estado.
- Idioma: UI y docs en español; identificadores en inglés.
- Pint antes de cerrar cada tarea: `./vendor/bin/sail bin pint --dirty --format agent`.

---

## Contexto imprescindible (leer antes de la Tarea 1)

El sistema tiene **dos campos de teléfono en una venta que hoy no se hablan**:

- `sales.customer_id` → `customers.phone`, guardado **literal, como se teclea** (`993 123 4567`).
- `sales.contact_phone`, guardado **normalizado a E.164** (`+529931234567`).

De esa discrepancia salen los dos bugs que este plan cierra:

1. El flujo "Enviar nota por WhatsApp" guarda el teléfono en `sales.contact_phone` y **nunca busca cliente** (`Caja/WorkbenchController.php:216` lo dice explícitamente: *"No crea cliente"*). El número queda huérfano.
2. El pedido web sí hace `Customer::firstOrCreate(['branch_id', 'phone' => $contactPhone])` (`Public/OrderController.php:228`) pero busca en E.164 contra una columna literal → **crea un cliente duplicado cada vez** que alguien dado de alta a mano pide por el QR.

`app/Console/Commands/DedupCustomers.php` agrupa por `phone` exacto, así que es ciego a ese duplicado.

Documentos de referencia: `docs/modulos/clientes-caja.md`, `docs/modulos/ventas.md`, `docs/modulos/pedidos-web.md`.

## Decisiones tomadas (no re-litigar durante la ejecución)

| Decisión | Elegido | Por qué |
|---|---|---|
| Normalización | **In-place con mutator** en `customers.phone` | Una sola fuente de verdad; cubre los 5 canales de escritura sin tocarlos |
| Clientes sin nombre | **Placeholder + `name_pending`** | `name` sigue NOT NULL: ~10 puntos de Vue hacen `c.name.toLowerCase()` y reventarían con null |
| Asignación automática | **Automática salvo impacto real** | Ver refinamiento abajo |
| Histórico | **Comando con `--dry-run`** | Decidir con números reales antes de crear clientes en masa |

**Refinamiento de "salvo casos de riesgo".** La consulta original decía *"si el cliente tiene precios preferenciales **o** la venta ya tiene pagos"*. Aplicarlo literal sería contraproducente: el flujo típico es enviar la nota **después** de cobrar, así que "venta con pagos" sería casi siempre verdadero y la confirmación aparecería en cada captura — reintroduciendo exactamente la fricción que queríamos evitar. Y asignar un cliente sin precios preferenciales a una venta pagada es inocuo: el total se recalcula al mismo valor.

La regla implementada es más precisa y cumple el mismo objetivo (que nada cambie en silencio): **se calcula el impacto real y solo se pide confirmación si el total cambiaría o si la venta pasaría a `Completed`.** En todos los demás casos se asigna directo.

## Estructura de archivos

**Crear**
| Archivo | Responsabilidad |
|---|---|
| `app/Services/Customers/ResolveCustomerByPhone.php` | Buscar-o-crear cliente por teléfono dentro de una sucursal |
| `app/Services/Customers/CustomerResolution.php` | DTO del resultado (cliente + si fue creado) |
| `app/Services/Customers/CustomerAssignmentPreview.php` | Calcula el impacto de asignar un cliente **sin escribir** |
| `app/Http/Controllers/Concerns/HandlesSalePhoneCapture.php` | Orquesta captura → resolución → asignación; compartido por los 3 canales |
| `app/Console/Commands/NormalizeCustomerPhones.php` | Normaliza y fusiona la cartera existente (prerrequisito de la migración) |
| `app/Console/Commands/LinkOrphanSalePhones.php` | Recupera los `contact_phone` históricos |
| `database/migrations/2026_08_11_000001_add_name_pending_to_customers_table.php` | Columna `name_pending` |
| `resources/js/Components/CustomerAssignConfirmDialog.vue` | Confirmación cuando la asignación cambia el total |
| `resources/js/Components/CustomerNameDialog.vue` | Completar el nombre de un cliente `name_pending` |

**Modificar**
| Archivo | Cambio |
|---|---|
| `app/Services/PhoneNormalizer.php` | Firma nullable + formatos MX (521…, 52…) + `displayLocal()` |
| `app/Models/Customer.php` | Mutator de `phone`, `name_pending` en fillable + cast |
| `app/Services/WhatsappMessageService.php:100` | Adaptar al retorno nullable |
| `app/Http/Controllers/Concerns/HandlesCustomers.php:214-273` | Normalizar antes de comparar duplicados |
| `app/Http/Controllers/Api/Hub/CustomerController.php:360-380` | Ídem para el hub |
| `app/Services/Ai/Assistant/Drafts/Confirmers/CustomerDraftConfirmer.php:74` | Reusar el servicio en vez de `Customer::create` a pelo |
| `app/Http/Controllers/Public/OrderController.php:228` | Reusar el servicio (elimina el duplicado del canal web) |
| `app/Http/Controllers/Caja/WorkbenchController.php:215-261` | Usar el trait |
| `app/Http/Controllers/Sucursal/WorkbenchController.php:479-530` | Usar el trait |
| `app/Http/Controllers/Api/Hub/SaleController.php:346-373` | Usar el trait |
| `app/Console/Commands/DedupCustomers.php:24` | Agrupar por teléfono normalizado |
| `resources/js/composables/useWhatsappSend.js` | Manejar `requires_confirmation` |
| `resources/js/Components/SaleWhatsappPhoneChip.vue` | Chip "Sin nombre" + acción de completar |
| `resources/js/Components/{Caja,Sucursal}/SaleDetail.vue` | Búsqueda de clientes tolerante a formato |

---

### Task 1: PhoneNormalizer — un solo formato para todo el sistema

**Files:**
- Modify: `app/Services/PhoneNormalizer.php`
- Modify: `app/Services/WhatsappMessageService.php:100-103`
- Test: `tests/Unit/Services/PhoneNormalizerTest.php` (crear)

**Interfaces:**
- Produces: `PhoneNormalizer::normalize(?string): ?string` — devuelve E.164 o `null` si no hay dígitos utilizables. **Ojo: la firma actual es `normalize(string): string` y devuelve `''`; este cambio es la base de todo el plan.**
- Produces: `PhoneNormalizer::displayLocal(?string $e164): string` — `+529931234567` → `993 123 4567`, usado para el nombre placeholder.
- Consumes: nada.

- [x] **Step 1: Escribir el test que falla**

Crear `tests/Unit/Services/PhoneNormalizerTest.php`:

```php
<?php

namespace Tests\Unit\Services;

use App\Services\PhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function phoneProvider(): array
    {
        return [
            'diez digitos' => ['9931234567', '+529931234567'],
            'con espacios' => ['993 123 4567', '+529931234567'],
            'con guiones' => ['993-123-4567', '+529931234567'],
            'con parentesis' => ['(993) 123 4567', '+529931234567'],
            'ya en e164' => ['+529931234567', '+529931234567'],
            'e164 con espacios' => ['+52 993 123 4567', '+529931234567'],
            'lada sin mas' => ['529931234567', '+529931234567'],
            'movil legacy 521' => ['5219931234567', '+529931234567'],
            'movil legacy con mas' => ['+52 1 993 123 4567', '+529931234567'],
            'null' => [null, null],
            'vacio' => ['', null],
            'solo simbolos' => ['---', null],
        ];
    }

    #[DataProvider('phoneProvider')]
    public function test_normalize(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNormalizer::normalize($input));
    }

    public function test_normalize_es_idempotente(): void
    {
        $once = PhoneNormalizer::normalize('993 123 4567');
        $this->assertSame($once, PhoneNormalizer::normalize($once));
    }

    public function test_display_local_formatea_para_humanos(): void
    {
        $this->assertSame('993 123 4567', PhoneNormalizer::displayLocal('+529931234567'));
    }

    public function test_display_local_tolera_null(): void
    {
        $this->assertSame('', PhoneNormalizer::displayLocal(null));
    }
}
```

- [x] **Step 2: Correr el test para verificar que falla**

Run: `./vendor/bin/sail artisan test --compact tests/Unit/Services/PhoneNormalizerTest.php`
Expected: FAIL — `displayLocal` no existe y `normalize(null)` lanza TypeError.

- [x] **Step 3: Implementar**

Reemplazar el contenido de `app/Services/PhoneNormalizer.php`:

```php
<?php

namespace App\Services;

/**
 * Normalización canónica de teléfonos a E.164 mexicano.
 *
 * Es el único lugar del sistema que decide qué formato tiene un teléfono.
 * `Customer::phone` lo aplica por mutator, así que toda escritura de cliente
 * queda normalizada sin importar el canal (CRUD, hub, IA, pedido web, POS).
 */
class PhoneNormalizer
{
    /**
     * Devuelve el teléfono en E.164 (+52XXXXXXXXXX) o null si no hay dígitos
     * utilizables. Es idempotente: normalizar dos veces da lo mismo.
     */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $input) ?? '';

        if ($digits === '') {
            return null;
        }

        // +52 1 XXXXXXXXXX — formato móvil legacy, el 1 ya no se marca.
        if (strlen($digits) === 13 && str_starts_with($digits, '521')) {
            return '+52'.substr($digits, 3);
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '52')) {
            return '+52'.substr($digits, 2);
        }

        if (strlen($digits) === 10) {
            return '+52'.$digits;
        }

        // Número extranjero o incompleto: se respeta tal cual, en E.164.
        return '+'.$digits;
    }

    /**
     * Formato legible de los 10 dígitos locales: `993 123 4567`.
     * Se usa para el nombre placeholder de clientes sin nombre.
     */
    public static function displayLocal(?string $e164): string
    {
        $digits = self::digits($e164 ?? '');

        if (strlen($digits) === 12 && str_starts_with($digits, '52')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) !== 10) {
            return $e164 ?? '';
        }

        return substr($digits, 0, 3).' '.substr($digits, 3, 3).' '.substr($digits, 6);
    }

    public static function digits(string $e164): string
    {
        return preg_replace('/\D/', '', $e164) ?? '';
    }
}
```

- [x] **Step 4: Adaptar el único caller que comparaba contra `''`**

En `app/Services/WhatsappMessageService.php`, sustituir:

```php
        $normalized = PhoneNormalizer::normalize($rawPhone);
        if ($normalized === '') {
            return ['url' => null, 'available' => false, 'reason' => 'invalid_phone'];
        }
```

por:

```php
        $normalized = PhoneNormalizer::normalize($rawPhone);
        if ($normalized === null) {
            return ['url' => null, 'available' => false, 'reason' => 'invalid_phone'];
        }
```

- [x] **Step 5: Correr los tests**

Run: `./vendor/bin/sail artisan test --compact tests/Unit/Services/PhoneNormalizerTest.php tests/Feature/Caja/WhatsappLinkTest.php tests/Feature/Sucursal/WhatsappLinkTest.php`
Expected: PASS todos.

- [x] **Step 6: Verificar que ningún otro caller espera string no-nulo**

Run: `grep -rn "PhoneNormalizer::normalize" app/ tests/`

**Resultado real al ejecutar (2026-08-11):** hay **siete** callers, tres más de los previstos.

| Caller | Estado |
|---|---|
| `Caja/WorkbenchController:238`, `Sucursal/WorkbenchController:503`, `Hub/SaleController:360`, `Public/OrderController:59` | Sin cambios: reciben un valor ya validado (regex de 10 dígitos), nunca null |
| `Empresa/SucursalController:145` (`public_phone`), `Empresa/ConfiguracionController:35` (`owner_whatsapp`) | Sin cambios: guardados por `! empty()`. El retorno `null` en vez de `''` para basura es más correcto — la columna es nullable |
| `Concerns/HandlesCustomerStats:227` | **Requirió arreglo.** Comparaba `$normalized !== ''`; con la firma nueva un teléfono ilegible devuelve `null`, pasaba el guard y llegaba a `buildUrl(string $phoneE164, …)`, que no acepta null. Corregido a `!== null` |

Lección para las tareas siguientes: **grepear siempre antes de asumir la lista de callers del plan**.

- [x] **Step 7: Commit**

```bash
./vendor/bin/sail bin pint --dirty --format agent
git add app/Services/PhoneNormalizer.php app/Services/WhatsappMessageService.php tests/Unit/Services/PhoneNormalizerTest.php
git commit -m "feat(telefonos): PhoneNormalizer canonico con formatos MX y retorno nullable"
```

---

### Task 2: Normalizar y fusionar la cartera existente

Prerrequisito de la Tarea 3: si el mutator entra antes de limpiar los duplicados de formato, la primera edición de un cliente cuyo número normalizado ya existe choca contra el índice único.

**Files:**
- Create: `app/Console/Commands/NormalizeCustomerPhones.php`
- Modify: `app/Console/Commands/DedupCustomers.php:21-26`
- Test: `tests/Feature/Console/NormalizeCustomerPhonesTest.php` (crear)

**Interfaces:**
- Consumes: `PhoneNormalizer::normalize()` de la Tarea 1.
- Produces: comando `customers:normalize-phones {--dry-run=true}`. Deja toda la tabla en E.164 y sin duplicados por formato.

- [x] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Console/NormalizeCustomerPhonesTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Models\Customer;
use App\Models\Sale;
use App\Enums\SaleStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class NormalizeCustomerPhonesTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_dry_run_no_modifica_nada(): void
    {
        $id = $this->rawCustomer('993 123 4567', 'Juan');

        $this->artisan('customers:normalize-phones', ['--dry-run' => 'true'])
            ->assertSuccessful();

        $this->assertSame('993 123 4567', DB::table('customers')->where('id', $id)->value('phone'));
    }

    public function test_normaliza_telefonos_al_aplicar(): void
    {
        $id = $this->rawCustomer('993 123 4567', 'Juan');

        $this->artisan('customers:normalize-phones', ['--dry-run' => 'false'])
            ->assertSuccessful();

        $this->assertSame('+529931234567', DB::table('customers')->where('id', $id)->value('phone'));
    }

    public function test_fusiona_duplicados_que_solo_diferian_en_formato(): void
    {
        $keep = $this->rawCustomer('993 123 4567', 'Juan Perez');
        $dupe = $this->rawCustomer('+529931234567', 'Juan P.');

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $dupe,
            'folio' => 'V-'.uniqid(),
            'total' => 100, 'amount_paid' => 0, 'amount_pending' => 100,
            'origin' => 'admin', 'status' => SaleStatus::Active,
        ]);

        $this->artisan('customers:normalize-phones', ['--dry-run' => 'false'])
            ->assertSuccessful();

        // Sobrevive el más antiguo y hereda las ventas del fusionado.
        $this->assertNull(Customer::find($dupe));
        $this->assertSame($keep, $sale->fresh()->customer_id);
        $this->assertSame('+529931234567', Customer::find($keep)->phone);
    }

    public function test_no_toca_clientes_sin_telefono(): void
    {
        $id = DB::table('customers')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Sin telefono', 'phone' => null, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('customers:normalize-phones', ['--dry-run' => 'false'])
            ->assertSuccessful();

        $this->assertNull(DB::table('customers')->where('id', $id)->value('phone'));
    }

    /** Inserta sin pasar por el modelo, para simular datos previos al mutator. */
    private function rawCustomer(string $phone, string $name): int
    {
        return DB::table('customers')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => $name,
            'phone' => $phone,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
```

- [x] **Step 2: Correr para verificar que falla**

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Console/NormalizeCustomerPhonesTest.php`
Expected: FAIL — `Command "customers:normalize-phones" is not defined.`

- [x] **Step 3: Implementar el comando**

Crear `app/Console/Commands/NormalizeCustomerPhones.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\PhoneNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deja `customers.phone` en E.164 y fusiona los clientes que solo diferían en
 * el formato del número. Prerrequisito del mutator de `Customer::phone`: sin
 * esto, la primera edición de un cliente cuyo número normalizado ya existe
 * choca contra `customers_tenant_branch_phone_uniq`.
 *
 * Se conserva el registro más antiguo de cada grupo (MIN(id)) y hereda ventas
 * y precios preferenciales de los fusionados — misma política que
 * `customers:dedup`.
 */
class NormalizeCustomerPhones extends Command
{
    protected $signature = 'customers:normalize-phones {--dry-run=true : Reporta sin modificar datos}';

    protected $description = 'Normaliza customers.phone a E.164 y fusiona duplicados por formato';

    public function handle(): int
    {
        $dryRun = filter_var($this->option('dry-run'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        $this->info($dryRun ? '=== DRY RUN — no se escribe nada ===' : '=== APLICANDO CAMBIOS ===');

        $rows = DB::table('customers')
            ->whereNotNull('phone')
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'branch_id', 'phone', 'name']);

        /** @var array<string, array<int, object>> $groups */
        $groups = [];
        foreach ($rows as $row) {
            $normalized = PhoneNormalizer::normalize($row->phone);
            if ($normalized === null) {
                $this->warn("  #{$row->id} '{$row->name}' tiene teléfono ilegible ('{$row->phone}') — se deja intacto.");

                continue;
            }
            $groups["{$row->tenant_id}|{$row->branch_id}|{$normalized}"][] = $row;
        }

        $merged = 0;
        $rewritten = 0;

        foreach ($groups as $key => $group) {
            $normalized = explode('|', $key)[2];
            $keep = $group[0];
            $duplicates = array_slice($group, 1);

            if ($duplicates !== []) {
                $ids = implode(',', array_map(fn ($d) => $d->id, $duplicates));
                $this->line("  fusionar [{$ids}] → #{$keep->id} ('{$keep->name}') phone={$normalized}");
            } elseif ($keep->phone !== $normalized) {
                $this->line("  #{$keep->id} '{$keep->phone}' → '{$normalized}'");
            } else {
                continue;
            }

            if ($dryRun) {
                $merged += count($duplicates);
                $rewritten++;

                continue;
            }

            DB::transaction(function () use ($keep, $duplicates, $normalized, &$merged, &$rewritten) {
                if ($duplicates !== []) {
                    $ids = array_map(fn ($d) => $d->id, $duplicates);

                    DB::table('sales')->whereIn('customer_id', $ids)->update(['customer_id' => $keep->id]);
                    DB::table('customer_product_prices')->whereIn('customer_id', $ids)->update(['customer_id' => $keep->id]);
                    DB::table('customers')->whereIn('id', $ids)->delete();

                    $merged += count($ids);
                }

                DB::table('customers')->where('id', $keep->id)->update([
                    'phone' => $normalized,
                    'updated_at' => now(),
                ]);
                $rewritten++;
            });
        }

        $this->info($dryRun
            ? "Dry run: {$rewritten} teléfonos a reescribir, {$merged} clientes a fusionar. Re-ejecuta con --dry-run=false."
            : "Listo. {$rewritten} teléfonos normalizados, {$merged} clientes fusionados.");

        return self::SUCCESS;
    }
}
```

**Nota sobre `customer_product_prices`:** al fusionar puede haber dos precios preferenciales del mismo producto (uno por cliente). El `update` masivo los deja ambos apuntando al superviviente. Es aceptable porque `AssignCustomerToSale` hace `keyBy('product_id')` y se queda con uno determinista, pero conviene reportarlo — añadir tras el update:

```php
                    $dupePrices = DB::table('customer_product_prices')
                        ->where('customer_id', $keep->id)
                        ->select('product_id', DB::raw('COUNT(*) as total'))
                        ->groupBy('product_id')
                        ->having(DB::raw('COUNT(*)'), '>', 1)
                        ->count();

                    if ($dupePrices > 0) {
                        $this->warn("  #{$keep->id} quedó con {$dupePrices} producto(s) con precio preferencial duplicado — revisar a mano.");
                    }
```

- [x] **Step 4: Correr los tests**

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Console/NormalizeCustomerPhonesTest.php`
Expected: PASS (4 tests).

- [x] **Step 5: Alinear `customers:dedup` con la normalización**

En `app/Console/Commands/DedupCustomers.php`, el `groupBy('tenant_id','branch_id','phone')` es ciego a los duplicados por formato. Añadir al inicio de `handle()`, justo después de la línea `$this->info($dryRun ? ...)`:

```php
        $unnormalized = DB::table('customers')
            ->whereNotNull('phone')
            ->where('phone', 'not like', '+%')
            ->count();

        if ($unnormalized > 0) {
            $this->warn("Hay {$unnormalized} teléfonos sin normalizar. Corre primero: php artisan customers:normalize-phones --dry-run=false");
            $this->warn('Este comando agrupa por teléfono exacto y NO detectará duplicados que solo difieren en formato.');
        }
```

- [x] **Step 6: Correr la suite de clientes completa**

Run: `./vendor/bin/sail artisan test --compact --filter=Customer`
Expected: PASS. Anotar en el commit cuántos tests corrieron.

- [x] **Step 7: Commit**

```bash
./vendor/bin/sail bin pint --dirty --format agent
git add app/Console/Commands/ tests/Feature/Console/NormalizeCustomerPhonesTest.php
git commit -m "feat(clientes): comando para normalizar telefonos y fusionar duplicados por formato"
```

---

### Task 3: Mutator en Customer + columna `name_pending`

**Files:**
- Create: `database/migrations/2026_08_11_000001_add_name_pending_to_customers_table.php`
- Modify: `app/Models/Customer.php`
- Test: `tests/Feature/Clientes/CustomerPhoneNormalizationTest.php` (crear)

**Interfaces:**
- Consumes: `PhoneNormalizer::normalize()` (Tarea 1).
- Produces: `customers.name_pending` (bool, default false); `Customer::$phone` siempre en E.164 al leer tras escribir.

- [x] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Clientes/CustomerPhoneNormalizationTest.php`:

```php
<?php

namespace Tests\Feature\Clientes;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CustomerPhoneNormalizationTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_el_telefono_se_guarda_normalizado_sin_importar_el_formato(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Juan',
            'phone' => '993 123 4567',
            'status' => 'active',
        ]);

        $this->assertSame('+529931234567', $customer->fresh()->phone);
    }

    public function test_el_telefono_normalizado_permite_encontrar_al_cliente(): void
    {
        Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Juan',
            'phone' => '(993) 123-4567',
            'status' => 'active',
        ]);

        $found = Customer::where('branch_id', $this->branch->id)
            ->where('phone', '+529931234567')
            ->first();

        $this->assertNotNull($found);
    }

    public function test_telefono_nulo_sigue_siendo_nulo(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Sin telefono',
            'phone' => null,
            'status' => 'active',
        ]);

        $this->assertNull($customer->fresh()->phone);
    }

    public function test_name_pending_default_false(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Juan',
            'phone' => '9931234567',
            'status' => 'active',
        ]);

        $this->assertFalse($customer->fresh()->name_pending);
    }
}
```

- [x] **Step 2: Correr para verificar que falla**

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Clientes/CustomerPhoneNormalizationTest.php`
Expected: FAIL — el teléfono se guarda literal y `name_pending` no existe.

- [x] **Step 3: Crear la migración**

Crear `database/migrations/2026_08_11_000001_add_name_pending_to_customers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca los clientes creados automáticamente desde una venta, que aún no
 * tienen nombre real (su `name` es un placeholder derivado del teléfono).
 *
 * `name` se mantiene NOT NULL a propósito: hay ~10 puntos en el frontend que
 * hacen `c.name.toLowerCase()` y reventarían con null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('name_pending')->default(false)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('name_pending');
        });
    }
};
```

- [x] **Step 4: Implementar el mutator**

Reemplazar `app/Models/Customer.php`:

```php
<?php

namespace App\Models;

use App\Services\PhoneNormalizer;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'branch_id', 'name', 'name_pending', 'phone', 'notes', 'status'])]
class Customer extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'name_pending' => 'boolean',
        ];
    }

    /**
     * El teléfono se guarda siempre en E.164. Al vivir en el modelo, cubre a
     * todos los canales que dan de alta clientes (CRUD web, hub, asistente IA,
     * pedido web y captura desde la venta) sin que ninguno tenga que acordarse.
     */
    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value) => PhoneNormalizer::normalize($value));
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(CustomerProductPrice::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
```

- [x] **Step 5: Migrar y correr los tests**

Run: `./vendor/bin/sail artisan migrate && ./vendor/bin/sail artisan test --compact tests/Feature/Clientes/CustomerPhoneNormalizationTest.php`
Expected: PASS (4 tests).

- [x] **Step 6: Normalizar los puntos que validan unicidad**

Con el mutator, las reglas `Rule::unique('customers','phone')` y los `where('phone', $input)` comparan el valor **crudo** contra una columna **normalizada** — dejarían pasar duplicados. Hay tres puntos.

En `app/Http/Controllers/Concerns/HandlesCustomers.php`, dentro de `store()`, sustituir el bloque de validación y chequeo por:

```php
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'notes' => 'nullable|string|max:1000',
        ]);

        $validated['phone'] = PhoneNormalizer::normalize($validated['phone']);

        if ($validated['phone'] === null) {
            return back()->withErrors(['phone' => 'El teléfono no es válido.']);
        }

        $exists = Customer::where('branch_id', $user->branch_id)
            ->where('phone', $validated['phone'])
            ->exists();
```

Y en `update()`, justo después del `$request->validate($rules)`:

```php
        $validated['phone'] = PhoneNormalizer::normalize($validated['phone']);

        if ($validated['phone'] === null) {
            return back()->withErrors(['phone' => 'El teléfono no es válido.']);
        }
```

Añadir el import `use App\Services\PhoneNormalizer;` al inicio del trait.

En `app/Http/Controllers/Api/Hub/CustomerController.php`, dentro de `validateCustomer()`, antes de construir `$phoneRule`, normalizar la entrada:

```php
    private function validateCustomer(Request $request, int $branchId, ?int $ignoreId = null, bool $withStatus = false): array
    {
        // El mutator de Customer normaliza al guardar; hay que comparar contra
        // el mismo formato o la regla `unique` nunca detectaría el duplicado.
        if ($request->filled('phone')) {
            $request->merge(['phone' => PhoneNormalizer::normalize($request->input('phone'))]);
        }

        $phoneRule = Rule::unique('customers', 'phone')
            ->where(fn ($q) => $q->where('branch_id', $branchId));
```

Añadir `use App\Services\PhoneNormalizer;` al inicio del controlador.

- [x] **Step 7: Test de que la unicidad ahora sí detecta el duplicado por formato**

Añadir a `tests/Feature/Clientes/CustomerPhoneNormalizationTest.php`:

```php
    public function test_no_se_puede_dar_de_alta_el_mismo_numero_con_otro_formato(): void
    {
        Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Juan',
            'phone' => '9931234567',
            'status' => 'active',
        ]);

        $this->actingAs($this->adminSucursal)
            ->post(route('sucursal.clientes.store', $this->tenant->slug), [
                'name' => 'Juan otra vez',
                'phone' => '993 123 4567',
            ])
            ->assertSessionHasErrors('phone');

        $this->assertSame(1, Customer::where('branch_id', $this->branch->id)->count());
    }
```

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Clientes/CustomerPhoneNormalizationTest.php`
Expected: PASS (5 tests).

**Si `$this->adminSucursal` no existe en `SeedsMetricsData`**, revisar el nombre real de la propiedad con `grep -n "adminSucursal\|protected \$" tests/Concerns/SeedsMetricsData.php` y usar el que corresponda.

- [x] **Step 8: Correr toda la suite tocada por el mutator**

Run: `./vendor/bin/sail artisan test --compact --filter="Customer|Whatsapp|Cliente|Order"`
Expected: PASS. **Este es el punto de mayor riesgo del plan** — cualquier test que cree un cliente con teléfono literal y luego lo compare crudo fallará. Corregir esos tests para esperar E.164 (es el nuevo comportamiento correcto), nunca debilitar el mutator.

- [x] **Step 9: Commit**

```bash
./vendor/bin/sail bin pint --dirty --format agent
git add app/Models/Customer.php database/migrations/ app/Http/Controllers/Concerns/HandlesCustomers.php app/Http/Controllers/Api/Hub/CustomerController.php tests/Feature/Clientes/
git commit -m "feat(clientes): normalizacion de telefono por mutator y flag name_pending"
```

---

### Task 4: Servicio de resolución cliente-por-teléfono

**Files:**
- Create: `app/Services/Customers/CustomerResolution.php`
- Create: `app/Services/Customers/ResolveCustomerByPhone.php`
- Test: `tests/Feature/Clientes/ResolveCustomerByPhoneTest.php` (crear)

**Interfaces:**
- Consumes: `PhoneNormalizer::normalize()`, `Customer` con mutator y `name_pending`.
- Produces:
  - `CustomerResolution` — readonly, con `Customer $customer`, `bool $wasCreated`.
  - `ResolveCustomerByPhone::execute(string $phone, int $branchId, int $tenantId): CustomerResolution`
  - Lanza `InvalidArgumentException` si el teléfono no normaliza.

- [x] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Clientes/ResolveCustomerByPhoneTest.php`:

```php
<?php

namespace Tests\Feature\Clientes;

use App\Models\Customer;
use App\Services\Customers\ResolveCustomerByPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ResolveCustomerByPhoneTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private ResolveCustomerByPhone $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->service = app(ResolveCustomerByPhone::class);
    }

    public function test_devuelve_el_cliente_existente_sin_crear_otro(): void
    {
        $existing = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Juan Perez',
            'phone' => '9931234567',
            'status' => 'active',
        ]);

        $result = $this->service->execute('9931234567', $this->branch->id, $this->tenant->id);

        $this->assertFalse($result->wasCreated);
        $this->assertSame($existing->id, $result->customer->id);
        $this->assertSame(1, Customer::where('branch_id', $this->branch->id)->count());
    }

    public function test_encuentra_al_cliente_aunque_el_formato_sea_distinto(): void
    {
        $existing = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Juan Perez',
            'phone' => '993 123 4567',
            'status' => 'active',
        ]);

        $result = $this->service->execute('+52 993 123 4567', $this->branch->id, $this->tenant->id);

        $this->assertFalse($result->wasCreated);
        $this->assertSame($existing->id, $result->customer->id);
    }

    public function test_crea_cliente_sin_nombre_cuando_el_telefono_es_nuevo(): void
    {
        $result = $this->service->execute('9939999999', $this->branch->id, $this->tenant->id);

        $this->assertTrue($result->wasCreated);
        $this->assertTrue($result->customer->name_pending);
        $this->assertSame('Cliente 993 999 9999', $result->customer->name);
        $this->assertSame('+529939999999', $result->customer->phone);
        $this->assertSame('active', $result->customer->status);
    }

    public function test_no_cruza_sucursales(): void
    {
        Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->secondBranch->id,
            'name' => 'Juan en otra sucursal',
            'phone' => '9931234567',
            'status' => 'active',
        ]);

        $result = $this->service->execute('9931234567', $this->branch->id, $this->tenant->id);

        // Mismo número, otra sucursal: se crea uno nuevo. La cartera es por sucursal.
        $this->assertTrue($result->wasCreated);
        $this->assertSame($this->branch->id, $result->customer->branch_id);
        $this->assertSame(2, Customer::withoutGlobalScopes()->where('phone', '+529931234567')->count());
    }

    public function test_reutiliza_cliente_inactivo_en_vez_de_duplicarlo(): void
    {
        $inactive = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Juan Inactivo',
            'phone' => '9931234567',
            'status' => 'inactive',
        ]);

        $result = $this->service->execute('9931234567', $this->branch->id, $this->tenant->id);

        $this->assertFalse($result->wasCreated);
        $this->assertSame($inactive->id, $result->customer->id);
    }

    public function test_rechaza_telefono_ilegible(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->execute('---', $this->branch->id, $this->tenant->id);
    }
}
```

**Helpers disponibles:** `tests/Concerns/SeedsMetricsData` ya expone `$tenant`, `$branch`, `$secondBranch`, `$adminSucursal`, `$adminEmpresa`, `$cajero` y los helpers `makeProduct()`, `makeCompletedSale()`, `makeCreditSale()`. **No existe `$this->product`** — hay que llamar a `makeProduct()`.

- [x] **Step 2: Correr para verificar que falla**

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Clientes/ResolveCustomerByPhoneTest.php`
Expected: FAIL — `Class "App\Services\Customers\ResolveCustomerByPhone" not found`.

- [x] **Step 3: Implementar el DTO**

Crear `app/Services/Customers/CustomerResolution.php`:

```php
<?php

namespace App\Services\Customers;

use App\Models\Customer;

/**
 * Resultado de resolver un teléfono a un cliente de la sucursal.
 */
readonly class CustomerResolution
{
    public function __construct(
        public Customer $customer,
        public bool $wasCreated,
    ) {}
}
```

- [x] **Step 4: Implementar el servicio**

Crear `app/Services/Customers/ResolveCustomerByPhone.php`:

```php
<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Services\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Busca un cliente por teléfono dentro de una sucursal y, si no existe, lo crea
 * sin nombre (`name_pending`). Es el único punto del sistema que decide si un
 * teléfono corresponde a un cliente nuevo o a uno existente.
 *
 * La cartera es **por sucursal**: el mismo número puede existir como cliente
 * independiente en dos sucursales del mismo tenant, y así debe seguir siendo.
 *
 * El advisory lock por sucursal evita que dos cajas capturando el mismo número
 * a la vez creen dos clientes. Es el mismo patrón que usa `OrderController`.
 */
class ResolveCustomerByPhone
{
    public function execute(string $phone, int $branchId, int $tenantId): CustomerResolution
    {
        $normalized = PhoneNormalizer::normalize($phone);

        if ($normalized === null) {
            throw new InvalidArgumentException('El teléfono no es válido.');
        }

        return DB::transaction(function () use ($normalized, $branchId, $tenantId) {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$branchId]);

            // Sin filtro de status: un cliente inactivo con ese número sigue
            // siendo ese cliente — reactivarlo o no es decisión del usuario,
            // pero duplicarlo nunca es correcto.
            $existing = Customer::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->where('phone', $normalized)
                ->first();

            if ($existing) {
                return new CustomerResolution($existing, false);
            }

            $customer = Customer::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'name' => self::placeholderName($normalized),
                'name_pending' => true,
                'phone' => $normalized,
                'status' => 'active',
            ]);

            return new CustomerResolution($customer, true);
        });
    }

    /**
     * Nombre provisional legible para un cliente del que solo sabemos el número.
     */
    public static function placeholderName(string $e164): string
    {
        return 'Cliente '.PhoneNormalizer::displayLocal($e164);
    }
}
```

- [x] **Step 5: Correr los tests**

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Clientes/ResolveCustomerByPhoneTest.php`
Expected: PASS (6 tests).

- [x] **Step 6: Commit**

```bash
./vendor/bin/sail bin pint --dirty --format agent
git add app/Services/Customers/ tests/Feature/Clientes/ResolveCustomerByPhoneTest.php
git commit -m "feat(clientes): servicio ResolveCustomerByPhone con creacion automatica sin nombre"
```

---

### Task 5: Preview del impacto de asignar un cliente

Permite decidir si la asignación es silenciosa o necesita confirmación, **sin escribir nada**.

**Files:**
- Create: `app/Services/Customers/CustomerAssignmentPreview.php`
- Test: `tests/Feature/Clientes/CustomerAssignmentPreviewTest.php` (crear)

**Interfaces:**
- Consumes: `SaleItemMath` (`app/Support/SaleItemMath.php`), `Customer::prices`.
- Produces: `CustomerAssignmentPreview::for(Sale $sale, Customer $customer): array{current_total: float, new_total: float, changes_total: bool, would_complete: bool, skipped_piece_presentations: array<string>}`

- [x] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Clientes/CustomerAssignmentPreviewTest.php`. Usar `tests/Feature/Sucursal/AssignCustomerPresentationTest.php` como referencia para armar venta con items y precios preferenciales (`grep -n "CustomerProductPrice" tests/Feature/Sucursal/AssignCustomerPresentationTest.php`).

**Detalle que hace o rompe estos tests:** el precio preferencial **solo aplica a líneas de peso/volumen**. `SaleItemMath::isWeightOrVolume()` mira `quantity_unit` (o el snapshot de presentación), y `makeProduct()` crea productos con `unit_type => 'pieza'`. Por eso el producto se crea con `unit_type => 'kg'` **y** la línea lleva `quantity_unit => 'kg'`: sin eso el preferencial se salta y el total nunca cambia.

```php
<?php

namespace Tests\Feature\Clientes;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\CustomerProductPrice;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Customers\CustomerAssignmentPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CustomerAssignmentPreviewTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->product = $this->makeProduct(['unit_type' => 'kg', 'price' => 100]);
    }

    public function test_cliente_sin_precios_preferenciales_no_cambia_el_total(): void
    {
        $customer = $this->makeCustomer('9931234567');
        $sale = $this->makeSaleWithItem(unitPrice: 100, quantity: 2);

        $preview = CustomerAssignmentPreview::for($sale, $customer);

        $this->assertFalse($preview['changes_total']);
        $this->assertSame(200.0, $preview['new_total']);
        $this->assertFalse($preview['would_complete']);
    }

    public function test_cliente_con_precio_preferencial_cambia_el_total(): void
    {
        $customer = $this->makeCustomer('9931234567');
        $sale = $this->makeSaleWithItem(unitPrice: 100, quantity: 2);

        CustomerProductPrice::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'product_id' => $this->product->id,
            'price' => 80,
        ]);

        $preview = CustomerAssignmentPreview::for($sale, $customer->fresh());

        $this->assertTrue($preview['changes_total']);
        $this->assertSame(200.0, $preview['current_total']);
        $this->assertSame(160.0, $preview['new_total']);
    }

    public function test_detecta_que_la_venta_quedaria_completada(): void
    {
        $customer = $this->makeCustomer('9931234567');
        $sale = $this->makeSaleWithItem(unitPrice: 100, quantity: 2);
        $sale->update(['amount_paid' => 200, 'amount_pending' => 0]);

        $preview = CustomerAssignmentPreview::for($sale->fresh(), $customer);

        $this->assertTrue($preview['would_complete']);
    }

    public function test_no_escribe_nada_en_la_base(): void
    {
        $customer = $this->makeCustomer('9931234567');
        $sale = $this->makeSaleWithItem(unitPrice: 100, quantity: 2);

        CustomerAssignmentPreview::for($sale, $customer);

        $this->assertNull($sale->fresh()->customer_id);
        $this->assertSame('200.00', $sale->fresh()->total);
    }

    private function makeCustomer(string $phone): Customer
    {
        return Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente prueba',
            'phone' => $phone,
            'status' => 'active',
        ]);
    }

    private function makeSaleWithItem(float $unitPrice, float $quantity): Sale
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'V-'.uniqid(),
            'total' => $unitPrice * $quantity,
            'amount_paid' => 0,
            'amount_pending' => $unitPrice * $quantity,
            'origin' => 'admin',
            'status' => SaleStatus::Active,
        ]);

        $sale->items()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'quantity' => $quantity,
            'quantity_unit' => 'kg',       // ← sin esto el preferencial no aplica
            'unit_type' => 'kg',
            'unit_price' => $unitPrice,
            'original_unit_price' => $unitPrice,
            'subtotal' => $unitPrice * $quantity,
        ]);

        return $sale->fresh();
    }
}
```

**Antes de correr:** confirmar los campos obligatorios de `CustomerProductPrice` con `grep -n "Fillable" -A 4 app/Models/CustomerProductPrice.php` y ajustar si difiere de `['tenant_id','customer_id','product_id','price']`.

- [x] **Step 2: Correr para verificar que falla**

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Clientes/CustomerAssignmentPreviewTest.php`
Expected: FAIL — clase no encontrada.

- [x] **Step 3: Implementar**

Crear `app/Services/Customers/CustomerAssignmentPreview.php`:

```php
<?php

namespace App\Services\Customers;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Sale;
use App\Support\SaleItemMath;

/**
 * Calcula qué pasaría si se asignara un cliente a una venta, sin escribir nada.
 *
 * Existe porque asignar cliente NO es una operación neutra: `AssignCustomerToSale`
 * recalcula los precios de línea con los preferenciales del cliente y puede
 * transicionar la venta a `Completed`. Capturar un teléfono no debe alterar en
 * silencio el total de una venta ya cobrada — pero tampoco tiene sentido pedir
 * confirmación cuando no cambia nada, que es el caso más común.
 *
 * Debe mantenerse en sintonía con la lógica de `AssignCustomerToSale::execute()`.
 */
class CustomerAssignmentPreview
{
    /**
     * @return array{
     *     current_total: float,
     *     new_total: float,
     *     changes_total: bool,
     *     would_complete: bool,
     *     skipped_piece_presentations: array<string>
     * }
     */
    public static function for(Sale $sale, Customer $customer): array
    {
        $sale->loadMissing('items');
        $customer->loadMissing('prices');

        $preferentialPrices = $customer->prices->keyBy('product_id');
        $skipped = [];
        $newTotal = 0.0;

        foreach ($sale->items as $item) {
            $prefPrice = $preferentialPrices->get($item->product_id);

            if (! $prefPrice) {
                $newTotal += (float) $item->subtotal;

                continue;
            }

            if (! SaleItemMath::isWeightOrVolume($item)) {
                $skipped[] = $item->product_name;
                $newTotal += (float) $item->subtotal;

                continue;
            }

            $unitPrice = SaleItemMath::unitPriceForBasePrice($item, (float) $prefPrice->price);
            $newTotal += round($unitPrice * (float) $item->quantity, 2);
        }

        $newTotal = round($newTotal, 2);
        $currentTotal = round((float) $sale->total, 2);
        $amountPaid = (float) $sale->amount_paid;
        $newPending = round(max($newTotal - $amountPaid, 0), 2);

        return [
            'current_total' => $currentTotal,
            'new_total' => $newTotal,
            'changes_total' => abs($newTotal - $currentTotal) >= 0.01,
            'would_complete' => $newPending <= 0 && $amountPaid > 0 && $sale->status !== SaleStatus::Completed,
            'skipped_piece_presentations' => array_values(array_unique($skipped)),
        ];
    }
}
```

- [x] **Step 4: Correr los tests**

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Clientes/CustomerAssignmentPreviewTest.php`
Expected: PASS (4 tests).

- [x] **Step 5: Commit**

```bash
./vendor/bin/sail bin pint --dirty --format agent
git add app/Services/Customers/CustomerAssignmentPreview.php tests/Feature/Clientes/CustomerAssignmentPreviewTest.php
git commit -m "feat(ventas): preview del impacto de asignar cliente sin escribir"
```

---

### Task 6: Captura de teléfono en la venta → cliente (backend, 3 canales)

**Files:**
- Create: `app/Http/Controllers/Concerns/HandlesSalePhoneCapture.php`
- Modify: `app/Http/Controllers/Caja/WorkbenchController.php:215-242`
- Modify: `app/Http/Controllers/Sucursal/WorkbenchController.php:479-506`
- Modify: `app/Http/Controllers/Api/Hub/SaleController.php:346-362`
- Test: `tests/Feature/Ventas/CapturePhoneCreatesCustomerTest.php` (crear)

**Interfaces:**
- Consumes: `ResolveCustomerByPhone::execute()`, `CustomerAssignmentPreview::for()`, `AssignCustomerToSale::execute()`, `WhatsappMessageService::linkForSale()`.
- Produces: `capturePhoneForSale(Sale $sale, string $phone, bool $confirmed, WhatsappMessageService $whatsapp): array` — el payload JSON que devuelven los tres endpoints.

**Contrato de la respuesta** (el frontend de la Tarea 7 depende de esto):

```jsonc
// Asignado (caso normal)
{ "url": "https://wa.me/...", "available": true,
  "customer": { "id": 12, "name": "Juan Pérez", "name_pending": false, "phone": "+529931234567" },
  "customer_created": false }

// Necesita confirmación
{ "requires_confirmation": true,
  "customer": { "id": 12, "name": "Juan Pérez", "name_pending": false, "phone": "+529931234567" },
  "customer_created": false,
  "preview": { "current_total": 450.0, "new_total": 410.0, "changes_total": true,
               "would_complete": false, "skipped_piece_presentations": [] } }
```

- [x] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Ventas/CapturePhoneCreatesCustomerTest.php`:

```php
<?php

namespace Tests\Feature\Ventas;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Capturar un teléfono en una venta debe resolver siempre a un cliente de la
 * sucursal: reutilizando el existente o creando uno sin nombre.
 */
class CapturePhoneCreatesCustomerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_telefono_de_cliente_existente_lo_asocia_a_la_venta(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Juan Perez',
            'phone' => '9931234567',
            'status' => 'active',
        ]);

        $sale = $this->makeSale();

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]), ['phone' => '9931234567'])
            ->assertOk()
            ->assertJsonPath('customer.id', $customer->id)
            ->assertJsonPath('customer_created', false);

        $this->assertSame($customer->id, $sale->fresh()->customer_id);
        $this->assertSame(1, Customer::where('branch_id', $this->branch->id)->count());
    }

    public function test_telefono_nuevo_crea_cliente_sin_nombre_y_lo_asocia(): void
    {
        $sale = $this->makeSale();

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]), ['phone' => '9939999999'])
            ->assertOk()
            ->assertJsonPath('customer_created', true)
            ->assertJsonPath('customer.name_pending', true);

        $customer = $sale->fresh()->customer;
        $this->assertNotNull($customer);
        $this->assertSame('+529939999999', $customer->phone);
        $this->assertSame('Cliente 993 999 9999', $customer->name);
    }

    public function test_no_duplica_cliente_al_capturar_el_mismo_numero_dos_veces(): void
    {
        $first = $this->makeSale();
        $second = $this->makeSale();

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $first->id]), ['phone' => '9939999999'])
            ->assertOk();

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $second->id]), ['phone' => '993 999 9999'])
            ->assertOk();

        $this->assertSame(1, Customer::where('branch_id', $this->branch->id)->count());
        $this->assertSame($first->fresh()->customer_id, $second->fresh()->customer_id);
    }

    public function test_devuelve_el_link_de_whatsapp_al_asociar(): void
    {
        $sale = $this->makeSale();

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]), ['phone' => '9939999999'])
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('url', fn (?string $url) => is_string($url) && str_contains($url, 'wa.me/529939999999'));
    }

    public function test_rechaza_telefono_invalido(): void
    {
        $sale = $this->makeSale();

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]), ['phone' => '123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->assertSame(0, Customer::where('branch_id', $this->branch->id)->count());
    }

    public function test_venta_de_otra_sucursal_es_rechazada(): void
    {
        // Sucursal real: `sales.branch_id` tiene FK, un id inventado no se puede insertar.
        $sale = $this->makeSale(['branch_id' => $this->secondBranch->id]);

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]), ['phone' => '9939999999'])
            ->assertForbidden();
    }

    private function makeSale(array $attrs = []): Sale
    {
        return Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'V-'.uniqid(),
            'total' => 250, 'amount_paid' => 0, 'amount_pending' => 250,
            'origin' => 'admin', 'status' => SaleStatus::Active,
        ], $attrs));
    }
}
```

- [x] **Step 2: Correr para verificar que falla**

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Ventas/CapturePhoneCreatesCustomerTest.php`
Expected: FAIL — hoy el endpoint guarda `contact_phone` y no crea cliente.

- [x] **Step 3: Implementar el trait compartido**

Crear `app/Http/Controllers/Concerns/HandlesSalePhoneCapture.php`:

```php
<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Sale;
use App\Services\AssignCustomerToSale;
use App\Services\Customers\CustomerAssignmentPreview;
use App\Services\Customers\ResolveCustomerByPhone;
use App\Services\WhatsappMessageService;

/**
 * Captura de teléfono desde una venta: resuelve el cliente de la sucursal
 * (creándolo sin nombre si hace falta) y lo asocia a la venta.
 *
 * Compartido por Caja, Sucursal y el hub, que exponen el mismo endpoint.
 *
 * Política: la asignación es automática, salvo que cambie el total de la venta
 * o la deje completada — ahí se devuelve `requires_confirmation` con el detalle
 * del impacto para que el usuario decida. Capturar un teléfono nunca debe
 * alterar en silencio una venta ya cobrada.
 */
trait HandlesSalePhoneCapture
{
    /**
     * @return array<string, mixed>
     */
    protected function capturePhoneForSale(
        Sale $sale,
        string $phone,
        bool $confirmed,
        WhatsappMessageService $whatsapp,
        ResolveCustomerByPhone $resolver,
        AssignCustomerToSale $assigner,
    ): array {
        $resolution = $resolver->execute($phone, $sale->branch_id, $sale->tenant_id);
        $customer = $resolution->customer;

        $customerPayload = [
            'id' => $customer->id,
            'name' => $customer->name,
            'name_pending' => (bool) $customer->name_pending,
            'phone' => $customer->phone,
        ];

        // Ya asignado a este mismo cliente: nada que hacer más que devolver el link.
        if ($sale->customer_id === $customer->id) {
            return [
                ...$whatsapp->linkForSale($sale->fresh()),
                'customer' => $customerPayload,
                'customer_created' => $resolution->wasCreated,
            ];
        }

        $preview = CustomerAssignmentPreview::for($sale, $customer);
        $needsConfirmation = $preview['changes_total'] || $preview['would_complete'];

        if ($needsConfirmation && ! $confirmed) {
            return [
                'requires_confirmation' => true,
                'customer' => $customerPayload,
                'customer_created' => $resolution->wasCreated,
                'preview' => $preview,
            ];
        }

        $assigner->execute($sale, $customer->id, $sale->branch_id);

        return [
            ...$whatsapp->linkForSale($sale->fresh()),
            'customer' => $customerPayload,
            'customer_created' => $resolution->wasCreated,
            'preview' => $preview,
        ];
    }
}
```

- [x] **Step 4: Conectar el endpoint de Caja**

En `app/Http/Controllers/Caja/WorkbenchController.php`, añadir el trait a la clase (`use HandlesSalePhoneCapture;` junto a los demás traits) y los imports:

```php
use App\Http\Controllers\Concerns\HandlesSalePhoneCapture;
use App\Services\AssignCustomerToSale;
use App\Services\Customers\ResolveCustomerByPhone;
```

Reemplazar el cuerpo de `storeWhatsappPhone()`:

```php
    /**
     * Captura un teléfono en la venta. Resuelve el cliente de la sucursal
     * (creándolo sin nombre si el número es nuevo) y lo asocia a la venta.
     */
    public function storeWhatsappPhone(
        Request $request,
        Sale $sale,
        WhatsappMessageService $whatsappService,
        ResolveCustomerByPhone $resolver,
        AssignCustomerToSale $assigner,
    ): JsonResponse {
        $user = Auth::user();

        if ($sale->branch_id !== $user->branch_id) {
            abort(403, 'Esta venta no pertenece a tu sucursal.');
        }
        if ($sale->tenant_id !== $user->tenant_id) {
            abort(403, 'Esta venta no pertenece a tu empresa.');
        }

        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\d{10}$/'],
            'confirmed' => ['nullable', 'boolean'],
        ], [
            'phone.regex' => 'El teléfono debe tener 10 dígitos.',
            'phone.required' => 'Ingresa un teléfono.',
        ]);

        return response()->json($this->capturePhoneForSale(
            $sale,
            $validated['phone'],
            (bool) ($validated['confirmed'] ?? false),
            $whatsappService,
            $resolver,
            $assigner,
        ));
    }
```

- [x] **Step 5: Replicar en Sucursal y en el hub**

En `app/Http/Controllers/Sucursal/WorkbenchController.php` aplicar **exactamente** el mismo cambio (mismos imports, mismo `use HandlesSalePhoneCapture;`, mismo cuerpo de `storeWhatsappPhone`).

En `app/Http/Controllers/Api/Hub/SaleController.php`, el método usa `$found` en vez de `$sale` y ya resuelve la venta con sus propias guardas. Reemplazar el cuerpo por:

```php
        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\d{10}$/'],
            'confirmed' => ['nullable', 'boolean'],
        ], [
            'phone.regex' => 'El teléfono debe tener 10 dígitos.',
            'phone.required' => 'Ingresa un teléfono.',
        ]);

        return response()->json($this->capturePhoneForSale(
            $found,
            $validated['phone'],
            (bool) ($validated['confirmed'] ?? false),
            $whatsappService,
            $resolver,
            $assigner,
        ));
```

añadiendo `use HandlesSalePhoneCapture;` a la clase y los mismos tres parámetros inyectados a la firma del método.

- [x] **Step 6: Correr los tests**

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Ventas/CapturePhoneCreatesCustomerTest.php`
Expected: PASS (6 tests).

- [x] **Step 7: Test de la confirmación**

Añadir a `tests/Feature/Ventas/CapturePhoneCreatesCustomerTest.php`:

```php
    public function test_pide_confirmacion_cuando_el_cliente_tiene_precio_preferencial(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Juan VIP',
            'phone' => '9931234567',
            'status' => 'active',
        ]);

        // Producto de peso: el preferencial solo aplica a líneas kg/l.
        $product = $this->makeProduct(['unit_type' => 'kg', 'price' => 100]);

        \App\Models\CustomerProductPrice::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'price' => 80,
        ]);

        $sale = $this->makeSale(['total' => 200, 'amount_pending' => 200]);
        $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2,
            'quantity_unit' => 'kg',
            'unit_type' => 'kg',
            'unit_price' => 100,
            'original_unit_price' => 100,
            'subtotal' => 200,
        ]);

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]), ['phone' => '9931234567'])
            ->assertOk()
            ->assertJsonPath('requires_confirmation', true)
            ->assertJsonPath('preview.changes_total', true);

        // Sin confirmar, la venta no se tocó.
        $this->assertNull($sale->fresh()->customer_id);

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]), [
                'phone' => '9931234567',
                'confirmed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('available', true);

        $this->assertSame($customer->id, $sale->fresh()->customer_id);
    }
```

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Ventas/CapturePhoneCreatesCustomerTest.php`
Expected: PASS (7 tests).

- [x] **Step 8: Verificar que no rompimos los tests existentes de WhatsApp**

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Caja/WhatsappLinkTest.php tests/Feature/Sucursal/WhatsappLinkTest.php tests/Feature/Sucursal/AssignCustomerClearsContactPhoneTest.php tests/Feature/Api/Hub/SaleApiTest.php`

Expected: **algunos fallarán a propósito.** `test_store_phone_saves_normalized_phone_and_returns_link` asertaba que el número quedaba en `sales.contact_phone`; ahora vive en el cliente asignado y `AssignCustomerToSale` limpia `contact_phone`. Actualizar esos tests para asertar el nuevo comportamiento (cliente asignado con ese teléfono), **no** revertir la lógica. Dejar constancia del cambio en el mensaje del commit.

- [x] **Step 9: Commit**

```bash
./vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/ tests/Feature/
git commit -m "feat(ventas): capturar telefono resuelve y asocia cliente en los 3 canales

Los tests de WhatsappLink que asertaban contact_phone se actualizan: el
telefono ahora vive en el cliente asignado y AssignCustomerToSale limpia
contact_phone en ventas POS."
```

---

### Task 7: Frontend — confirmación y clientes sin nombre

**Files:**
- Create: `resources/js/Components/CustomerAssignConfirmDialog.vue`
- Create: `resources/js/Components/CustomerNameDialog.vue`
- Modify: `resources/js/composables/useWhatsappSend.js`
- Modify: `resources/js/Components/SaleWhatsappPhoneChip.vue`
- Modify: `resources/js/Components/Caja/SaleDetail.vue:104-110`
- Modify: `resources/js/Components/Sucursal/SaleDetail.vue:157-163`

**Interfaces:**
- Consumes: el contrato JSON de la Tarea 6 (`requires_confirmation`, `customer`, `preview`).
- Produces: `useWhatsappSend` expone `assignConfirmDialog` y `confirmAssign()`.

- [x] **Step 1: Manejar `requires_confirmation` en el composable**

En `resources/js/composables/useWhatsappSend.js`, añadir junto a los otros diálogos:

```js
    const assignConfirmDialog = ref({ show: false, customer: null, preview: null, phone: null, sendAfter: false });
```

En `submitPhone`, después de `const data = await res.json();` y **antes** de cerrar el capture dialog, interceptar:

```js
            if (data.requires_confirmation) {
                closePopup(popup);
                captureDialog.value = { ...captureDialog.value, show: false };
                assignConfirmDialog.value = {
                    show: true,
                    customer: data.customer,
                    preview: data.preview,
                    phone,
                    sendAfter: wantsSend,
                };
                return { ok: false, pending: true };
            }
```

Añadir la acción de confirmación, que reenvía con `confirmed: true`:

```js
    const confirmAssign = async () => {
        const { phone, sendAfter } = assignConfirmDialog.value;
        assignConfirmDialog.value = { ...assignConfirmDialog.value, show: false };
        captureDialog.value = { ...captureDialog.value, sendAfter };
        return submitPhone(phone, true);
    };
```

Cambiar la firma de `submitPhone` a `async (phone, confirmed = false)` y el body del fetch a:

```js
                body: JSON.stringify({ phone, confirmed }),
```

Exportar en el return: `assignConfirmDialog, confirmAssign,`.

- [x] **Step 2: Crear el diálogo de confirmación**

Crear `resources/js/Components/CustomerAssignConfirmDialog.vue`:

```vue
<script setup>
import Modal from '@/Components/Modal.vue';
import { computed } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
    customer: { type: Object, default: null },
    preview: { type: Object, default: null },
});

const emit = defineEmits(['close', 'confirm']);

const money = (n) => `$${Number(n ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const changesTotal = computed(() => props.preview?.changes_total === true);
const wouldComplete = computed(() => props.preview?.would_complete === true);
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <div class="p-6">
            <h3 class="text-base font-bold text-gray-900">Este número ya es de un cliente</h3>
            <p class="mt-1 text-sm text-gray-500">
                <span class="font-semibold text-gray-700">{{ customer?.name }}</span> tiene este teléfono registrado.
                Asociarlo a la venta cambiará lo siguiente:
            </p>

            <div class="mt-4 space-y-2 rounded-xl bg-amber-50 p-4 ring-1 ring-amber-200">
                <div v-if="changesTotal" class="flex items-baseline justify-between text-sm">
                    <span class="text-amber-900">Total de la venta</span>
                    <span class="font-mono font-bold tabular-nums text-amber-900">
                        {{ money(preview.current_total) }} → {{ money(preview.new_total) }}
                    </span>
                </div>
                <p v-if="changesTotal" class="text-xs text-amber-700">
                    El cliente tiene precios preferenciales que aplican a esta venta.
                </p>
                <p v-if="wouldComplete" class="text-xs font-semibold text-amber-800">
                    La venta quedará marcada como cobrada.
                </p>
                <p v-if="preview?.skipped_piece_presentations?.length" class="text-xs text-amber-700">
                    Sin precio preferencial (presentaciones por pieza):
                    {{ preview.skipped_piece_presentations.join(', ') }}.
                </p>
            </div>

            <div class="mt-6 flex justify-end gap-2">
                <button type="button" @click="emit('close')" :disabled="saving"
                    class="rounded-lg px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-100 disabled:opacity-50">
                    Cancelar
                </button>
                <button type="button" @click="emit('confirm')" :disabled="saving"
                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50">
                    {{ saving ? 'Asociando…' : 'Asociar cliente' }}
                </button>
            </div>
        </div>
    </Modal>
</template>
```

- [x] **Step 3: Montar el diálogo donde ya se usan los otros**

En `resources/js/Components/Caja/SaleDetail.vue` y `resources/js/Components/Sucursal/SaleDetail.vue`, localizar dónde se renderiza `WhatsappPhoneDialog` (`grep -n "WhatsappPhoneDialog" resources/js/Components/{Caja,Sucursal}/SaleDetail.vue`) y añadir junto a él:

```vue
        <CustomerAssignConfirmDialog
            :show="assignConfirmDialog.show"
            :saving="savingPhone"
            :customer="assignConfirmDialog.customer"
            :preview="assignConfirmDialog.preview"
            @close="assignConfirmDialog.show = false"
            @confirm="confirmAssign"
        />
```

importando el componente y desestructurando `assignConfirmDialog, confirmAssign` del composable.

- [x] **Step 4: Chip — mostrar el cliente sin nombre y permitir completarlo**

En `resources/js/Components/SaleWhatsappPhoneChip.vue`, añadir la prop `namePending` y, en la rama `source === 'customer'`, sustituir el nombre por un chip accionable cuando falte:

```vue
<script setup>
const props = defineProps({
    phone: { type: String, default: null },
    source: { type: String, default: null },
    customerName: { type: String, default: null },
    namePending: { type: Boolean, default: false },
});

const emit = defineEmits(['edit', 'remove', 'add', 'name']);
</script>
```

Y en el template, reemplazar la línea del nombre del cliente:

```vue
            <button v-if="source === 'customer' && namePending" type="button" @click="emit('name')"
                class="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-700 ring-1 ring-inset ring-amber-600/20 hover:bg-amber-100">
                Poner nombre
            </button>
            <span v-else-if="source === 'customer' && customerName" class="max-w-[160px] truncate text-[11px] text-gray-500">{{ customerName }}</span>
```

En `useWhatsappSend.js`, exponer el dato en `phoneInfo`:

```js
        if (s.customer?.phone) {
            return {
                phone: s.customer.phone,
                source: 'customer',
                customerName: s.customer.name,
                namePending: !!s.customer.name_pending,
            };
        }
```

**Verificar** que `name_pending` viaje al frontend: revisar `app/Http/Resources/SaleResource.php` y los `select` de `customer:id,name,phone` en `Caja/WorkbenchController.php:376` y `Sucursal/WorkbenchController.php:685`, añadiendo `name_pending` a cada lista de columnas. Sin esto la prop llega siempre `false`.

- [x] **Step 5: Diálogo para completar el nombre**

Crear `resources/js/Components/CustomerNameDialog.vue`:

```vue
<script setup>
import Modal from '@/Components/Modal.vue';
import { ref, watch, nextTick } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
    phone: { type: String, default: '' },
});

const emit = defineEmits(['close', 'submit']);

const name = ref('');
const error = ref(null);
const inputRef = ref(null);

watch(() => props.show, (open) => {
    if (open) {
        name.value = '';
        error.value = null;
        nextTick(() => inputRef.value?.focus());
    }
});

const onSubmit = () => {
    const value = name.value.trim();
    if (value.length < 2) {
        error.value = 'Escribe el nombre del cliente.';
        return;
    }
    emit('submit', value);
};
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <form @submit.prevent="onSubmit" class="p-6">
            <h3 class="text-base font-bold text-gray-900">¿Cómo se llama el cliente?</h3>
            <p class="mt-1 text-sm text-gray-500">
                Este cliente se creó automáticamente con el teléfono
                <span class="font-mono font-semibold text-gray-700">{{ phone }}</span>.
            </p>

            <div class="mt-4">
                <label for="customer-name" class="block text-sm font-medium text-gray-700">Nombre</label>
                <input id="customer-name" ref="inputRef" v-model="name" type="text" maxlength="255"
                    placeholder="Juan Pérez"
                    class="mt-1 w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />
                <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
            </div>

            <div class="mt-6 flex justify-end gap-2">
                <button type="button" @click="emit('close')" :disabled="saving"
                    class="rounded-lg px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-100 disabled:opacity-50">
                    Ahora no
                </button>
                <button type="submit" :disabled="saving"
                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50">
                    {{ saving ? 'Guardando…' : 'Guardar nombre' }}
                </button>
            </div>
        </form>
    </Modal>
</template>
```

Montarlo en ambos `SaleDetail.vue`, conectado al evento `name` del chip:

```js
const nameDialog = ref({ show: false });
const savingName = ref(false);

const submitCustomerName = (name) => {
    savingName.value = true;
    router.put(
        route('caja.clientes.update', [props.tenantSlug, props.sale.customer.id]),
        { name, phone: props.sale.customer.phone, notes: props.sale.customer.notes ?? null },
        {
            preserveScroll: true,
            onSuccess: () => { nameDialog.value.show = false; emit('mutated'); },
            onFinish: () => { savingName.value = false; },
        },
    );
};
```

**En `Sucursal/SaleDetail.vue` la ruta es `sucursal.clientes.update`** — verificar el nombre exacto con `./vendor/bin/sail artisan route:list --name=clientes.update`.

**Ojo con la validación:** `HandlesCustomers::update()` exige `name` **y** `phone`; por eso se reenvía el teléfono actual del cliente. Y hay que apagar el flag — añadir en `HandlesCustomers::update()`, tras `$validated = $request->validate($rules);`:

```php
        if (($validated['name'] ?? '') !== '') {
            $validated['name_pending'] = false;
        }
```

**Permisos:** el cajero puede editar clientes solo si la sucursal tiene activo `cashier_customers_enabled` (middleware `branch.feature`). Si el flag está apagado, la ruta `caja.clientes.update` no existe para ese usuario y el botón "Poner nombre" debe ocultarse. Pasar el flag como prop desde `Caja/WorkbenchController` (`$branch->cashier_customers_enabled`) y condicionar el botón con `v-if`.

- [x] **Step 6: Buscador de clientes tolerante al formato**

En `Caja/SaleDetail.vue:104-110` y `Sucursal/SaleDetail.vue:157-163`, el filtro `c.phone.includes(q)` falla si el usuario teclea `9931234567` y el cliente está guardado como `+529931234567`. Reemplazar el computed en ambos archivos:

```js
const onlyDigits = (v) => String(v ?? '').replace(/\D/g, '');

const filteredCustomers = computed(() => {
    if (!customerQuery.value) {
        return (props.customers || []).slice(0, 5);
    }
    const q = customerQuery.value.toLowerCase();
    const qDigits = onlyDigits(customerQuery.value);
    return (props.customers || []).filter((c) => {
        const byName = (c.name || '').toLowerCase().includes(q);
        const byPhone = qDigits.length >= 3 && onlyDigits(c.phone).includes(qDigits);
        return byName || byPhone;
    }).slice(0, 5);
});
```

Esto además blinda contra `c.name` vacío, que hoy lanzaría `TypeError`.

- [x] **Step 7: Compilar y probar a mano**

Run: `npm run build`
Expected: build sin errores.

Prueba manual en mesa de trabajo (Caja y Sucursal):
1. Venta sin cliente → chip "Agregar" → teclear un número nuevo → debe quedar cliente asignado con badge "Cliente" y chip "Poner nombre".
2. Repetir con el mismo número en otra venta → mismo cliente, sin duplicado.
3. Con un cliente que tenga precio preferencial → debe aparecer el diálogo de confirmación con el cambio de total.
4. Buscar ese cliente en "Asignar cliente" tecleando los 10 dígitos → debe encontrarlo.

- [x] **Step 8: Commit**

```bash
./vendor/bin/sail bin pint --dirty --format agent
git add resources/js/ app/Http/
git commit -m "feat(ventas): UI de confirmacion de cliente y clientes sin nombre"
```

---

### Task 8: Alinear pedido web y asistente IA con el servicio

Elimina el `firstOrCreate` divergente del canal web y el `Customer::create` sin dedup del asistente.

**Files:**
- Modify: `app/Http/Controllers/Public/OrderController.php:228-231`
- Modify: `app/Services/Ai/Assistant/Drafts/Confirmers/CustomerDraftConfirmer.php:74-81`
- Test: `tests/Feature/Ventas/WebOrderReusesCustomerTest.php` (crear)

**Interfaces:**
- Consumes: `ResolveCustomerByPhone::execute()`.

- [x] **Step 1: Escribir el test que falla**

**No existe ningún test que POSTee al endpoint público de pedidos** — los tests de web orders crean las ventas directo con `Sale::create(['origin' => 'web'])`. Hay que escribir el payload desde cero; el que sigue satisface las reglas de `OrderController::store()` (líneas 31-46).

Requisitos que la sucursal y el producto deben cumplir o el endpoint responde 422:
- `branch.online_ordering_enabled = true` y `branch.pickup_enabled = true`
- `branch.hours = null` → `isOpenNow()` devuelve `true` sin importar la hora (evita un test que falle de madrugada)
- `branch.min_order_amount = null`
- producto `status = 'active'` **y** `visible_online = true`

Crear `tests/Feature/Ventas/WebOrderReusesCustomerTest.php`:

```php
<?php

namespace Tests\Feature\Ventas;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class WebOrderReusesCustomerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        $this->branch->update([
            'online_ordering_enabled' => true,
            'pickup_enabled' => true,
            'hours' => null,
            'min_order_amount' => null,
        ]);

        $this->product = $this->makeProduct(['visible_online' => true, 'price' => 100]);
    }

    public function test_pedido_web_reutiliza_el_cliente_dado_de_alta_a_mano(): void
    {
        $existing = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Juan Perez',
            'phone' => '993 123 4567',   // formato humano, como lo captura el mostrador
            'status' => 'active',
        ]);

        $this->postJson($this->orderUrl(), $this->payload([
            'contact_name' => 'Juan P',
            'contact_phone' => '9931234567',
        ]))->assertCreated();

        // Antes de este plan aquí se creaba un SEGUNDO cliente.
        $this->assertSame(1, Customer::where('branch_id', $this->branch->id)->count());
        $this->assertSame(
            $existing->id,
            Sale::withoutGlobalScopes()->where('origin', 'web')->latest('id')->first()->customer_id,
        );
    }

    public function test_pedido_web_conserva_el_nombre_capturado_en_el_checkout(): void
    {
        $this->postJson($this->orderUrl(), $this->payload([
            'contact_name' => 'Cliente Nuevo',
            'contact_phone' => '9939999999',
        ]))->assertCreated();

        $customer = Customer::where('phone', '+529939999999')->first();
        $this->assertNotNull($customer);
        $this->assertSame('Cliente Nuevo', $customer->name);
        $this->assertFalse($customer->name_pending);
    }

    private function orderUrl(): string
    {
        return "/api/public/{$this->tenant->slug}/branches/{$this->branch->id}/orders";
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
            'delivery_type' => 'pickup',
            'contact_name' => 'Cliente',
            'contact_phone' => '9939999999',
            'payment_method' => 'cash',
        ], $overrides);
    }
}
```

**Verificar la URL exacta** antes de correr: `./vendor/bin/sail artisan route:list --path=api/public --method=POST`. Si el prefijo difiere, ajustar `orderUrl()`.

**Feature flag:** la ruta solo se registra si `config('features.web_orders')` está activo. Hoy lo está en el entorno de test (`WebOrdersFeatureFlagEnabledTest` lo da por hecho). Si el test devuelve 404 en vez de 201, esa es la causa — revisar `config/features.php` y `.env.testing`.

- [x] **Step 2: Correr para verificar que falla**

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Ventas/WebOrderReusesCustomerTest.php`
Expected: FAIL en el primero — hoy se crean dos clientes.

- [x] **Step 3: Sustituir el `firstOrCreate` del canal web**

En `app/Http/Controllers/Public/OrderController.php`, dentro de la transacción, reemplazar:

```php
            $customer = Customer::firstOrCreate(
                ['branch_id' => $branchModel->id, 'phone' => $contactPhone],
                ['name' => $validated['contact_name']]
            );
```

por:

```php
            // El checkout sí trae nombre: si el cliente es nuevo, se guarda ese
            // nombre en vez del placeholder; si ya existía, no se pisa el suyo.
            $resolution = $resolver->execute($contactPhone, $branchModel->id, $tenant->id);
            $customer = $resolution->customer;

            if ($resolution->wasCreated) {
                $customer->update([
                    'name' => $validated['contact_name'],
                    'name_pending' => false,
                ]);
            }
```

Inyectar `ResolveCustomerByPhone $resolver` en la firma del método del controlador, añadirlo al `use (...)` del closure de la transacción, e importar la clase.

- [x] **Step 4: Alinear el asistente IA**

En `app/Services/Ai/Assistant/Drafts/Confirmers/CustomerDraftConfirmer.php`, `Customer::create()` choca contra el índice único si el teléfono ya existe, lanzando `QueryException` sin manejar. Reemplazar el bloque de creación:

```php
        $phone = PhoneNormalizer::normalize($validated['phone'] ?? null);

        if ($phone !== null) {
            $existing = Customer::withoutGlobalScopes()
                ->where('tenant_id', app('tenant')->id)
                ->where('branch_id', $branchId)
                ->where('phone', $phone)
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'phone' => 'Ya existe un cliente con ese teléfono en la sucursal: '.$existing->name.'.',
                ]);
            }
        }

        $customer = Customer::create([
            'tenant_id' => app('tenant')->id,
            'branch_id' => $branchId,
            'name' => $validated['name'],
            'phone' => $phone,
            'notes' => $validated['notes'] ?? null,
            'status' => 'active',
        ]);
```

importando `App\Services\PhoneNormalizer` e `Illuminate\Validation\ValidationException`.

- [x] **Step 5: Correr los tests**

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Ventas/WebOrderReusesCustomerTest.php tests/Feature/Ai/AssistantCustomerDraftConfirmTest.php`
Expected: PASS.

- [x] **Step 6: Commit**

```bash
./vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Public/OrderController.php app/Services/Ai/ tests/Feature/Ventas/WebOrderReusesCustomerTest.php
git commit -m "fix(clientes): pedido web y asistente IA usan el resolvedor comun (elimina duplicados)"
```

---

### Task 9: Recuperar los teléfonos históricos

**Files:**
- Create: `app/Console/Commands/LinkOrphanSalePhones.php`
- Test: `tests/Feature/Console/LinkOrphanSalePhonesTest.php` (crear)

**Interfaces:**
- Consumes: `ResolveCustomerByPhone::execute()`.
- Produces: comando `sales:link-orphan-phones {--dry-run=true} {--branch=}`.

- [x] **Step 1: Escribir el test que falla**

Crear `tests/Feature/Console/LinkOrphanSalePhonesTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class LinkOrphanSalePhonesTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_dry_run_no_crea_ni_asocia_nada(): void
    {
        $sale = $this->orphanSale('+529939999999');

        $this->artisan('sales:link-orphan-phones', ['--dry-run' => 'true'])->assertSuccessful();

        $this->assertNull($sale->fresh()->customer_id);
        $this->assertSame(0, Customer::where('branch_id', $this->branch->id)->count());
    }

    public function test_asocia_la_venta_al_cliente_existente(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Juan',
            'phone' => '9931234567',
            'status' => 'active',
        ]);

        $sale = $this->orphanSale('+529931234567');

        $this->artisan('sales:link-orphan-phones', ['--dry-run' => 'false'])->assertSuccessful();

        $this->assertSame($customer->id, $sale->fresh()->customer_id);
        $this->assertSame(1, Customer::where('branch_id', $this->branch->id)->count());
    }

    public function test_crea_cliente_sin_nombre_para_telefonos_nuevos(): void
    {
        $sale = $this->orphanSale('+529939999999');

        $this->artisan('sales:link-orphan-phones', ['--dry-run' => 'false'])->assertSuccessful();

        $customer = $sale->fresh()->customer;
        $this->assertNotNull($customer);
        $this->assertTrue($customer->name_pending);
    }

    public function test_omite_ventas_canceladas(): void
    {
        $sale = $this->orphanSale('+529939999999', ['status' => SaleStatus::Cancelled]);

        $this->artisan('sales:link-orphan-phones', ['--dry-run' => 'false'])->assertSuccessful();

        $this->assertNull($sale->fresh()->customer_id);
    }

    public function test_no_toca_ventas_que_ya_tienen_cliente(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Otro',
            'phone' => '9938888888',
            'status' => 'active',
        ]);

        $sale = $this->orphanSale('+529939999999', ['customer_id' => $customer->id]);

        $this->artisan('sales:link-orphan-phones', ['--dry-run' => 'false'])->assertSuccessful();

        $this->assertSame($customer->id, $sale->fresh()->customer_id);
    }

    private function orphanSale(string $phone, array $attrs = []): Sale
    {
        return Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'V-'.uniqid(),
            'total' => 100, 'amount_paid' => 0, 'amount_pending' => 100,
            'origin' => 'admin', 'status' => SaleStatus::Active,
            'contact_phone' => $phone,
        ], $attrs));
    }
}
```

- [x] **Step 2: Correr para verificar que falla**

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Console/LinkOrphanSalePhonesTest.php`
Expected: FAIL — comando no definido.

- [x] **Step 3: Implementar**

Crear `app/Console/Commands/LinkOrphanSalePhones.php`:

```php
<?php

namespace App\Console\Commands;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Services\Customers\ResolveCustomerByPhone;
use Illuminate\Console\Command;

/**
 * Recupera los teléfonos que quedaron guardados en `sales.contact_phone` sin
 * cliente asociado, de cuando capturar un teléfono no creaba cliente.
 *
 * NO usa `AssignCustomerToSale`: ese servicio recalcula precios preferenciales
 * y podría alterar el total de ventas históricas ya cobradas. Aquí solo se
 * rellena `customer_id`, dejando intactos importes y estado.
 */
class LinkOrphanSalePhones extends Command
{
    protected $signature = 'sales:link-orphan-phones
                            {--dry-run=true : Reporta sin modificar datos}
                            {--branch= : Limitar a una sucursal}';

    protected $description = 'Asocia a un cliente las ventas con contact_phone huérfano';

    public function handle(ResolveCustomerByPhone $resolver): int
    {
        $dryRun = filter_var($this->option('dry-run'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        $this->info($dryRun ? '=== DRY RUN — no se escribe nada ===' : '=== APLICANDO CAMBIOS ===');

        $sales = Sale::withoutGlobalScopes()
            ->whereNull('customer_id')
            ->whereNotNull('contact_phone')
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->whereNull('deleted_at')
            ->when($this->option('branch'), fn ($q, $b) => $q->where('branch_id', (int) $b))
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'branch_id', 'contact_phone', 'folio']);

        if ($sales->isEmpty()) {
            $this->info('No hay ventas con teléfono huérfano.');

            return self::SUCCESS;
        }

        $this->info("{$sales->count()} ventas con teléfono huérfano.");

        $linked = 0;
        $created = 0;
        $failed = 0;

        foreach ($sales as $sale) {
            if ($dryRun) {
                $this->line("  {$sale->folio} → {$sale->contact_phone}");
                $linked++;

                continue;
            }

            try {
                $resolution = $resolver->execute($sale->contact_phone, $sale->branch_id, $sale->tenant_id);

                // Solo se rellena el cliente: importes y estado quedan intactos.
                Sale::withoutGlobalScopes()
                    ->where('id', $sale->id)
                    ->update(['customer_id' => $resolution->customer->id]);

                $linked++;
                $created += $resolution->wasCreated ? 1 : 0;
            } catch (\InvalidArgumentException $e) {
                $this->warn("  {$sale->folio}: teléfono ilegible ('{$sale->contact_phone}') — se omite.");
                $failed++;
            }
        }

        $this->info($dryRun
            ? "Dry run: {$linked} ventas se asociarían. Re-ejecuta con --dry-run=false."
            : "Listo. {$linked} ventas asociadas, {$created} clientes creados, {$failed} omitidas.");

        return self::SUCCESS;
    }
}
```

- [x] **Step 4: Correr los tests**

Run: `./vendor/bin/sail artisan test --compact tests/Feature/Console/LinkOrphanSalePhonesTest.php`
Expected: PASS (5 tests).

- [x] **Step 5: Commit**

```bash
./vendor/bin/sail bin pint --dirty --format agent
git add app/Console/Commands/LinkOrphanSalePhones.php tests/Feature/Console/LinkOrphanSalePhonesTest.php
git commit -m "feat(clientes): comando para recuperar telefonos historicos de ventas"
```

---

### Task 10: Suite completa, documentación y despliegue

**Files:**
- Modify: `docs/modulos/clientes-caja.md`, `docs/modulos/ventas.md`, `docs/modulos/pedidos-web.md`
- Modify: `docs/api/hub.md`
- Modify: `docs/README.md`

- [x] **Step 1: Correr la suite completa**

Run: `./vendor/bin/sail composer run test`
Expected: PASS. Si algo falla, arreglarlo antes de seguir — no documentar sobre una suite roja.

- [x] **Step 2: Actualizar las docs vivas**

En `docs/modulos/clientes-caja.md` documentar: teléfono canónico en E.164, `name_pending`, creación automática desde venta, unicidad por sucursal y los dos comandos nuevos.

En `docs/modulos/ventas.md` documentar el nuevo flujo de captura de teléfono, incluyendo la regla de confirmación (solo si cambia el total o completa la venta) y que `contact_phone` deja de ser el destino de la captura en ventas POS.

En `docs/modulos/pedidos-web.md` corregir la sección de creación de cliente: ahora pasa por `ResolveCustomerByPhone`.

En `docs/api/hub.md` documentar el nuevo parámetro `confirmed` y el contrato de respuesta con `requires_confirmation`.

- [x] **Step 3: Nota de despliegue**

Añadir al final del plan (este archivo) y al doc de clientes el orden obligatorio en producción:

```bash
# 1. Diagnóstico, sin tocar nada
php artisan customers:normalize-phones --dry-run=true

# 2. Normalizar y fusionar duplicados por formato (ANTES de migrar)
php artisan customers:normalize-phones --dry-run=false

# 3. Desplegar el código (incluye la migración de name_pending)

# 4. Opcional: recuperar el histórico, primero en seco
php artisan sales:link-orphan-phones --dry-run=true
php artisan sales:link-orphan-phones --dry-run=false
```

**El paso 2 debe correr antes de que el código nuevo esté sirviendo tráfico.** Si el mutator entra primero, la primera edición de un cliente cuyo teléfono normalizado colisiona con otro existente falla con violación del índice único.

- [x] **Step 4: Atlas**

El manifiesto `resources/js/Features/Architecture/data/system-architecture.json` **no existe en esta rama**. Si para cuando se ejecute este plan ya existe, actualizarlo (tablas, servicios, endpoints) y correr `npm run validate:architecture` y `npm run test:architecture`. Si no existe, omitir sin más.

- [x] **Step 5: Commit final**

```bash
git add docs/
git commit -m "docs(clientes): flujo de telefonos normalizados y creacion automatica desde ventas"
```

---

## Riesgos y decisiones abiertas

| Riesgo | Mitigación |
|---|---|
| **El mutator rompe tests que comparan teléfonos crudos** | Es el punto más probable de fricción. Los tests deben actualizarse a E.164, nunca debilitar el mutator. Task 3 Step 8 lo cubre explícitamente. |
| **La normalización en producción colisiona con el índice único** | `customers:normalize-phones` corre *antes* del deploy y fusiona los duplicados. El dry-run permite ver el alcance primero. |
| **La fusión de clientes es irreversible** | Hacer respaldo de `customers`, `sales.customer_id` y `customer_product_prices` antes del paso 2. El comando reasigna ventas y precios, pero un merge equivocado no se deshace solo. |
| **Precios preferenciales duplicados tras fusionar** | El comando lo reporta; la resolución es manual. Poco frecuente (requiere que ambos duplicados tuvieran precio del mismo producto). |
| **Crecimiento de la cartera con clientes sin nombre** | `name_pending` permite filtrarlos y curarlos. Vale la pena revisar en un mes cuántos quedaron sin nombre; si son muchos, considerar pedir el nombre en el diálogo de captura. |
| **`customers` precargado completo en la mesa de trabajo** | `WorkbenchController:62` manda todos los clientes activos en cada render. Con creación automática la cartera crecerá más rápido y ese payload también. **No lo resuelve este plan** — si empieza a doler, convertir la búsqueda en un endpoint server-side. |
| **Ventas web y `contact_phone`** | Se conserva intacto: es parte del registro del pedido y `AssignCustomerToSale` ya lo respeta para `origin='web'`. |
| **Cliente creado que queda sin venta si se cancela la confirmación** | `ResolveCustomerByPhone` crea el cliente *antes* de evaluar si hace falta confirmar. Solo ocurre en un caso raro (venta ya pagada pero aún no `Completed`, con teléfono nuevo): si el usuario cancela, queda un cliente sin ventas. Es inocuo y el número sigue siendo real. Si molesta, mover la creación a después de la confirmación — a costa de tener que resolver dos veces. |

**Dos cosas que este plan deliberadamente NO cambia**, para no ampliar el alcance:

1. **La UI sigue sin permitir cambiar el teléfono de una venta con cliente asignado.** El chip solo ofrece editar/quitar en fuente `manual`. Cambiar el teléfono de un cliente sigue siendo cosa del módulo de Clientes. Si se quiere permitirlo, es una decisión aparte: implicaría definir qué significa "otro teléfono" para una venta ya vinculada.
2. **`destroyWhatsappPhone` sigue existiendo** y limpiando `contact_phone`. Tras este plan casi nunca habrá un `contact_phone` que borrar en ventas POS (el teléfono vive en el cliente). Conviene decidir en una iteración posterior si en POS ese botón debería pasar a significar "quitar cliente de la venta" — hoy sería confuso tener dos acciones distintas con el mismo icono.
