# Atlas vivo del sistema — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir dentro de `carniceria-saas` un Atlas visual de solo lectura, exclusivo para `superadmin`, que permita explorar aplicaciones, módulos, conexiones, flujos, estados, evidencia y archivos a partir de `system-architecture.json`.

**Architecture:** Una ruta Inertia protegida por el grupo existente `/admin` monta una página Vue delgada. El frontend importa un manifiesto JSON validado en build, crea índices y grafos mediante funciones puras y renderiza dos representaciones equivalentes: campus SVG/HTML 2.5D y lista accesible. No hay API de arquitectura, consultas a PostgreSQL, mutaciones ni cambios en los contratos de negocio.

**Tech Stack:** Laravel 13, Inertia v2, Vue 3 Composition API con `<script setup>`, JavaScript siguiendo el repositorio existente, Tailwind CSS 3, SVG, `node:test`, PHPUnit.

## Global Constraints

- La especificación fuente de verdad es `docs/superpowers/specs/2026-08-06-atlas-vivo-sistema-design.md`.
- Solo `superadmin` puede acceder. `admin-empresa`, `admin-sucursal` y `cajero` reciben 403 aunque conozcan la URL.
- La herramienta vive en `GET /admin/arquitectura` con nombre `admin.arquitectura.index`; no existe ruta tenant equivalente.
- El MVP es exclusivamente de lectura: cero migraciones, modelos, escrituras, endpoints JSON o acciones administrativas.
- El manifiesto documenta la arquitectura; nunca decide autorizaciones ni comportamiento del negocio.
- No añadir Three.js, canvas, imágenes generadas, motor de física ni librería de grafos.
- No añadir un framework de pruebas Vue. La lógica comprobable vive en módulos JavaScript puros probados con `node:test`; la UI se verifica con build y recorrido manual.
- Usar Composition API y `<script setup>`; mantener las páginas de ruta como superficies de composición.
- Usar `shallowRef()` para estado primitivo de Vue; reservar `ref()` para valores que requieran reactividad profunda.
- Seguir el JavaScript existente del repositorio. No introducir TypeScript solo para esta herramienta.
- Todo estado concluyente del manifiesto requiere evidencia con archivo, commit y fecha.
- Los estados se comunican con texto, color y forma/patrón; nunca solo por color.
- La vista de lista y la navegación por teclado son requisitos del MVP, no mejoras opcionales.
- Enlaces a fuentes usan GitHub + commit; nunca rutas locales, `file://` ni `vscode://`.
- Respetar `prefers-reduced-motion` y objetivo táctil mínimo de 44 × 44 px.
- Ejecutar `./vendor/bin/pint --dirty` después de cambios PHP.
- Antes de cada commit ejecutar `git status --short` y agregar solo los archivos de la tarea. Nunca usar `git add .` ni `git add -A`.
- Cada tarea termina en un commit independiente y verificable.

---

## File and responsibility map

```text
app/Http/Controllers/Admin/ArchitectureAtlasController.php
    Sirve únicamente el componente Inertia; no consulta datos.

resources/js/Pages/Admin/ArchitectureAtlas/Index.vue
    Página de ruta delgada con AdminLayout.

resources/js/Features/Architecture/
├── components/
│   ├── ArchitectureExplorer.vue       Orquesta escena, lista y panel.
│   ├── ArchitectureBreadcrumbs.vue    Navegación entre niveles.
│   ├── ArchitectureToolbar.vue        Búsqueda, filtros, modos y vista.
│   ├── ArchitectureLegend.vue         Semántica visual.
│   ├── ArchitectureListView.vue       Alternativa accesible completa.
│   ├── ArchitectureDetailPanel.vue    Detalle técnico y enlaces.
│   ├── EcosystemScene.vue              Campus de aplicaciones.
│   ├── ApplicationBuilding.vue        Edificio SVG seleccionable.
│   ├── ApplicationScene.vue            Habitaciones de una aplicación.
│   ├── ModuleRoom.vue                  Habitación SVG seleccionable.
│   └── ConnectionLayer.vue             Caminos, eventos y flujos.
├── composables/
│   └── useArchitectureExplorer.js      Estado mínimo y navegación.
├── data/
│   ├── system-architecture.json        Catálogo auditado.
│   └── system-architecture.schema.json Contrato portable.
└── lib/
    ├── architectureGraph.js            Índices, búsqueda y relaciones.
    └── architectureQuery.js            Query string segura/compartible.

scripts/validate-architecture-manifest.mjs
    Validación estructural e integridad referencial sin dependencias.

tests/Architecture/
├── architecture-manifest.test.mjs
├── architecture-graph.test.mjs
└── architecture-query.test.mjs

tests/Feature/Admin/ArchitectureAtlasAccessTest.php
    Autorización e Inertia.

docs/frontend/atlas-vivo.md
    Documento vivo una vez entregado el MVP.
```

## Component contracts

| Componente | Props | Eventos | Responsabilidad única |
|---|---|---|---|
| `ArchitectureExplorer` | `manifest` | ninguno | Conecta composable, toolbar, escena, lista y panel |
| `ArchitectureBreadcrumbs` | `level`, `application`, `module` | `go-ecosystem`, `go-application` | Mostrar y accionar la jerarquía |
| `ArchitectureToolbar` | `query`, `filters`, `mode`, `view`, catálogos | `update:*`, `clear` | Controles de exploración |
| `EcosystemScene` | `applications`, `connections`, `layout`, `selectedId` | `select-application` | Campus completo |
| `ApplicationBuilding` | `application`, `layout`, `selected`, `dimmed` | `select` | Un edificio accesible |
| `ApplicationScene` | `application`, `modules`, `connections`, `layout`, `selectedId` | `select-module` | Planta/habitaciones de una app |
| `ModuleRoom` | `module`, `layout`, `selected`, `dimmed` | `select` | Una habitación accesible |
| `ConnectionLayer` | `connections`, `nodes`, `mode`, `selectedEntityId` | ninguno | Trazar relaciones relevantes |
| `ArchitectureListView` | `applications`, `modules`, `query` | `select-application`, `select-module` | Navegación equivalente sin mapa |
| `ArchitectureDetailPanel` | `entity`, `related`, `manifest`, `open` | `close`, `select-related` | Detalle técnico |
| `ArchitectureLegend` | `statuses`, `connectionTypes` | ninguno | Explicar símbolos y colores |

---

### Task 1: Proteger y exponer la página del Atlas para `superadmin`

**Files:**
- Create: `app/Http/Controllers/Admin/ArchitectureAtlasController.php`
- Create: `tests/Feature/Admin/ArchitectureAtlasAccessTest.php`
- Modify: `routes/web.php`
- Modify: `resources/js/Layouts/AdminLayout.vue`
- Create: `resources/js/Pages/Admin/ArchitectureAtlas/Index.vue`

**Interfaces:**
- Produces: `GET /admin/arquitectura`, ruta `admin.arquitectura.index`, componente Inertia `Admin/ArchitectureAtlas/Index`.
- Security contract: middleware existente `auth` + `role:superadmin`; ningún prop de negocio.

- [ ] **Step 1: escribir la prueba de acceso que debe fallar**

Crear `tests/Feature/Admin/ArchitectureAtlasAccessTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ArchitectureAtlasAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin-empresa', 'admin-sucursal', 'cajero'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/arquitectura')->assertRedirect('/login');
    }

    public function test_superadmin_can_open_the_atlas(): void
    {
        $this->actingAs($this->userWithRole('superadmin'))
            ->get('/admin/arquitectura')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ArchitectureAtlas/Index')
                ->missing('tenants')
                ->missing('architecture'));
    }

    public function test_tenant_roles_are_forbidden(): void
    {
        foreach (['admin-empresa', 'admin-sucursal', 'cajero'] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->get('/admin/arquitectura')
                ->assertForbidden();
        }
    }
}
```

- [ ] **Step 2: ejecutar la prueba y confirmar el fallo por ruta inexistente**

Run:

```bash
php artisan test --filter=ArchitectureAtlasAccessTest
```

Expected: FAIL porque `/admin/arquitectura` todavía devuelve 404.

- [ ] **Step 3: crear el controlador mínimo sin acceso a datos**

Crear `app/Http/Controllers/Admin/ArchitectureAtlasController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class ArchitectureAtlasController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Admin/ArchitectureAtlas/Index');
    }
}
```

- [ ] **Step 4: registrar la ruta dentro del grupo exclusivo de superadmin**

En los imports de `routes/web.php` agregar:

```php
use App\Http\Controllers\Admin\ArchitectureAtlasController;
```

