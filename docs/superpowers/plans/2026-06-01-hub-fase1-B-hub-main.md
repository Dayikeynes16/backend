# Hub Fase 1 · Plan B — Hub main: cliente API autenticado + IPC

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans. Steps use `- [ ]`.

**Goal:** Capa en el proceso main del hub para consumir `/api/v1/hub/*` con el token Sanctum, exponiéndola al renderer por IPC como `window.hub.api.*`, sin que el renderer vea el token.

**Architecture:** `httpClient` genérico (token vía `AuthService`, errores tipados, 401→logout) + módulos `api/shift.js` y `api/sales.js` + handlers IPC + preload. Núcleo Node-puro, testeable con Vitest (fetch + authService inyectados). Usar skill electron (`.agents/skills/electron`).

**Prerequisito:** Plan A (endpoints backend) verde. Rama hub `feature/hub-modulos-fase1` (de `feature/hub-auth-login`).

---

## File Structure
- Modify: `src/main/auth/authService.js` — exponer `getToken()` público.
- Create: `src/main/api/httpClient.js` — cliente HTTP autenticado genérico (errores tipados).
- Create: `src/main/api/shift.js`, `src/main/api/sales.js` — módulos por dominio.
- Modify: `src/main/ipc.js` — handlers `api:shift:*`, `api:sales:*`.
- Modify: `src/preload/preload.cjs` — `window.hub.api.shift.*`, `window.hub.api.sales.*`.
- Modify: `src/main/index.js` — instanciar y cablear.
- Create: `test/httpClient.test.js`, `test/api-shift.test.js`, `test/api-sales.test.js`.

---

### Task B1: `AuthService.getToken()` público

- [ ] **Step 1:** En `src/main/auth/authService.js`, agregar método público:

```js
  /** Devuelve el token Bearer descifrado, o null. */
  getToken() {
    return this._readToken();
  }
```

- [ ] **Step 2:** `npx vitest run test/authService.test.js` → sigue verde (7).
- [ ] **Step 3:** commit `feat(hub): expose AuthService.getToken()`.

---

### Task B2: `httpClient` (TDD)

- [ ] **Step 1: test `test/httpClient.test.js`**

```js
import { describe, it, expect } from 'vitest';
import { HttpClient } from '../src/main/api/httpClient.js';

function fakeFetch(responder) {
  const calls = [];
  const fn = async (url, options) => { calls.push({ url, options }); return responder({ url, options }); };
  fn.calls = calls;
  return fn;
}
const cfg = { get: (k) => (k === 'backendUrl' ? 'https://api.test' : undefined) };
const auth = { getToken: () => 'tok-1', logout: async () => { auth.loggedOut = true; } };

describe('HttpClient', () => {
  it('GET adds Bearer token and returns parsed data', async () => {
    const fetch = fakeFetch(() => ({ ok: true, status: 200, json: async () => ({ data: [1, 2] }) }));
    const res = await new HttpClient(cfg, auth, fetch).get('/api/v1/hub/sales');
    expect(res.ok).toBe(true);
    expect(res.data).toEqual({ data: [1, 2] });
    expect(fetch.calls[0].url).toBe('https://api.test/api/v1/hub/sales');
    expect(fetch.calls[0].options.headers.Authorization).toBe('Bearer tok-1');
  });

  it('POST sends JSON body', async () => {
    const fetch = fakeFetch(() => ({ ok: true, status: 201, json: async () => ({ ok: 1 }) }));
    const client = new HttpClient(cfg, auth, fetch);
    await client.post('/api/v1/hub/shift/open', { opening_amount: 5 });
    expect(fetch.calls[0].options.method).toBe('POST');
    expect(JSON.parse(fetch.calls[0].options.body)).toEqual({ opening_amount: 5 });
  });

  it('maps 403/409/422/404/500 to typed errors', async () => {
    const mk = (status) => new HttpClient(cfg, auth, fakeFetch(() => ({ ok: false, status, json: async () => ({ message: 'x' }) })));
    expect((await mk(403).get('/x')).error).toBe('forbidden');
    expect((await mk(409).get('/x')).error).toBe('conflict');
    expect((await mk(422).get('/x')).error).toBe('validation');
    expect((await mk(404).get('/x')).error).toBe('not_found');
    expect((await mk(500).get('/x')).error).toBe('server');
  });

  it('maps a thrown network error to offline', async () => {
    const client = new HttpClient(cfg, auth, async () => { throw new Error('down'); });
    expect((await client.get('/x')).error).toBe('offline');
  });

  it('on 401 logs out and returns unauthorized', async () => {
    const a = { getToken: () => 't', loggedOut: false, logout: async () => { a.loggedOut = true; } };
    const client = new HttpClient(cfg, a, fakeFetch(() => ({ ok: false, status: 401, json: async () => ({}) })));
    const res = await client.get('/x');
    expect(res.error).toBe('unauthorized');
    expect(a.loggedOut).toBe(true);
  });

  it('returns offline without calling fetch when backendUrl is unset', async () => {
    const emptyCfg = { get: () => '' };
    const fetch = fakeFetch(() => ({ ok: true, status: 200, json: async () => ({}) }));
    const res = await new HttpClient(emptyCfg, auth, fetch).get('/x');
    expect(res.error).toBe('offline');
    expect(fetch.calls).toHaveLength(0);
  });
});
```

