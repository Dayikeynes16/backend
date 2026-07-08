# Hub Fase 1 · Plan C — Renderer: pantallas de caja (Material Design 3)

> ⚠️ **HISTÓRICO / DIRECCIÓN SUPERADA (2026-07-07).** Material Design 3 y `@material/web` **fueron abandonados** como dirección del hub. La UI del hub ya se migró a **Tailwind** para tener **paridad visual con la web `carniceria-saas`** (fuente de verdad). Este plan queda como referencia histórica de cómo se construyó la Fase 1; **no lo sigas para MD3**. Dirección vigente: `carniceria-hub/docs/direccion-visual.md`.

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans. Steps use `- [ ]`. Usar skills `material-3`, electron (`.agents/skills/electron`), `vue-best-practices`.

**Goal:** Pantallas del núcleo de caja en el hub (Turno, Caja/ventas con polling, Detalle+cobro) con Material Design 3 (`@material/web`), integradas al shell por rol, consumiendo `window.hub.api.*` (Plan B).

**Architecture:** Vue 3 + vue-router. `@material/web` web components (registrados globalmente; Vue configurado para tratar `md-*` como custom elements). Tema MD3 por tokens CSS. Cada vista maneja estados cargando/error/offline/sin-permiso con mensajes en español a partir de los errores tipados del `HttpClient`.

**Prerequisito:** Plan B verde (hub main API). Rama hub `feature/hub-modulos-fase1`.

---

## File Structure
- Modify: `package.json` — dep `@material/web`.
- Modify: `vite.config.js` — `isCustomElement` para `md-*`.
- Create: `src/renderer/material.js` — imports de componentes `@material/web` usados.
- Create: `src/renderer/theme.css` — tokens MD3 (color/tipografía).
- Create: `src/renderer/lib/apiError.js` — mapea error tipado → mensaje español (puro, testeable).
- Create: `src/renderer/views/ShiftView.vue`, `SalesView.vue`, `SaleDetailView.vue`.
- Modify: `src/renderer/router.js` — rutas `shift`, `sales`, `sale-detail`.
- Modify: `src/renderer/views/ShellLayout.vue` — nav "Turno" y "Caja".
- Modify: `src/renderer/main.js` — importar `material.js` y `theme.css`.
- Create: `test/apiError.test.js`.

---

### Task C1: Instalar @material/web + custom elements + tema

- [ ] **Step 1:** `npm install @material/web`
- [ ] **Step 2:** En `vite.config.js`, configurar el plugin vue:

```js
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  base: './',
  plugins: [
    vue({
      template: { compilerOptions: { isCustomElement: (tag) => tag.startsWith('md-') } },
    }),
  ],
  build: { outDir: 'dist' },
});
```

- [ ] **Step 3:** Crear `src/renderer/material.js` (importa los componentes usados):

```js
import '@material/web/button/filled-button.js';
import '@material/web/button/text-button.js';
import '@material/web/button/outlined-button.js';
import '@material/web/textfield/outlined-text-field.js';
import '@material/web/list/list.js';
import '@material/web/list/list-item.js';
import '@material/web/select/outlined-select.js';
import '@material/web/select/select-option.js';
import '@material/web/progress/circular-progress.js';
import '@material/web/divider/divider.js';
```

- [ ] **Step 4:** Crear `src/renderer/theme.css`:

```css
:root {
  --md-sys-color-primary: #8e1c1c;
  --md-sys-color-on-primary: #ffffff;
  --md-ref-typeface-brand: system-ui, sans-serif;
  --md-ref-typeface-plain: system-ui, sans-serif;
}
.muted { color: #6b7280; }
.state { padding: 24px; text-align: center; color: #6b7280; }
.error-banner { background: #fee2e2; color: #991b1b; padding: 10px 14px; border-radius: 8px; margin-bottom: 12px; }
.totals { display: flex; gap: 16px; flex-wrap: wrap; }
.totals .stat { flex: 1; min-width: 120px; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px; }
.totals .stat b { display: block; font-size: 24px; }
.sale-row { display: flex; justify-content: space-between; width: 100%; }
```