Dentro del grupo `Route::prefix('admin')->middleware(['auth', 'role:superadmin'])` agregar después del dashboard:

```php
Route::get('arquitectura', ArchitectureAtlasController::class)
    ->name('arquitectura.index');
```

- [ ] **Step 5: crear la página Inertia de composición**

Crear `resources/js/Pages/Admin/ArchitectureAtlas/Index.vue`:

```vue
<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
</script>

<template>
    <Head title="Atlas del sistema" />

    <AdminLayout>
        <template #header>
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-red-600">Arquitectura</p>
                <h1 class="text-xl font-bold text-gray-900">Atlas del sistema</h1>
            </div>
        </template>

        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <p class="text-sm text-gray-600">Cargando mapa del ecosistema…</p>
        </section>
    </AdminLayout>
</template>
```

- [ ] **Step 6: añadir el enlace únicamente a `AdminLayout`**

En `resources/js/Layouts/AdminLayout.vue`, añadir al arreglo `navLinks`:

```js
{ label: 'Atlas del sistema', route: 'admin.arquitectura.index', match: 'admin.arquitectura', icon: 'architecture' },
```

Añadir dentro del `<Link>` un icono para `architecture`:

```vue
<svg v-if="link.icon === 'architecture'" class="h-5 w-5 shrink-0 transition-colors"
    :class="isActive(link) ? 'text-white' : 'text-red-300 group-hover:text-white'"
    fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6 12 2.25 20.25 6 12 9.75 3.75 6Zm0 6L12 15.75 20.25 12M3.75 18 12 21.75 20.25 18" />
</svg>
```

No modificar `EmpresaLayout`, `SucursalLayout` ni `CajeroLayout`.

- [ ] **Step 7: ejecutar pruebas, formato y build**

```bash
php artisan test --filter=ArchitectureAtlasAccessTest
./vendor/bin/pint --dirty
npm run build
```

Expected: 3 pruebas en verde, Pint sin cambios pendientes y build Vite exitoso.

- [ ] **Step 8: commit**

```bash
git status --short
git add app/Http/Controllers/Admin/ArchitectureAtlasController.php routes/web.php resources/js/Layouts/AdminLayout.vue resources/js/Pages/Admin/ArchitectureAtlas/Index.vue tests/Feature/Admin/ArchitectureAtlasAccessTest.php
git commit -m "feat(atlas): agrega acceso exclusivo para superadmin"
```

---

### Task 2: Crear el contrato, validador y manifiesto base

**Files:**
- Create: `resources/js/Features/Architecture/data/system-architecture.schema.json`
- Create: `resources/js/Features/Architecture/data/system-architecture.json`
- Create: `scripts/validate-architecture-manifest.mjs`
- Create: `tests/Architecture/architecture-manifest.test.mjs`
- Modify: `package.json`

**Interfaces:**
- Produces: `validateManifest(manifest): string[]`.
- Produces scripts: `npm run validate:architecture` y `npm run test:architecture`.
- El manifiesto base tiene las 19 colecciones raíz exigidas por la spec.

- [ ] **Step 1: escribir las pruebas del contrato antes del validador**

Crear `tests/Architecture/architecture-manifest.test.mjs`:

```js
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { validateManifest } from '../../scripts/validate-architecture-manifest.mjs';

const manifestPath = new URL('../../resources/js/Features/Architecture/data/system-architecture.json', import.meta.url);

async function loadManifest() {
    return JSON.parse(await readFile(manifestPath, 'utf8'));
}

test('the checked-in architecture manifest is valid', async () => {
    const manifest = await loadManifest();
    assert.deepEqual(validateManifest(manifest), []);
});

test('duplicate ids and broken references are rejected', async () => {
    const manifest = await loadManifest();
    manifest.applications.push({ ...manifest.applications[0] });
    manifest.modules[0].applicationId = 'app.missing';

    const errors = validateManifest(manifest);
    assert.ok(errors.some((error) => error.includes('duplicate id')));
    assert.ok(errors.some((error) => error.includes('app.missing')));
});

test('a conclusive status requires evidence', async () => {
    const manifest = await loadManifest();
    manifest.modules[0].status.evidenceIds = [];

    assert.ok(validateManifest(manifest).some((error) => error.includes('requires evidence')));
});
```

- [ ] **Step 2: añadir scripts y confirmar el fallo inicial**

En `package.json` agregar:

```json
"test:architecture": "node --test tests/Architecture/*.test.mjs",
"validate:architecture": "node scripts/validate-architecture-manifest.mjs"
```

Run:

```bash
npm run test:architecture
```

Expected: FAIL porque el validador y el manifiesto aún no existen.

- [ ] **Step 3: crear el validador sin dependencias**

Crear `scripts/validate-architecture-manifest.mjs` con este contrato:

```js
import { readFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';

export const entityCollections = [
    'repositories', 'applications', 'modules', 'components', 'connections',
    'dataSources', 'endpoints', 'events', 'databaseTables', 'devices',
    'dependencies', 'permissions', 'featureFlags', 'sourceFiles', 'risks',
    'evidence',
];

const allowedConnectionKinds = new Set([
    'http', 'websocket', 'polling', 'ipc', 'usb-serial', 'mdns',
    'database', 'sync-outbox', 'cache', 'external-link',
]);

const inconclusiveStatuses = new Set(['requires-review', 'unknown']);

export function validateManifest(manifest) {
    const errors = [];
    const requiredRoots = ['metadata', 'statuses', ...entityCollections, 'visualLayout'];

    for (const key of requiredRoots) {
        if (!(key in manifest)) errors.push(`missing root collection: ${key}`);
    }

    if (manifest.metadata?.schemaVersion !== '1.0.0') {
        errors.push('metadata.schemaVersion must be 1.0.0');
    }

    const allEntities = new Map();
    for (const collection of entityCollections) {
        if (!Array.isArray(manifest[collection])) {
            errors.push(`${collection} must be an array`);
            continue;
        }

        for (const entity of manifest[collection]) {
            if (!entity?.id) {
                errors.push(`${collection} contains an entity without id`);
                continue;
            }
            if (allEntities.has(entity.id)) errors.push(`duplicate id: ${entity.id}`);
            allEntities.set(entity.id, { collection, entity });
        }
    }

    const statusIds = new Set((manifest.statuses ?? []).map((status) => status.id));
    const evidenceIds = new Set((manifest.evidence ?? []).map((entry) => entry.id));
    const requireEntity = (ownerId, field, referencedId) => {
        if (referencedId && !allEntities.has(referencedId)) {
            errors.push(`${ownerId}.${field} references missing id ${referencedId}`);
        }
    };

    for (const application of manifest.applications ?? []) {
        requireEntity(application.id, 'repositoryId', application.repositoryId);
    }

    for (const module of manifest.modules ?? []) {
        requireEntity(module.id, 'applicationId', module.applicationId);
        const statusId = module.status?.id;
        if (!statusIds.has(statusId)) errors.push(`${module.id} has unknown status ${statusId}`);

        const refs = module.status?.evidenceIds ?? [];
        if (!inconclusiveStatuses.has(statusId) && refs.length === 0) {
            errors.push(`${module.id} requires evidence for status ${statusId}`);
        }
        for (const evidenceId of refs) {
            if (!evidenceIds.has(evidenceId)) errors.push(`${module.id} references missing evidence ${evidenceId}`);
        }
        for (const field of [
            'sourceOfTruthIds', 'endpointIds', 'eventIds', 'dependencyIds',
            'sourceFileIds', 'riskIds', 'featureFlagIds', 'permissionIds',
            'databaseTableIds', 'componentIds', 'deviceIds',
        ]) {
            for (const id of module[field] ?? []) requireEntity(module.id, field, id);
        }
    }

    for (const connection of manifest.connections ?? []) {
        requireEntity(connection.id, 'fromId', connection.fromId);
        requireEntity(connection.id, 'toId', connection.toId);
        if (!allowedConnectionKinds.has(connection.kind)) {
            errors.push(`${connection.id} has unknown connection kind ${connection.kind}`);
        }
        for (const field of ['endpointIds', 'eventIds', 'evidenceIds']) {
            for (const id of connection[field] ?? []) requireEntity(connection.id, field, id);
        }
    }

    for (const endpoint of manifest.endpoints ?? []) {
        requireEntity(endpoint.id, 'applicationId', endpoint.applicationId);
        requireEntity(endpoint.id, 'sourceFileId', endpoint.sourceFileId);
    }

    for (const event of manifest.events ?? []) {
        requireEntity(event.id, 'applicationId', event.applicationId);
        for (const id of event.emitterIds ?? []) requireEntity(event.id, 'emitterIds', id);
        for (const id of event.listenerIds ?? []) requireEntity(event.id, 'listenerIds', id);
    }

    for (const file of manifest.sourceFiles ?? []) {
        requireEntity(file.id, 'repositoryId', file.repositoryId);
    }

    for (const entry of manifest.evidence ?? []) {
        requireEntity(entry.id, 'sourceFileId', entry.sourceFileId);
    }

    for (const risk of manifest.risks ?? []) {
        for (const id of risk.evidenceIds ?? []) requireEntity(risk.id, 'evidenceIds', id);
    }

    for (const building of manifest.visualLayout?.buildings ?? []) {
        requireEntity(`visual.building.${building.applicationId}`, 'applicationId', building.applicationId);
    }
    for (const room of manifest.visualLayout?.rooms ?? []) {
        requireEntity(`visual.room.${room.moduleId}`, 'moduleId', room.moduleId);
    }

    return errors;
}

async function main() {
    const manifestUrl = new URL('../resources/js/Features/Architecture/data/system-architecture.json', import.meta.url);
    const manifest = JSON.parse(await readFile(manifestUrl, 'utf8'));
    const errors = validateManifest(manifest);

    if (errors.length > 0) {
        console.error(errors.map((error) => `- ${error}`).join('\n'));
        process.exitCode = 1;
        return;
    }

    console.log(`Architecture manifest valid: ${manifest.applications.length} applications, ${manifest.modules.length} modules, ${manifest.connections.length} connections.`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    await main();
}
```