- [ ] **Step 2:** run → FALLA.
- [ ] **Step 3: implementar `src/main/api/httpClient.js`**

```js
const ERROR_BY_STATUS = {
  401: 'unauthorized',
  403: 'forbidden',
  404: 'not_found',
  409: 'conflict',
  422: 'validation',
};

export class HttpClient {
  /**
   * @param {{get:(k:string)=>any}} config electron-store-like
   * @param {{getToken:()=>?string, logout:()=>Promise<any>}} auth
   * @param {typeof fetch} [fetchImpl]
   */
  constructor(config, auth, fetchImpl = globalThis.fetch) {
    this.config = config;
    this.auth = auth;
    this.fetch = fetchImpl;
  }

  get base() {
    return (this.config.get('backendUrl') || '').replace(/\/+$/, '');
  }

  get(path) {
    return this.request('GET', path);
  }

  post(path, body) {
    return this.request('POST', path, body);
  }

  async request(method, path, body) {
    if (!this.base) {
      return { ok: false, error: 'offline', status: 0 };
    }
    const headers = { Accept: 'application/json', Authorization: `Bearer ${this.auth.getToken()}` };
    const init = { method, headers };
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }
    try {
      const res = await this.fetch(`${this.base}${path}`, init);
      const data = await res.json().catch(() => ({}));
      if (res.ok) {
        return { ok: true, status: res.status, data };
      }
      if (res.status === 401) {
        await this.auth.logout();
      }
      return {
        ok: false,
        status: res.status,
        error: ERROR_BY_STATUS[res.status] ?? 'server',
        message: data.message ?? null,
        data,
      };
    } catch {
      return { ok: false, error: 'offline', status: 0 };
    }
  }
}
```

- [ ] **Step 4:** run → PASA (7). Commit `feat(hub): authenticated http client with typed errors (TDD)`.

---

### Task B3: `api/shift.js` y `api/sales.js` (TDD)

- [ ] **Step 1: test `test/api-shift.test.js` y `test/api-sales.test.js`**

```js
// test/api-shift.test.js
import { describe, it, expect } from 'vitest';
import { ShiftApi } from '../src/main/api/shift.js';

function clientSpy() {
  const calls = [];
  return {
    calls,
    get: async (p) => { calls.push(['GET', p]); return { ok: true, data: { data: null } }; },
    post: async (p, b) => { calls.push(['POST', p, b]); return { ok: true, data: { data: { id: 1 } } }; },
  };
}

describe('ShiftApi', () => {
  it('current GETs shift/current', async () => {
    const c = clientSpy();
    await new ShiftApi(c).current();
    expect(c.calls[0]).toEqual(['GET', '/api/v1/hub/shift/current']);
  });
  it('open POSTs shift/open with opening_amount', async () => {
    const c = clientSpy();
    await new ShiftApi(c).open(500);
    expect(c.calls[0]).toEqual(['POST', '/api/v1/hub/shift/open', { opening_amount: 500 }]);
  });
  it('close POSTs shift/close with declared payload', async () => {
    const c = clientSpy();
    await new ShiftApi(c).close({ declared_amount: 10 });
    expect(c.calls[0]).toEqual(['POST', '/api/v1/hub/shift/close', { declared_amount: 10 }]);
  });
});
```