- [ ] **Step 5:** En `src/renderer/main.js`, agregar imports al inicio:

```js
import './material.js';
import './theme.css';
```

- [ ] **Step 6:** `npm run build:renderer` → compila. Commit `feat(hub): material design 3 setup (@material/web)`.

---

### Task C2: `apiError` helper (TDD)

- [ ] **Step 1: test `test/apiError.test.js`**

```js
import { describe, it, expect } from 'vitest';
import { apiErrorMessage } from '../src/renderer/lib/apiError.js';

describe('apiErrorMessage', () => {
  it('maps known error codes to Spanish', () => {
    expect(apiErrorMessage('offline')).toMatch(/sin conexión/i);
    expect(apiErrorMessage('forbidden')).toMatch(/permiso/i);
    expect(apiErrorMessage('conflict')).toMatch(/turno/i);
    expect(apiErrorMessage('validation')).toMatch(/datos/i);
    expect(apiErrorMessage('unauthorized')).toMatch(/sesión/i);
  });
  it('falls back for unknown codes', () => {
    expect(apiErrorMessage('weird')).toMatch(/error/i);
    expect(apiErrorMessage(undefined)).toMatch(/error/i);
  });
  it('prefers a server-provided message when present', () => {
    expect(apiErrorMessage('conflict', 'Abre un turno antes de cobrar.')).toBe('Abre un turno antes de cobrar.');
  });
});
```

- [ ] **Step 2:** run → FALLA.
- [ ] **Step 3: implementar `src/renderer/lib/apiError.js`**

```js
const MESSAGES = {
  offline: 'Sin conexión con el servidor. Reintenta.',
  forbidden: 'No tienes permiso para esta acción.',
  conflict: 'Conflicto con el estado del turno o la venta.',
  validation: 'Revisa los datos ingresados.',
  unauthorized: 'Tu sesión expiró. Inicia sesión de nuevo.',
  not_found: 'No encontrado.',
  server: 'Error del servidor. Intenta de nuevo.',
};

/**
 * @param {string} [code] error tipado del HttpClient
 * @param {string} [serverMessage] mensaje del backend, si vino
 */
export function apiErrorMessage(code, serverMessage = null) {
  if (serverMessage) {
    return serverMessage;
  }
  return MESSAGES[code] ?? 'Ocurrió un error. Intenta de nuevo.';
}
```

- [ ] **Step 4:** run → PASA. Commit `feat(hub): typed API error messages (TDD)`.

---

### Task C3: ShiftView

- [ ] **Step 1: crear `src/renderer/views/ShiftView.vue`**

```vue
<script setup>
import { ref, onMounted } from 'vue';
import { apiErrorMessage } from '../lib/apiError.js';

const loading = ref(true);
const error = ref('');
const shift = ref(null);
const openingAmount = ref(0);
const declaredAmount = ref(0);
const busy = ref(false);

async function load() {
  loading.value = true;
  error.value = '';
  const res = await window.hub.api.shift.current();
  loading.value = false;
  if (res.ok) {
    shift.value = res.data.data;
  } else {
    error.value = apiErrorMessage(res.error, res.message);
  }
}

async function open() {
  busy.value = true;
  const res = await window.hub.api.shift.open(Number(openingAmount.value) || 0);
  busy.value = false;
  if (res.ok) {
    shift.value = res.data.data;
  } else {
    error.value = apiErrorMessage(res.error, res.message);
  }
}

async function close() {
  busy.value = true;
  const res = await window.hub.api.shift.close({ declared_amount: Number(declaredAmount.value) || 0 });
  busy.value = false;
  if (res.ok) {
    shift.value = res.data.data;
  } else {
    error.value = apiErrorMessage(res.error, res.message);
  }
}
onMounted(load);
</script>

<template>
  <div>
    <h2>Turno</h2>
    <div v-if="loading" class="state"><md-circular-progress indeterminate /></div>
    <p v-else-if="error" class="error-banner">{{ error }}</p>

    <template v-else>
      <div v-if="!shift || shift.closed" class="card">
        <p class="muted">No tienes un turno abierto.</p>
        <md-outlined-text-field label="Monto de apertura" type="number" :value="String(openingAmount)"
          @input="openingAmount = $event.target.value" />
        <div style="margin-top:12px"><md-filled-button :disabled="busy" @click="open">Abrir turno</md-filled-button></div>
      </div>

      <div v-else class="card">
        <p>Turno abierto desde <strong>{{ new Date(shift.opened_at).toLocaleString() }}</strong></p>
        <p>Fondo inicial: <strong>{{ shift.opening_amount }}</strong></p>
        <md-outlined-text-field label="Efectivo declarado" type="number" :value="String(declaredAmount)"
          @input="declaredAmount = $event.target.value" />
        <div style="margin-top:12px"><md-filled-button :disabled="busy" @click="close">Cerrar turno</md-filled-button></div>
      </div>
    </template>
  </div>
</template>
```