- [ ] **Step 4: crear JSON Schema y manifiesto base válido**

`system-architecture.schema.json` debe declarar Draft 2020-12, exigir todas
las colecciones raíz, definir `$defs.id`, `$defs.statusReference` y
`$defs.evidenceReference`, y usar `additionalProperties: false` en
`metadata`, `statuses`, `applications`, `modules`, `connections` y
`visualLayout`. Los enums son exactamente:

```json
{
  "statusIds": ["implemented", "partial", "pending", "in-review", "issues", "requires-review", "unknown", "not-responsible"],
  "determinations": ["verified", "inferred", "declared", "requires-review"],
  "confidence": ["high", "medium", "low"],
  "connectionKinds": ["http", "websocket", "polling", "ipc", "usb-serial", "mdns", "database", "sync-outbox", "cache", "external-link"]
}
```

Crear el manifiesto base con:

- metadata y los cuatro commits de la spec;
- los ocho estados anteriores;
- cuatro repositorios;
- cuatro aplicaciones;
- un módulo con evidencia por aplicación para mantener el archivo válido;
- cuatro `sourceFiles` y cuatro evidencias que sostengan esos módulos;
- las colecciones todavía no pobladas como arreglos vacíos;
- un edificio por aplicación y una habitación por módulo.

El módulo base de SaaS debe tener esta forma exacta, que fija el contrato usado
en la tarea siguiente:

```json
{
  "id": "saas.core.tenancy-auth",
  "applicationId": "app.saas",
  "name": "Autenticación, roles y multi-tenancy",
  "description": "Resuelve el tenant, autentica usuarios y aplica los cuatro roles del sistema.",
  "status": {
    "id": "implemented",
    "determination": "verified",
    "confidence": "high",
    "evidenceIds": ["ev.saas.routes-web"]
  },
  "responsibleApplicationId": "app.saas",
  "sourceOfTruthIds": [],
  "internetRequirement": { "requiredForUse": true, "notes": "Se ejecuta en el backend central." },
  "offlineCapability": { "supported": false, "scope": "none" },
  "implementedCapabilities": ["Resolución de tenant", "Autenticación", "Roles"],
  "pendingCapabilities": [],
  "endpointIds": [],
  "eventIds": [],
  "dependencyIds": [],
  "sourceFileIds": ["file.saas.routes-web"],
  "riskIds": [],
  "searchTerms": ["tenant", "roles", "login", "ResolveTenant"]
}
```

- [ ] **Step 5: ejecutar contrato y validador**

```bash
npm run test:architecture
npm run validate:architecture
```

Expected: 3 pruebas en verde y mensaje `Architecture manifest valid: 4 applications, 4 modules, 0 connections.`

- [ ] **Step 6: commit**

```bash
git status --short
git add package.json scripts/validate-architecture-manifest.mjs tests/Architecture/architecture-manifest.test.mjs resources/js/Features/Architecture/data/system-architecture.json resources/js/Features/Architecture/data/system-architecture.schema.json
git commit -m "feat(atlas): define manifiesto y validacion arquitectonica"
```

---

### Task 3: Poblar el inventario auditado y sus relaciones

**Files:**
- Modify: `resources/js/Features/Architecture/data/system-architecture.json`
- Modify: `tests/Architecture/architecture-manifest.test.mjs`

**Interfaces:**
- Produces: catálogo inicial completo del snapshot aprobado.
- Stable application IDs: `app.saas`, `app.hub`, `app.scale-web`, `app.scale-android`.

- [ ] **Step 1: fijar pruebas de cobertura mínima antes de cargar los datos**

Añadir a `architecture-manifest.test.mjs`:

```js
test('the MVP inventory covers the audited ecosystem', async () => {
    const manifest = await loadManifest();
    const moduleIds = new Set(manifest.modules.map(({ id }) => id));
    const connectionIds = new Set(manifest.connections.map(({ id }) => id));

    assert.equal(manifest.applications.length, 4);
    assert.equal(manifest.modules.length, 47);
    assert.ok(manifest.connections.length >= 18);

    for (const id of [
        'saas.sales.workbench',
        'saas.inventory.stock',
        'hub.sync.scale-sales',
        'hub.pairing.scale-devices',
        'scale-web.hardware.adapter',
        'scale-android.hardware.usb-serial',
        'scale-android.sales.via-hub',
    ]) assert.ok(moduleIds.has(id), `missing module ${id}`);

    for (const id of [
        'conn.android.usb-scale',
        'conn.android.hub-lan',
        'conn.hub.saas-sync',
        'conn.saas.postgresql',
        'conn.saas.reverb-web',
    ]) assert.ok(connectionIds.has(id), `missing connection ${id}`);
});

test('disabled features and delegated responsibility are not reported as pending', async () => {
    const manifest = await loadManifest();
    const modules = new Map(manifest.modules.map((module) => [module.id, module]));

    assert.equal(modules.get('saas.web-orders').status.id, 'implemented');
    assert.deepEqual(modules.get('saas.web-orders').featureFlagIds, ['flag.saas.web-orders']);
    assert.equal(modules.get('hub.users').status.id, 'not-responsible');
    assert.equal(modules.get('saas.inventory.stock').status.id, 'pending');
});
```

- [ ] **Step 2: ejecutar y confirmar que falla por cobertura incompleta**

```bash
npm run test:architecture
```

Expected: FAIL con conteo 4 módulos en vez de 47.

- [ ] **Step 3: cargar exactamente los 47 módulos auditados**

Usar los siguientes IDs estables; cada entrada lleva todos los campos del
contrato de Task 2 y al menos una evidencia salvo `requires-review`/`unknown`:

```text
saas.core.tenancy-auth
saas.core.organizations
saas.catalog.products
saas.inventory.stock
saas.sales.workbench
saas.sales.payments
saas.cash.shifts
saas.customers.credit
saas.expenses
saas.purchases-providers
saas.metrics
saas.agenda
saas.ai-assistant
saas.web-orders
saas.realtime
saas.api.scales
saas.api.hub
saas.api.public

hub.shell-ipc
hub.shared-online-flows
hub.local-scale-api
hub.sqlite
hub.sync.scale-sales
hub.catalog-cache
hub.realtime
hub.printing
hub.discovery-mdns
hub.pairing.scale-devices
hub.users

scale-web.setup
scale-web.catalog-sales
scale-web.hardware.adapter
scale-web.hub-compatibility
scale-web.idempotency
scale-web.offline
scale-web.tests

scale-android.hardware.usb-serial
scale-android.weight-capture
scale-android.setup-qr
scale-android.discovery-mdns
scale-android.sales.direct-cloud
scale-android.sales.via-hub
scale-android.catalog-cache
scale-android.updates
scale-android.pairing
scale-android.printing
scale-android.product-scanner
```

Los estados y observaciones son exactamente los de la sección “Inventario de
módulos” de la spec. En particular:

- `saas.inventory.stock = pending`;
- `saas.web-orders = implemented` + `flag.saas.web-orders` desactivado;
- `hub.sync.scale-sales = partial` y declara que solo cubre ventas de báscula;
- `hub.pairing.scale-devices = in-review` y evidencia el commit de rama;
- `hub.users = not-responsible`;
- `scale-web.hub-compatibility = issues`;
- `scale-android.pairing = pending`;
- `scale-android.product-scanner = requires-review`.

- [ ] **Step 4: cargar entidades técnicas y fuentes de verdad**

Crear como mínimo estos IDs:

```text
components:
component.saas.laravel
component.saas.vue-inertia
component.saas.postgresql
component.saas.redis
component.saas.reverb
component.hub.electron-main
component.hub.vue-renderer
component.hub.fastify
component.hub.sqlite
component.android.usb-driver
component.external.openai
component.external.google-maps
component.external.object-storage
component.external.apk-distribution

dataSources:
data.saas.postgresql.business
data.saas.laravel-rules
data.hub.sqlite.unsynced-sales
data.hub.sqlite.catalog-cache
data.hub.electron-store
data.android.datastore
data.scale-web.local-storage
data.device.physical-weight

devices:
device.scale.usb
device.android-terminal
device.camera.qr
device.printer.system
device.reader.unverified
```

Las fuentes PostgreSQL/Laravel son autoritativas; SQLite de catálogo y
`localStorage` son explícitamente `authoritative: false`.

- [ ] **Step 5: cargar conexiones y mecanismos**

Incluir, como mínimo, estas relaciones dirigidas:

```text
conn.web.saas-inertia           http
conn.saas.postgresql            database
conn.saas.redis                 cache
conn.saas.reverb-web            websocket
conn.hub.renderer-ipc           ipc
conn.hub.saas-sanctum           http
conn.hub.reverb                 websocket
conn.hub.polling-fallback       polling
conn.hub.sqlite                 database
conn.hub.saas-sync              sync-outbox
conn.android.usb-scale          usb-serial
conn.android.hub-lan            http
conn.android.saas-cloud         http
conn.android.hub-discovery      mdns
conn.scale-web.saas             http
conn.saas.openai                http
conn.saas.google-maps           http
conn.saas.object-storage        http
conn.android.apk-update         http
conn.public-menu.saas           http
```

Cada conexión declara `direction`, `auth`, `onlineRequired`, `durable`,
`status`, `evidenceIds` y los endpoints/eventos relacionados.

- [ ] **Step 6: cargar endpoints, eventos, tablas, permisos y flags**

El MVP documenta todos los límites entre aplicaciones y agrupa las rutas web
CRUD internas. Incluir al menos:

```text
endpoints:
endpoint.saas.scale.branch-me          GET /api/v1/branches/me
endpoint.saas.scale.categories         GET /api/v1/categories
endpoint.saas.scale.products           GET /api/v1/products
endpoint.saas.scale.sales-create       POST /api/v1/sales
endpoint.saas.scale.sales-index        GET /api/v1/sales
endpoint.saas.scale.sales-show         GET /api/v1/sales/{sale}
endpoint.hub.local.branch-me           GET /api/v1/branches/me
endpoint.hub.local.categories          GET /api/v1/categories
endpoint.hub.local.products            GET /api/v1/products
endpoint.hub.local.sales-create        POST /api/v1/sales
endpoint.hub.local.sales-status        GET /api/v1/sales/{clientReference}
endpoint.saas.hub.surface              /api/v1/hub/*
endpoint.saas.public.surface           /api/public/{tenantSlug}/*
endpoint.saas.web.admin                /admin/*
endpoint.saas.web.empresa              /{tenant}/empresa/*
endpoint.saas.web.sucursal             /{tenant}/sucursal/*
endpoint.saas.web.caja                 /{tenant}/caja/*

events:
event.saas.new-external-sale
event.saas.sale-updated
event.saas.sale-locked
event.saas.sale-unlocked
event.saas.agenda-item-assigned

featureFlags:
flag.saas.web-orders
flag.branch.cashier-customers
flag.branch.cashier-expenses
flag.branch.cashier-purchases
flag.branch.admin-providers
flag.branch.admin-expense-categories

permissions:
permission.role.superadmin
permission.role.admin-empresa
permission.role.admin-sucursal
permission.role.cajero
permission.hub.roles
```

Incluir las tablas de negocio auditadas por grupos semánticos, conservando el
nombre físico en `name`: tenants/branches/users, products/categories/
product_presentations, sales/sale_items/payments, cash_register_shifts/
cash_withdrawals, customers/customer_product_prices/customer_payments,
expenses y adjuntos/catálogos, providers/purchases/items/payments y adjuntos,
AI drafts/sessions/messages, agenda_items, audit logs, personal access tokens y
tablas de Spatie. No crear una tabla de inventario inexistente.

- [ ] **Step 7: cargar archivos, evidencia, riesgos y layout**

Cada módulo debe enlazar por lo menos un archivo real. Las evidencias mínimas
son los archivos citados en la spec y deben usar repositorio + commit + path,
por ejemplo:

```json
{
  "id": "file.saas.api-sale-controller",
  "repositoryId": "repo.saas",
  "path": "app/Http/Controllers/Api/SaleController.php",
  "role": "Crea ventas recibidas desde básculas"
}
```

Crear los diez riesgos `risk.audit.*` de la spec. En `visualLayout` ubicar los
cuatro edificios dentro de `viewBox: "0 0 1200 720"`, con estas posiciones
base para obtener una composición estable:

```json
[
  { "applicationId": "app.saas", "x": 600, "y": 170, "width": 250, "depth": 130, "height": 210, "tone": "red" },
  { "applicationId": "app.hub", "x": 275, "y": 330, "width": 210, "depth": 110, "height": 150, "tone": "amber" },
  { "applicationId": "app.scale-android", "x": 850, "y": 380, "width": 175, "depth": 90, "height": 115, "tone": "sky" },
  { "applicationId": "app.scale-web", "x": 930, "y": 175, "width": 150, "depth": 76, "height": 90, "tone": "slate" }
]
```

Distribuir habitaciones en una grilla declarativa por aplicación con
`column`, `row`, `columnSpan` y `floor`. Ninguna posición se deriva del índice
del arreglo.

- [ ] **Step 8: validar inventario y revisar diff semántico**

```bash
npm run test:architecture
npm run validate:architecture
git diff --check
```

Expected: todas las pruebas en verde; mensaje con 4 aplicaciones, 47 módulos y
al menos 18 conexiones; cero referencias rotas.

- [ ] **Step 9: commit**

```bash
git status --short
git add resources/js/Features/Architecture/data/system-architecture.json tests/Architecture/architecture-manifest.test.mjs
git commit -m "docs(atlas): carga inventario tecnico auditado"
```

---

### Task 4: Construir búsqueda, filtros, grafo y URLs como lógica pura

**Files:**
- Create: `resources/js/Features/Architecture/lib/architectureGraph.js`
- Create: `resources/js/Features/Architecture/lib/architectureQuery.js`
- Create: `tests/Architecture/architecture-graph.test.mjs`
- Create: `tests/Architecture/architecture-query.test.mjs`

**Interfaces:**
- Produces: `createArchitectureGraph(manifest)`.
- Produces: `searchEntities(graph, query, filters)`.
- Produces: `getVisibleConnections(graph, selection, mode)`.
- Produces: `buildSourceUrl(repository, sourceFile)`.
- Produces: `parseArchitectureQuery(search, graph)` y `serializeArchitectureQuery(state)`.

- [ ] **Step 1: escribir pruebas de normalización, filtros y relaciones**

Crear `tests/Architecture/architecture-graph.test.mjs` con fixtures pequeños y
estas aserciones:

```js
import assert from 'node:assert/strict';
import test from 'node:test';
import {
    buildSourceUrl,
    createArchitectureGraph,
    getVisibleConnections,
    searchEntities,
} from '../../resources/js/Features/Architecture/lib/architectureGraph.js';

const manifest = {
    statuses: [{ id: 'implemented', label: 'Implementado' }],
    repositories: [{ id: 'repo.saas', webUrl: 'https://github.com/acme/saas', commit: 'abc123' }],
    applications: [{ id: 'app.saas', name: 'SaaS', description: 'Núcleo central' }],
    modules: [{
        id: 'saas.customers', applicationId: 'app.saas', name: 'Clientes',
        description: 'Cobro de fiado', status: { id: 'implemented' },
        searchTerms: ['cobranza'], sourceFileIds: ['file.customers'],
    }],
    endpoints: [{ id: 'endpoint.customers', applicationId: 'app.saas', method: 'GET', path: '/clientes' }],
    events: [], components: [], dataSources: [], databaseTables: [], devices: [],
    dependencies: [], permissions: [], featureFlags: [], risks: [], evidence: [],
    sourceFiles: [{ id: 'file.customers', repositoryId: 'repo.saas', path: 'app/Customer.php' }],
    connections: [{ id: 'conn.a', fromId: 'app.saas', toId: 'saas.customers', kind: 'http', viewModes: ['dependencies'] }],
};

test('search is case and accent insensitive', () => {
    const graph = createArchitectureGraph(manifest);
    assert.deepEqual(searchEntities(graph, 'COBRÁNZA', {}).map(({ id }) => id), ['saas.customers']);
});

test('filters modules by application and status', () => {
    const graph = createArchitectureGraph(manifest);
    assert.equal(searchEntities(graph, '', { applicationId: 'app.saas', statusId: 'implemented' }).length, 1);
    assert.equal(searchEntities(graph, '', { statusId: 'pending' }).length, 0);
});

test('returns only connections for selection and mode', () => {
    const graph = createArchitectureGraph(manifest);
    assert.deepEqual(getVisibleConnections(graph, 'app.saas', 'dependencies').map(({ id }) => id), ['conn.a']);
    assert.deepEqual(getVisibleConnections(graph, 'app.saas', 'sync'), []);
});

test('builds a source URL pinned to the audited commit', () => {
    assert.equal(
        buildSourceUrl(manifest.repositories[0], manifest.sourceFiles[0]),
        'https://github.com/acme/saas/blob/abc123/app/Customer.php',
    );
});
```

- [ ] **Step 2: escribir pruebas del query string**

Crear `tests/Architecture/architecture-query.test.mjs`:

```js
import assert from 'node:assert/strict';
import test from 'node:test';
import { parseArchitectureQuery, serializeArchitectureQuery } from '../../resources/js/Features/Architecture/lib/architectureQuery.js';

const graph = {
    applicationsById: new Map([['app.hub', { id: 'app.hub' }]]),
    modulesById: new Map([['hub.sync.scale-sales', { id: 'hub.sync.scale-sales', applicationId: 'app.hub' }]]),
};

test('parses a valid deep link', () => {
    assert.deepEqual(
        parseArchitectureQuery('?app=app.hub&module=hub.sync.scale-sales&mode=sync&view=map', graph),
        { applicationId: 'app.hub', moduleId: 'hub.sync.scale-sales', mode: 'sync', view: 'map' },
    );
});

test('drops unknown ids and invalid enum values', () => {
    assert.deepEqual(parseArchitectureQuery('?app=missing&mode=broken&view=x', graph), {
        applicationId: null, moduleId: null, mode: 'dependencies', view: 'map',
    });
});

test('serializes only non-default values', () => {
    assert.equal(serializeArchitectureQuery({
        applicationId: 'app.hub', moduleId: null, mode: 'dependencies', view: 'map',
    }), '?app=app.hub');
});
```

- [ ] **Step 3: confirmar que ambas suites fallan por módulos inexistentes**

```bash
npm run test:architecture
```

Expected: FAIL con `ERR_MODULE_NOT_FOUND`.

- [ ] **Step 4: implementar el grafo y la búsqueda**

`architectureGraph.js` debe:

```js
export function normalizeSearchText(value = '') {
    return String(value)
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .trim();
}

export function createArchitectureGraph(manifest) {
    const byId = (collection) => new Map(collection.map((entity) => [entity.id, entity]));
    const graph = {
        manifest,
        repositoriesById: byId(manifest.repositories),
        applicationsById: byId(manifest.applications),
        modulesById: byId(manifest.modules),
        endpointsById: byId(manifest.endpoints),
        eventsById: byId(manifest.events),
        sourceFilesById: byId(manifest.sourceFiles),
        entitiesById: new Map(),
        adjacentConnections: new Map(),
        searchable: [],
    };

    for (const collection of [manifest.applications, manifest.modules, manifest.endpoints, manifest.events, manifest.sourceFiles]) {
        for (const entity of collection) graph.entitiesById.set(entity.id, entity);
    }

    for (const connection of manifest.connections) {
        for (const id of [connection.fromId, connection.toId]) {
            const current = graph.adjacentConnections.get(id) ?? [];
            current.push(connection);
            graph.adjacentConnections.set(id, current);
        }
    }

    graph.searchable = manifest.modules.map((module) => ({
        ...module,
        normalizedSearch: normalizeSearchText([
            module.id, module.name, module.description,
            ...(module.searchTerms ?? []),
            ...(module.endpointIds ?? []).map((id) => {
                const endpoint = graph.endpointsById.get(id);
                return endpoint ? `${endpoint.method ?? ''} ${endpoint.path ?? ''}` : '';
            }),
            ...(module.sourceFileIds ?? []).map((id) => graph.sourceFilesById.get(id)?.path ?? ''),
        ].join(' ')),
    }));

    return graph;
}

export function searchEntities(graph, query, filters = {}) {
    const needle = normalizeSearchText(query);
    return graph.searchable.filter((module) => {
        if (needle && !module.normalizedSearch.includes(needle)) return false;
        if (filters.applicationId && module.applicationId !== filters.applicationId) return false;
        if (filters.statusId && module.status.id !== filters.statusId) return false;
        if (filters.connectionKind) {
            const connections = graph.adjacentConnections.get(module.id) ?? [];
            if (!connections.some(({ kind }) => kind === filters.connectionKind)) return false;
        }
        return true;
    });
}

export function getVisibleConnections(graph, selectedEntityId, mode = 'dependencies') {
    const source = selectedEntityId
        ? graph.adjacentConnections.get(selectedEntityId) ?? []
        : graph.manifest.connections;
    return source.filter((connection) => (connection.viewModes ?? ['dependencies']).includes(mode));
}

export function buildSourceUrl(repository, sourceFile) {
    return `${repository.webUrl}/blob/${repository.commit}/${sourceFile.path}`;
}
```

- [ ] **Step 5: implementar parseo y serialización de URL**

`architectureQuery.js` debe aceptar solo `dependencies`, `data`, `sync` y las
vistas `map`, `list`. Un módulo es válido únicamente si pertenece a la app
seleccionada. Implementar:

```js
const modes = new Set(['dependencies', 'data', 'sync']);
const views = new Set(['map', 'list']);

export function parseArchitectureQuery(search, graph) {
    const params = new URLSearchParams(search);
    const requestedApp = params.get('app');
    const applicationId = graph.applicationsById.has(requestedApp) ? requestedApp : null;
    const requestedModule = params.get('module');
    const module = graph.modulesById.get(requestedModule);
    const moduleId = module && module.applicationId === applicationId ? requestedModule : null;
    const requestedMode = params.get('mode');
    const requestedView = params.get('view');

    return {
        applicationId,
        moduleId,
        mode: modes.has(requestedMode) ? requestedMode : 'dependencies',
        view: views.has(requestedView) ? requestedView : 'map',
    };
}

export function serializeArchitectureQuery(state) {
    const params = new URLSearchParams();
    if (state.applicationId) params.set('app', state.applicationId);
    if (state.moduleId) params.set('module', state.moduleId);
    if (state.mode !== 'dependencies') params.set('mode', state.mode);
    if (state.view !== 'map') params.set('view', state.view);
    const value = params.toString();
    return value ? `?${value}` : '';
}
```

- [ ] **Step 6: ejecutar pruebas**

```bash
npm run test:architecture
```

Expected: todas las suites `Architecture` en verde.

- [ ] **Step 7: commit**

```bash
git status --short
git add resources/js/Features/Architecture/lib/architectureGraph.js resources/js/Features/Architecture/lib/architectureQuery.js tests/Architecture/architecture-graph.test.mjs tests/Architecture/architecture-query.test.mjs
git commit -m "feat(atlas): agrega grafo busqueda y enlaces compartibles"
```

---

### Task 5: Entregar primero el explorador funcional en vista de lista

**Files:**
- Create: `resources/js/Features/Architecture/composables/useArchitectureExplorer.js`
- Create: `resources/js/Features/Architecture/components/ArchitectureExplorer.vue`
- Create: `resources/js/Features/Architecture/components/ArchitectureBreadcrumbs.vue`
- Create: `resources/js/Features/Architecture/components/ArchitectureToolbar.vue`
- Create: `resources/js/Features/Architecture/components/ArchitectureListView.vue`
- Create: `resources/js/Features/Architecture/components/ArchitectureDetailPanel.vue`
- Create: `resources/js/Features/Architecture/components/ArchitectureLegend.vue`
- Modify: `resources/js/Pages/Admin/ArchitectureAtlas/Index.vue`