```js
// test/api-sales.test.js
import { describe, it, expect } from 'vitest';
import { SalesApi } from '../src/main/api/sales.js';

function clientSpy() {
  const calls = [];
  return {
    calls,
    get: async (p) => { calls.push(['GET', p]); return { ok: true, data: { data: [] } }; },
    post: async (p, b) => { calls.push(['POST', p, b]); return { ok: true, data: {} }; },
  };
}

describe('SalesApi', () => {
  it('list GETs sales', async () => {
    const c = clientSpy();
    await new SalesApi(c).list();
    expect(c.calls[0]).toEqual(['GET', '/api/v1/hub/sales']);
  });
  it('show GETs sales/{id}', async () => {
    const c = clientSpy();
    await new SalesApi(c).show(7);
    expect(c.calls[0]).toEqual(['GET', '/api/v1/hub/sales/7']);
  });
  it('pay POSTs sales/{id}/payments with method/amount/client_reference', async () => {
    const c = clientSpy();
    await new SalesApi(c).pay(7, { method: 'cash', amount: 100, clientReference: 'r1' });
    expect(c.calls[0]).toEqual(['POST', '/api/v1/hub/sales/7/payments', { method: 'cash', amount: 100, client_reference: 'r1' }]);
  });
});
```

- [ ] **Step 2:** run → FALLA.
- [ ] **Step 3: implementar**

```js
// src/main/api/shift.js
export class ShiftApi {
  constructor(http) { this.http = http; }
  current() { return this.http.get('/api/v1/hub/shift/current'); }
  open(openingAmount = 0) { return this.http.post('/api/v1/hub/shift/open', { opening_amount: openingAmount }); }
  close(declared = {}) { return this.http.post('/api/v1/hub/shift/close', declared); }
}
```

```js
// src/main/api/sales.js
export class SalesApi {
  constructor(http) { this.http = http; }
  list() { return this.http.get('/api/v1/hub/sales'); }
  show(id) { return this.http.get(`/api/v1/hub/sales/${id}`); }
  pay(saleId, { method, amount, clientReference }) {
    return this.http.post(`/api/v1/hub/sales/${saleId}/payments`, {
      method, amount, client_reference: clientReference,
    });
  }
}
```

- [ ] **Step 4:** run → PASA. Commit `feat(hub): shift & sales api modules (TDD)`.

---

### Task B4: IPC + preload + index

- [ ] **Step 1:** En `src/main/ipc.js`, ampliar la firma a `{ outbox, catalog, config, auth, api, getOnline }` y añadir:

```js
  ipcMain.handle('api:shift:current', () => api.shift.current());
  ipcMain.handle('api:shift:open', (_e, amount) => api.shift.open(amount));
  ipcMain.handle('api:shift:close', (_e, declared) => api.shift.close(declared));
  ipcMain.handle('api:sales:list', () => api.sales.list());
  ipcMain.handle('api:sales:show', (_e, id) => api.sales.show(id));
  ipcMain.handle('api:sales:pay', (_e, { saleId, payment }) => api.sales.pay(saleId, payment));
```

- [ ] **Step 2:** En `src/preload/preload.cjs`, agregar dentro de `exposeInMainWorld('hub', {...})`:

```js
  api: {
    shift: {
      current: () => ipcRenderer.invoke('api:shift:current'),
      open: (amount) => ipcRenderer.invoke('api:shift:open', amount),
      close: (declared) => ipcRenderer.invoke('api:shift:close', declared),
    },
    sales: {
      list: () => ipcRenderer.invoke('api:sales:list'),
      show: (id) => ipcRenderer.invoke('api:sales:show', id),
      pay: (saleId, payment) => ipcRenderer.invoke('api:sales:pay', { saleId, payment }),
    },
  },
```

- [ ] **Step 3:** En `src/main/index.js`, tras crear `auth`, agregar:

```js
import { HttpClient } from './api/httpClient.js';
import { ShiftApi } from './api/shift.js';
import { SalesApi } from './api/sales.js';
// ...
  const http = new HttpClient(config, auth);
  const api = { shift: new ShiftApi(http), sales: new SalesApi(http) };
```

y pasar `api` a `registerIpc({ outbox, catalog, config, auth, api, getOnline: () => online })`.

- [ ] **Step 4:** `npx vitest run` → toda la suite verde. `node --check` de ipc/preload/index. Commit `feat(hub): wire shift/sales api into IPC, preload and main`.

---

## Self-Review
- httpClient autenticado + errores tipados + 401→logout + guard sin-backend → B2. ✅
- shift.js/sales.js con contrato correcto → B3. ✅
- IPC + preload `window.hub.api.*`, token nunca en renderer → B4. ✅
- getToken expuesto → B1. ✅