- [ ] **Step 2:** `npm run build:renderer` → compila.

---

### Task C4: SalesView (polling) + SaleDetailView (cobro)

- [ ] **Step 1: crear `src/renderer/views/SalesView.vue`**

```vue
<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import { apiErrorMessage } from '../lib/apiError.js';

const router = useRouter();
const sales = ref([]);
const error = ref('');
const loading = ref(true);
let timer = null;

async function refresh() {
  const res = await window.hub.api.sales.list();
  loading.value = false;
  if (res.ok) {
    sales.value = res.data.data ?? [];
    error.value = '';
  } else {
    error.value = apiErrorMessage(res.error, res.message);
  }
}
function openSale(id) {
  router.push({ name: 'sale-detail', params: { id } });
}
onMounted(() => { refresh(); timer = setInterval(refresh, 4000); });
onUnmounted(() => clearInterval(timer));
</script>

<template>
  <div>
    <h2>Caja — ventas por cobrar</h2>
    <p v-if="error" class="error-banner">{{ error }}</p>
    <div v-if="loading" class="state"><md-circular-progress indeterminate /></div>
    <p v-else-if="!sales.length" class="state">No hay ventas pendientes.</p>
    <md-list v-else>
      <md-list-item v-for="s in sales" :key="s.id" type="button" @click="openSale(s.id)">
        <div class="sale-row">
          <span>{{ s.folio }} · {{ s.origin_name || s.origin }}</span>
          <span>Pendiente: ${{ s.amount_pending }}</span>
        </div>
      </md-list-item>
    </md-list>
  </div>
</template>
```

- [ ] **Step 2: crear `src/renderer/views/SaleDetailView.vue`**