**Interfaces:**
- Consumes: manifest, `createArchitectureGraph`, `searchEntities`, query helpers.
- Produces: búsqueda, filtros, drill-down, lista, detalle y enlaces de fuente antes de añadir la escena visual.

- [ ] **Step 1: implementar el composable con estado mínimo derivado**

`useArchitectureExplorer.js` debe importar el grafo/query helpers y exponer:

```js
import { computed, onMounted, shallowRef, watch } from 'vue';
import { createArchitectureGraph, getVisibleConnections, searchEntities } from '../lib/architectureGraph.js';
import { parseArchitectureQuery, serializeArchitectureQuery } from '../lib/architectureQuery.js';

export function useArchitectureExplorer(manifest) {
    const graph = createArchitectureGraph(manifest);
    const initial = typeof window === 'undefined'
        ? { applicationId: null, moduleId: null, mode: 'dependencies', view: 'map' }
        : parseArchitectureQuery(window.location.search, graph);

    const selectedApplicationId = shallowRef(initial.applicationId);
    const selectedModuleId = shallowRef(initial.moduleId);
    const mode = shallowRef(initial.mode);
    const view = shallowRef(initial.view);
    const query = shallowRef('');
    const statusId = shallowRef('');
    const connectionKind = shallowRef('');

    const selectedApplication = computed(() => graph.applicationsById.get(selectedApplicationId.value) ?? null);
    const selectedModule = computed(() => graph.modulesById.get(selectedModuleId.value) ?? null);
    const level = computed(() => selectedModule.value ? 'module' : selectedApplication.value ? 'application' : 'ecosystem');
    const filteredModules = computed(() => searchEntities(graph, query.value, {
        applicationId: selectedApplicationId.value,
        statusId: statusId.value,
        connectionKind: connectionKind.value,
    }));
    const visibleConnections = computed(() => getVisibleConnections(
        graph,
        selectedModuleId.value ?? selectedApplicationId.value,
        mode.value,
    ));

    const selectApplication = (id) => {
        selectedApplicationId.value = id;
        selectedModuleId.value = null;
    };
    const selectModule = (id) => {
        const module = graph.modulesById.get(id);
        if (!module) return;
        selectedApplicationId.value = module.applicationId;
        selectedModuleId.value = id;
    };
    const goToEcosystem = () => {
        selectedApplicationId.value = null;
        selectedModuleId.value = null;
    };
    const goToApplication = () => { selectedModuleId.value = null; };
    const clearFilters = () => {
        query.value = '';
        statusId.value = '';
        connectionKind.value = '';
    };

    onMounted(() => {
        watch(
            [selectedApplicationId, selectedModuleId, mode, view],
            () => window.history.replaceState({}, '', `${window.location.pathname}${serializeArchitectureQuery({
                applicationId: selectedApplicationId.value,
                moduleId: selectedModuleId.value,
                mode: mode.value,
                view: view.value,
            })}`),
            { immediate: true },
        );
    });

    return {
        graph, level, query, statusId, connectionKind, mode, view,
        selectedApplication, selectedModule, filteredModules, visibleConnections,
        selectApplication, selectModule, goToEcosystem, goToApplication, clearFilters,
    };
}
```

- [ ] **Step 2: crear breadcrumbs y toolbar con contratos explícitos**

`ArchitectureBreadcrumbs.vue` usa botones reales, no texto clicable, y emite
los dos eventos del mapa de componentes. `ArchitectureToolbar.vue` contiene:

- input `type="search"` con label visible;
- select de estado;
- select de conexión;
- tres botones de modo con `aria-pressed`;
- toggle mapa/lista con `aria-pressed`;
- botón “Limpiar filtros”.

Los modelos se implementan con `defineModel('query')`,
`defineModel('statusId')`, `defineModel('connectionKind')`,
`defineModel('mode')` y `defineModel('view')`. Todos tienen tipo `String` y
valores por defecto exactos del composable.

- [ ] **Step 3: crear la vista de lista accesible**

`ArchitectureListView.vue` agrupa por aplicación con `<section>` y `<h2>`. Cada
módulo es un `<button>` de ancho completo que muestra nombre, descripción,
etiqueta textual de estado, requisito de internet y capacidad offline. El
evento es:

```js
const emit = defineEmits({
    'select-application': (id) => typeof id === 'string',
    'select-module': (id) => typeof id === 'string',
});
```

Si no hay resultados, renderizar:

```vue
<div role="status" class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-6 py-12 text-center">
    <p class="font-bold text-gray-900">No encontramos componentes</p>
    <p class="mt-1 text-sm text-gray-500">Prueba otra búsqueda o limpia los filtros.</p>
</div>
```

- [ ] **Step 4: crear el panel técnico**

`ArchitectureDetailPanel.vue` debe:

- usar `<aside aria-labelledby="architecture-detail-title">`;
- mostrar estado, determinación, confianza y `verifiedAt`;
- resolver IDs mediante los mapas del manifiesto;
- separar responsabilidad, fuentes, internet/offline, capacidades, endpoints,
  eventos, archivos, riesgos y evidencia;
- formar URLs mediante `buildSourceUrl`;
- abrir enlaces con `target="_blank" rel="noopener noreferrer"`;
- tener botón cerrar con label “Cerrar detalle”.

No usar `v-html`. Los arrays vacíos no renderizan secciones vacías.

- [ ] **Step 5: crear leyenda y orquestador**

`ArchitectureLegend.vue` lista todos los estados del manifiesto y los tipos de
conexión presentes. `ArchitectureExplorer.vue` usa el composable, mantiene
siempre montados breadcrumbs y toolbar, y alterna:

```vue
<ArchitectureListView
    v-if="view === 'list'"
    :applications="manifest.applications"
    :modules="filteredModules"
    :statuses="manifest.statuses"
    @select-application="selectApplication"
    @select-module="selectModule"
/>

<div v-else class="rounded-3xl border border-gray-200 bg-slate-950 p-4 text-white shadow-sm">
    <p class="text-sm text-slate-300">La escena visual se incorpora en el siguiente hito.</p>
</div>
```

El panel se abre cuando `selectedModule` existe. Seleccionar una app cambia el
nivel, pero no abre el panel técnico de módulo.

- [ ] **Step 6: importar manifiesto y montar el explorador en la página**

En `Index.vue`:

```js
import ArchitectureExplorer from '@/Features/Architecture/components/ArchitectureExplorer.vue';
import manifest from '@/Features/Architecture/data/system-architecture.json';
```

Sustituir el placeholder por:

```vue
<ArchitectureExplorer :manifest="manifest" />
```

- [ ] **Step 7: verificar lógica, build y recorrido de lista**

```bash
npm run test:architecture
npm run validate:architecture
npm run build
```

Manual:

1. Abrir `/admin/arquitectura?view=list`.
2. Buscar `client_reference`, `SaleUpdated`, `UsbScaleAdapter` y `fiado`.
3. Filtrar por `pending` y comprobar inventario, offline web y pairing Android.
4. Abrir un módulo y verificar que el enlace contiene el commit auditado.
5. Recorrer toolbar, lista y panel usando Tab/Enter y el botón accesible de cierre.

Expected: sin errores de consola, URL actualizada y toda la información
disponible sin mapa.

- [ ] **Step 8: commit**

```bash
git status --short
git add resources/js/Pages/Admin/ArchitectureAtlas/Index.vue resources/js/Features/Architecture/components resources/js/Features/Architecture/composables/useArchitectureExplorer.js
git commit -m "feat(atlas): agrega exploracion accesible del manifiesto"
```

---

### Task 6: Renderizar el campus 2.5D y sus conexiones

**Files:**
- Create: `resources/js/Features/Architecture/components/EcosystemScene.vue`
- Create: `resources/js/Features/Architecture/components/ApplicationBuilding.vue`
- Create: `resources/js/Features/Architecture/components/ConnectionLayer.vue`
- Modify: `resources/js/Features/Architecture/components/ArchitectureExplorer.vue`

**Interfaces:**
- Consumes: aplicaciones, layout de edificios y conexiones filtradas.
- Produces: selección accesible de edificios y trazos semánticos.

- [ ] **Step 1: crear el edificio SVG como unidad aislada**

`ApplicationBuilding.vue` calcula tres polígonos desde
`{x, y, width, depth, height}` y mantiene un hit target accesible:

```js
const props = defineProps({
    application: { type: Object, required: true },
    layout: { type: Object, required: true },
    selected: { type: Boolean, default: false },
    dimmed: { type: Boolean, default: false },
});
const emit = defineEmits({ select: (id) => typeof id === 'string' });

const points = computed(() => {
    const { x, y, width: w, depth: d, height: h } = props.layout;
    const top = `${x},${y} ${x + w},${y + d / 2} ${x},${y + d} ${x - w},${y + d / 2}`;
    const left = `${x - w},${y + d / 2} ${x},${y + d} ${x},${y + d + h} ${x - w},${y + d / 2 + h}`;
    const right = `${x},${y + d} ${x + w},${y + d / 2} ${x + w},${y + d / 2 + h} ${x},${y + d + h}`;
    return { top, left, right };
});

const onKeydown = (event) => {
    if (!['Enter', ' '].includes(event.key)) return;
    event.preventDefault();
    emit('select', props.application.id);
};
```

El template usa `<g role="button" tabindex="0">`, `aria-label` con nombre y
resumen, foco visible mediante un rectángulo punteado, tres polígonos y una
etiqueta de texto. La opacidad `dimmed` nunca baja de `0.35`.

- [ ] **Step 2: crear la capa de conexiones sin capturar interacción**

`ConnectionLayer.vue` usa `<g pointer-events="none" aria-hidden="true">`,
`<defs>` con marcadores de flecha y una tabla explícita:

```js
const connectionStyle = {
    http: { dash: '', color: '#94a3b8' },
    websocket: { dash: '', color: '#38bdf8' },
    polling: { dash: '7 9', color: '#f59e0b' },
    ipc: { dash: '2 5', color: '#c084fc' },
    'usb-serial': { dash: '', color: '#fb923c' },
    mdns: { dash: '1 8', color: '#2dd4bf' },
    database: { dash: '', color: '#a78bfa' },
    'sync-outbox': { dash: '12 6', color: '#22c55e' },
    cache: { dash: '4 5', color: '#eab308' },
    'external-link': { dash: '8 8', color: '#64748b' },
};
```

Cada path usa `vector-effect="non-scaling-stroke"`, ancho 3 para selección y
1.5 para contexto. Solo `sync-outbox` puede animar `stroke-dashoffset`, y esa
animación se desactiva en `prefers-reduced-motion`.

- [ ] **Step 3: crear la escena de ecosistema**

`EcosystemScene.vue`:

- `<figure aria-labelledby="ecosystem-map-title">`;
- `<svg viewBox="0 0 1200 720" role="img">`;
- título y descripción accesibles;
- capa de terreno estática;
- `ConnectionLayer` detrás de edificios;
- un `ApplicationBuilding` por layout;
- `<figcaption>` con instrucción de teclado;
- evento `select-application`.

La escena no implementa pan ni zoom físico. El click cambia al nivel aplicación.

- [ ] **Step 4: montar el campus en el orquestador**

En `ArchitectureExplorer.vue`, sustituir el placeholder de mapa cuando
`level === 'ecosystem'`:

```vue
<EcosystemScene
    v-if="view === 'map' && level === 'ecosystem'"
    :applications="manifest.applications"
    :connections="visibleConnections"
    :layout="manifest.visualLayout"
    :selected-id="selectedApplication?.id ?? null"
    @select-application="selectApplication"
/>
```

- [ ] **Step 5: verificar escena en desktop y tablet**

```bash
npm run test:architecture
npm run build
```

Manual en 1440×900 y 1024×768:

- los cuatro edificios son distinguibles;
- etiquetas legibles sin hover;
- Tab alcanza cada edificio;
- Enter/Espacio abre la aplicación;
- tipos de conexión coinciden con la leyenda;
- movimiento reducido elimina el flujo animado.

- [ ] **Step 6: commit**

```bash
git status --short
git add resources/js/Features/Architecture/components/ApplicationBuilding.vue resources/js/Features/Architecture/components/ConnectionLayer.vue resources/js/Features/Architecture/components/EcosystemScene.vue resources/js/Features/Architecture/components/ArchitectureExplorer.vue
git commit -m "feat(atlas): renderiza campus y conexiones del ecosistema"
```

---

### Task 7: Renderizar habitaciones, modos y detalle conectado

**Files:**
- Create: `resources/js/Features/Architecture/components/ApplicationScene.vue`
- Create: `resources/js/Features/Architecture/components/ModuleRoom.vue`
- Modify: `resources/js/Features/Architecture/components/ArchitectureExplorer.vue`
- Modify: `resources/js/Features/Architecture/components/ArchitectureDetailPanel.vue`
- Modify: `resources/js/Features/Architecture/components/ArchitectureLegend.vue`

**Interfaces:**
- Consumes: aplicación seleccionada, módulos filtrados, rooms layout y grafo.
- Produces: nivel aplicación y nivel detalle completo.

- [ ] **Step 1: crear habitación seleccionable**

`ModuleRoom.vue` usa la misma semántica de teclado que `ApplicationBuilding`,
pero dibuja un bloque de planta a partir de `{column,row,columnSpan,floor}`. La
posición se calcula con constantes locales:

```js
const CELL_WIDTH = 150;
const CELL_HEIGHT = 92;
const FLOOR_GAP = 22;
const ORIGIN_X = 140;
const ORIGIN_Y = 110;
```

La habitación muestra:

- nombre abreviado;
- indicador textual accesible de estado;
- patrón de borde para `partial`, `pending`, `in-review`, `issues` y `unknown`;
- badge “offline” cuando `offlineCapability.supported` es true.

El nombre completo y la descripción viven en `aria-label`; no truncar la
información para lectores de pantalla.

- [ ] **Step 2: crear la escena de aplicación**

`ApplicationScene.vue` filtra rooms por `module.applicationId`, conserva el
orden `floor,row,column` y renderiza:

```vue
<svg viewBox="0 0 1200 720" role="img" :aria-labelledby="`${application.id}-title`">
    <title :id="`${application.id}-title`">Módulos de {{ application.name }}</title>
    <ConnectionLayer :connections="connections" :nodes="roomNodes" :mode="mode" :selected-entity-id="selectedId" />
    <ModuleRoom
        v-for="module in modules"
        :key="module.id"
        :module="module"
        :layout="roomLayout.get(module.id)"
        :selected="module.id === selectedId"
        :dimmed="Boolean(selectedId) && module.id !== selectedId"
        @select="$emit('select-module', $event)"
    />
</svg>
```

Si un filtro deja cero habitaciones, mostrar el mismo empty state de la vista
de lista fuera del SVG.

- [ ] **Step 3: conectar los tres modos visuales**

El modo modifica únicamente qué conexiones se muestran y qué texto de ayuda se
presenta:

| Mode | `viewModes` aceptados | Copy |
|---|---|---|
| `dependencies` | `dependencies` | “Qué necesita este componente para funcionar” |
| `data` | `data` | “Dónde se origina, persiste y consume la información” |
| `sync` | `sync` | “Qué se conserva localmente y cuándo viaja al backend” |

No duplicar conexiones; una conexión puede declarar varios `viewModes`.

- [ ] **Step 4: montar escena de aplicación y panel**

En `ArchitectureExplorer.vue`:

```vue
<ApplicationScene
    v-else-if="view === 'map' && selectedApplication"
    :application="selectedApplication"
    :modules="filteredModules"
    :connections="visibleConnections"
    :layout="manifest.visualLayout"
    :selected-id="selectedModule?.id ?? null"
    :mode="mode"
    @select-module="selectModule"
/>
```

El `ArchitectureDetailPanel` se monta después de escena/lista y recibe:

```vue
<ArchitectureDetailPanel
    :open="Boolean(selectedModule)"
    :entity="selectedModule"
    :manifest="manifest"
    :graph="graph"
    @close="goToApplication"
    @select-related="selectModule"
/>
```

En tablet (`max-width: 1023px`) es un drawer inferior; en desktop ocupa columna
derecha de 380 px. No usar `Teleport`: el panel forma parte de la jerarquía de
lectura y no es un diálogo modal.

- [ ] **Step 5: verificar navegación completa**

```bash
npm run validate:architecture
npm run test:architecture
npm run build
```

Manual:

1. Ecosistema → Hub → sincronización de ventas.
2. Cambiar dependencias/datos/sync y comprobar conexiones distintas.
3. Cerrar detalle con el botón; el cierre global con Escape se incorpora y verifica en Task 8.
4. Breadcrumb aplicación vuelve a la planta; ecosistema vuelve al campus.
5. Recargar la URL profunda y recuperar la selección.
6. Cambiar a lista conservando filtros y selección.