```vue
<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { apiErrorMessage } from '../lib/apiError.js';

const route = useRoute();
const router = useRouter();
const sale = ref(null);
const error = ref('');
const loading = ref(true);
const method = ref('cash');
const amount = ref(0);
const busy = ref(false);
const feedback = ref('');
let payRef = null;

async function load() {
  loading.value = true;
  const res = await window.hub.api.sales.show(Number(route.params.id));
  loading.value = false;
  if (res.ok) {
    sale.value = res.data.data;
    amount.value = sale.value.amount_pending;
  } else {
    error.value = apiErrorMessage(res.error, res.message);
  }
}

async function pay() {
  busy.value = true;
  feedback.value = '';
  // Idempotencia: un client_reference estable por intento; se reusa en reintentos.
  if (!payRef) {
    payRef = crypto.randomUUID();
  }
  const res = await window.hub.api.sales.pay(sale.value.id, {
    method: method.value,
    amount: Number(amount.value),
    clientReference: payRef,
  });
  busy.value = false;
  if (res.ok) {
    payRef = null;
    sale.value = res.data.sale.data ?? res.data.sale;
    const change = res.data.change ?? 0;
    feedback.value = change > 0 ? `Cobrado. Cambio: $${change}` : 'Cobrado.';
    if (sale.value.amount_pending <= 0) {
      setTimeout(() => router.push({ name: 'sales' }), 800);
    }
  } else {
    // En error de red se conserva payRef para reintento idempotente.
    error.value = apiErrorMessage(res.error, res.message);
  }
}
onMounted(load);
</script>

<template>
  <div>
    <md-text-button @click="router.push({ name: 'sales' })">← Volver</md-text-button>
    <div v-if="loading" class="state"><md-circular-progress indeterminate /></div>
    <p v-else-if="error" class="error-banner">{{ error }}</p>
    <template v-else-if="sale">
      <h2>Venta {{ sale.folio }}</h2>
      <div class="totals">
        <div class="stat"><b>${{ sale.total }}</b> Total</div>
        <div class="stat"><b>${{ sale.amount_paid }}</b> Pagado</div>
        <div class="stat"><b>${{ sale.amount_pending }}</b> Pendiente</div>
      </div>
      <md-list>
        <md-list-item v-for="i in sale.items" :key="i.id">
          <div class="sale-row"><span>{{ i.product_name }} ×{{ i.quantity }}</span><span>${{ i.subtotal }}</span></div>
        </md-list-item>
      </md-list>

      <div class="card" v-if="sale.amount_pending > 0">
        <h3>Cobrar</h3>
        <md-outlined-select label="Método" :value="method" @change="method = $event.target.value">
          <md-select-option value="cash"><div slot="headline">Efectivo</div></md-select-option>
          <md-select-option value="card"><div slot="headline">Tarjeta</div></md-select-option>
          <md-select-option value="transfer"><div slot="headline">Transferencia</div></md-select-option>
        </md-outlined-select>
        <md-outlined-text-field label="Monto" type="number" :value="String(amount)"
          @input="amount = $event.target.value" />
        <div style="margin-top:12px"><md-filled-button :disabled="busy" @click="pay">Registrar cobro</md-filled-button></div>
        <p v-if="feedback" class="muted">{{ feedback }}</p>
      </div>
      <p v-else class="muted">Venta cobrada por completo.</p>
    </template>
  </div>
</template>
```

- [ ] **Step 3: rutas en `src/renderer/router.js`** — agregar a los children del ShellLayout:

```js
      { path: 'shift', name: 'shift', component: ShiftView },
      { path: 'sales', name: 'sales', component: SalesView },
      { path: 'sales/:id', name: 'sale-detail', component: SaleDetailView },
```

con sus imports:

```js
import ShiftView from './views/ShiftView.vue';
import SalesView from './views/SalesView.vue';
import SaleDetailView from './views/SaleDetailView.vue';
```

- [ ] **Step 4: nav en `src/renderer/views/ShellLayout.vue`** — agregar tras "Dispositivo":

```html
        <RouterLink :to="{ name: 'shift' }">Turno</RouterLink>
        <RouterLink :to="{ name: 'sales' }">Caja</RouterLink>
```

- [ ] **Step 5:** `npm run build:renderer` → compila. `npm test` → 65 verde (apiError +1 archivo). Commit `feat(hub): cash screens (shift, sales polling, sale detail + payment) in MD3`.

---

### Task C5: Verificación visual (Electron real)

- [ ] **Step 1:** `npm start` (prestart recompila SQLite para Electron). Login `sucursal@eltoro.test` / `password` (backend Sail con la rama feature/hub-modulos-fase1 sirviendo `/api/v1/hub/*`).
- [ ] **Step 2:** Ir a **Turno** → Abrir turno. Ir a **Caja** → ver ventas activas (crear una vía báscula/seed si hace falta) → abrir una → **Registrar cobro** → ver cambio y que pase a cobrada. Volver a **Turno** → Cerrar turno.
- [ ] **Step 3:** Verificar estados: sin turno no deja cobrar (409 → mensaje); cajero no ve "Config".

---

## Self-Review
- @material/web + custom elements + tema → C1. ✅
- Estados/errores en español (tipados) → C2 + uso en vistas. ✅
- ShiftView (abrir/cerrar) → C3. ✅
- SalesView polling + SaleDetailView cobro idempotente (client_reference por intento) → C4. ✅
- Integración al shell por rol → C4 (nav + rutas). ✅
- Verificación real → C5. ✅