- [ ] **Step 6: commit**

```bash
git status --short
git add resources/js/Features/Architecture/components/ApplicationScene.vue resources/js/Features/Architecture/components/ModuleRoom.vue resources/js/Features/Architecture/components/ArchitectureExplorer.vue resources/js/Features/Architecture/components/ArchitectureDetailPanel.vue resources/js/Features/Architecture/components/ArchitectureLegend.vue
git commit -m "feat(atlas): agrega habitaciones flujos y detalle tecnico"
```

---

### Task 8: Endurecer accesibilidad, responsive y estados de error

**Files:**
- Modify: `resources/js/Features/Architecture/components/ArchitectureExplorer.vue`
- Modify: `resources/js/Features/Architecture/components/ArchitectureToolbar.vue`
- Modify: `resources/js/Features/Architecture/components/ArchitectureDetailPanel.vue`
- Modify: `resources/js/Features/Architecture/components/EcosystemScene.vue`
- Modify: `resources/js/Features/Architecture/components/ApplicationScene.vue`
- Modify: `resources/js/Features/Architecture/composables/useArchitectureExplorer.js`

**Interfaces:**
- Produces: experiencia operable a 1024 px, por teclado y con movimiento reducido.

- [ ] **Step 1: añadir fallback visible ante manifiesto inválido**

La validación de CI evita subir un manifiesto roto, pero el runtime debe tratar
datos ausentes sin pantalla blanca. `ArchitectureExplorer` calcula:

```js
const hasMinimumManifest = computed(() =>
    Array.isArray(props.manifest?.applications)
    && Array.isArray(props.manifest?.modules)
    && props.manifest.applications.length > 0,
);
```

Si es falso, renderizar `role="alert"` con “No se pudo cargar el catálogo de
arquitectura. Ejecuta npm run validate:architecture y revisa el manifiesto.”

- [ ] **Step 2: normalizar foco al cambiar de nivel**

Añadir un `ref` al título de la escena y, tras seleccionar app/módulo o volver
por breadcrumb, ejecutar `nextTick(() => sceneHeading.value?.focus())`. El
título usa `tabindex="-1"`; no mover foco durante cambios de filtros.

- [ ] **Step 3: implementar cierre con Escape sin interferir con búsqueda**

Registrar un listener durante montaje y retirarlo antes de desmontar:

```js
const onEscape = (event) => {
    if (event.key === 'Escape' && selectedModuleId.value) goToApplication();
};

onMounted(() => window.addEventListener('keydown', onEscape));
onBeforeUnmount(() => window.removeEventListener('keydown', onEscape));
```

- [ ] **Step 4: comprobar contraste y semántica**

Aplicar estas reglas exactas:

- texto normal mínimo `text-slate-700` sobre blanco;
- texto de escena mínimo `text-slate-200` sobre `bg-slate-950`;
- foco `focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white` en SVG;
- todos los botones icon-only tienen `aria-label`;
- todos los selects tienen `<label>` asociado;
- badges de estado contienen su label completo;
- trazos de estado se duplican en la leyenda.

- [ ] **Step 5: verificar matriz responsive y teclado**

Recorrido manual obligatorio:

| Viewport | Verificación |
|---|---|
| 1440×900 | Campus + panel lateral sin scroll horizontal |
| 1280×800 | Etiquetas y conexiones legibles |
| 1024×768 | Toolbar se envuelve; panel inferior accesible |
| 768×1024 | Vista de lista recomendada, mapa todavía utilizable |

Con teclado: sidebar → toolbar → breadcrumb → escena/lista → detalle → fuentes.
Con `prefers-reduced-motion: reduce`: cero flujo animado.

- [ ] **Step 6: build y commit**

```bash
npm run test:architecture
npm run validate:architecture
npm run build
git status --short
git add resources/js/Features/Architecture/components resources/js/Features/Architecture/composables/useArchitectureExplorer.js
git commit -m "fix(atlas): refuerza accesibilidad y experiencia tablet"
```

---

### Task 9: Documentación viva y verificación final del MVP

**Files:**
- Create: `docs/frontend/atlas-vivo.md`
- Modify: `docs/README.md`
- Modify: `docs/superpowers/specs/2026-08-06-atlas-vivo-sistema-design.md`

**Interfaces:**
- Produces: documentación viva indexada y spec con estado verdadero.

- [ ] **Step 1: crear la documentación viva**

`docs/frontend/atlas-vivo.md` debe documentar:

1. propósito y audiencia `superadmin`;
2. ruta y middleware;
3. estructura de componentes;
4. contrato del manifiesto y comandos de validación;
5. significado de estados, evidencia y conexiones;
6. proceso manual para actualizar el catálogo;
7. criterios para no inventar estados;
8. apertura de archivos fijada a commits;
9. capacidades y límites del MVP;
10. procedimiento de revisión cuando cambia uno de los cuatro repositorios.

El procedimiento de actualización es exacto:

```text
1. Fijar HEAD de los cuatro repositorios.
2. Inspeccionar código y documentación relevante.
3. Actualizar entidades y evidencia sin cambiar IDs estables.
4. Ejecutar npm run validate:architecture.
5. Ejecutar npm run test:architecture.
6. Revisar visualmente las tres vistas de conexión.
7. Registrar metadata.verifiedAt y los nuevos snapshotRefs.
```

- [ ] **Step 2: actualizar índice y estado del sistema**

En `docs/README.md` agregar bajo Frontend:

```markdown
- [Atlas vivo del sistema](frontend/atlas-vivo.md) — mapa arquitectónico 2.5D de solo lectura, exclusivo para superadmin y alimentado por manifiesto versionado
```

Agregar en “Estado del sistema”:

```markdown
| Atlas vivo del sistema | ✅ MVP de lectura · acceso exclusivo `superadmin` · manifiesto versionado |
```

Actualizar la fecha del encabezado de esa tabla a la fecha real de entrega.

- [ ] **Step 3: cerrar la spec solo después de completar el código**

Cambiar el header de la spec a:

```markdown
**Estado:** Implementado (fecha real de entrega) — doc viva: [atlas-vivo.md](../../frontend/atlas-vivo.md)
```

No hacer este cambio si cualquier criterio de aceptación sigue pendiente.

- [ ] **Step 4: ejecutar verificación completa**

```bash
php artisan test --filter=ArchitectureAtlasAccessTest
npm run validate:architecture
npm run test:architecture
npm run build
./vendor/bin/pint --dirty
git diff --check
```

Después ejecutar la matriz manual de las Tasks 5–8 con un `superadmin` y
confirmar 403 usando cuentas `admin-empresa`, `admin-sucursal` y `cajero`.

Expected: todas las pruebas y build en verde; ningún cambio de esquema, API o
datos; cero errores de consola.

- [ ] **Step 5: auditar alcance antes del commit final**

```bash
git diff --name-only 10dc8d0..HEAD
git status --short
```

Confirmar que no aparecen migraciones, modelos, controladores de negocio,
`routes/api.php`, servicios de sincronización ni archivos de otros repositorios.

- [ ] **Step 6: commit**

```bash
git status --short
git add docs/frontend/atlas-vivo.md docs/README.md docs/superpowers/specs/2026-08-06-atlas-vivo-sistema-design.md
git commit -m "docs(atlas): publica guia viva y cierra el MVP"
```

---

## Final acceptance checklist

- [ ] Ruta disponible únicamente para `superadmin`.
- [ ] Cero rutas tenant o enlaces en layouts de empresa/sucursal/caja.
- [ ] Cuatro aplicaciones y 47 módulos documentados con IDs estables.
- [ ] Inventario/stock aparece pendiente, no implementado.
- [ ] Pedidos web aparece implementado y desactivado por flag.
- [ ] Emparejamiento del Hub aparece en revisión y Android pendiente.
- [ ] Conexiones HTTP, Reverb, polling, IPC, USB, mDNS, SQL y outbox distinguibles.
- [ ] Campus, aplicación y detalle navegables con teclado.
- [ ] Vista de lista ofrece información equivalente.
- [ ] Búsqueda encuentra módulos, rutas, eventos y archivos.
- [ ] Filtros por app, estado y conexión funcionan juntos.
- [ ] Modos dependencias, datos y sync cambian las relaciones visibles.
- [ ] Enlaces de código están fijados al commit auditado.
- [ ] Manifiesto e integridad referencial validados en verde.
- [ ] Build Vite y prueba PHP de acceso en verde.
- [ ] Documentación viva e índice sincronizados.
- [ ] No hay migraciones, API nueva, escritura ni cambio al negocio.
