# Carnicería Hub — Plan 2: App Electron `carniceria-hub`

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir la app de escritorio `carniceria-hub`: recibe ventas de las básculas por LAN (contrato `/api/v1`), las persiste en un outbox SQLite durable, y las reenvía al backend con reintentos e idempotencia; cachea el catálogo para servir offline; expone un monitor de estado en una app de bandeja (tray) always-on.

**Architecture:** Proyecto Node nuevo, separado. El **núcleo** (config, DB, repos, servidor Fastify, cliente backend, sync worker, refresher, connectivity) es Node puro y testeable con Vitest sin Electron. El **shell Electron** (proceso main: tray, ventana, ciclo de vida, single-instance) instancia y cablea ese núcleo, y el **renderer** (Vue) muestra el monitor vía IPC.

**Tech Stack:** Electron 33, Fastify 5, better-sqlite3 12, Vue 3 + Vite 6 (renderer), electron-store 10 (config), bonjour-service 1 (mDNS), Vitest 2 (tests), undici/fetch nativo de Node 20 (cliente HTTP).

**Spec:** `carniceria-saas/docs/superpowers/specs/2026-05-29-carniceria-hub-buffer-sync-design.md`.

**Prerequisito:** Plan 1 (idempotencia backend) ya implementado — el SyncWorker depende de que `POST /api/v1/sales` acepte `client_reference`.

**Ubicación:** `/Users/sebas/Documents/version 2/carniceria-hub/` (4º proyecto del workspace).

---

## File Structure

```
carniceria-hub/
  package.json                      # scripts, deps
  vite.config.js                    # build del renderer
  vitest.config.js                  # tests del núcleo
  index.html                        # entry del renderer
  electron.vite NO se usa; arranque manual
  src/
    main/
      index.js                      # Electron: app, tray, ventana, single-instance, cableado
      config.js                     # lectura/escritura de configuración (electron-store)
      logger.js                     # logger mínimo a archivo + consola
      db/
        database.js                 # init better-sqlite3 + esquema (idempotente)
        outboxRepo.js               # CRUD + transiciones de outbox_sales
        catalogRepo.js              # read/write de catalog_*
      server/
        localApiServer.js           # Fastify: expone /api/v1
      sync/
        backendClient.js            # HTTP al backend con X-Api-Key real
        syncWorker.js               # drena outbox con reintentos/backoff
        catalogRefresher.js         # pull periódico de catálogo
        connectivityMonitor.js      # online/offline + despierta al worker
      discovery/
        mdns.js                     # anuncio mDNS opcional
      ipc.js                        # handlers IPC main↔renderer
    preload/
      preload.js                    # contextBridge: API segura para el renderer
    renderer/
      main.js                       # bootstrap Vue
      App.vue                       # monitor (conexión, cola, fallidas, config)
      style.css
  test/
    outboxRepo.test.js
    catalogRepo.test.js
    backendClient.test.js
    syncWorker.test.js
    localApiServer.test.js
```

**Decisiones de diseño de archivos:**
- `db/`, `server/`, `sync/` separan responsabilidades. Cada repo encapsula su(s) tabla(s).
- El núcleo no importa nada de `electron` → testeable en Node puro. Sólo `main/index.js`, `config.js`, `ipc.js` y `preload.js` tocan Electron.
- `better-sqlite3` se usa en Node para dev/tests; el rebuild contra el ABI de Electron es tema de empaquetado (fuera de alcance de este plan — ver Roadmap).

---

### Task 1: Scaffold del proyecto

**Files:**
- Create: `carniceria-hub/package.json`
- Create: `carniceria-hub/.gitignore`
- Create: `carniceria-hub/vitest.config.js`

- [ ] **Step 1: Crear `package.json`**

```json
{
  "name": "carniceria-hub",
  "private": true,
  "version": "0.1.0",
  "type": "module",
  "main": "src/main/index.js",
  "scripts": {
    "dev:renderer": "vite",
    "build:renderer": "vite build",
    "start": "electron .",
    "test": "vitest run",
    "test:watch": "vitest"
  },
  "dependencies": {
    "bonjour-service": "^1.2.1",
    "electron-store": "^10.0.0",
    "fastify": "^5.0.0",
    "better-sqlite3": "^12.0.0"
  },
  "devDependencies": {
    "@vitejs/plugin-vue": "^6.0.0",
    "electron": "^33.0.0",
    "vite": "^6.0.0",
    "vitest": "^2.0.0",
    "vue": "^3.4.0"
  }
}
```

- [ ] **Step 2: Crear `.gitignore`**

```
node_modules
dist
*.log
hub-data/
```

- [ ] **Step 3: Crear `vitest.config.js`**

```js
import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'node',
    include: ['test/**/*.test.js'],
  },
});
```

- [ ] **Step 4: Instalar dependencias**

Run: `cd "/Users/sebas/Documents/version 2/carniceria-hub" && npm install`
Expected: instala sin error. `better-sqlite3` compila su binario nativo (node-gyp). Si falla la compilación, ver Troubleshooting al final.

- [ ] **Step 5: Commit**

```bash
cd "/Users/sebas/Documents/version 2/carniceria-hub"
git init -q 2>/dev/null || true
git add package.json .gitignore vitest.config.js
git commit -q -m "chore(hub): scaffold carniceria-hub project"
```

(Si el workspace ya es un repo, omitir `git init`; el commit se hace donde corresponda.)

---

### Task 2: Base de datos SQLite (esquema)

**Files:**
- Create: `carniceria-hub/src/main/db/database.js`

- [ ] **Step 1: Implementar `database.js`**

```js
import Database from 'better-sqlite3';

/**
 * Abre/crea la base SQLite y asegura el esquema (idempotente).
 * @param {string} filePath Ruta al archivo .sqlite (o ':memory:' en tests).
 * @returns {import('better-sqlite3').Database}
 */
export function openDatabase(filePath) {
  const db = new Database(filePath);
  db.pragma('journal_mode = WAL');
  db.pragma('foreign_keys = ON');

  db.exec(`
    CREATE TABLE IF NOT EXISTS outbox_sales (
      client_reference TEXT PRIMARY KEY,
      payload          TEXT NOT NULL,
      status           TEXT NOT NULL DEFAULT 'queued',
      attempts         INTEGER NOT NULL DEFAULT 0,
      last_error       TEXT,
      backend_sale_id  INTEGER,
      backend_folio    TEXT,
      received_at      TEXT NOT NULL,
      synced_at        TEXT
    );

    CREATE INDEX IF NOT EXISTS outbox_status_received_idx
      ON outbox_sales (status, received_at);

    CREATE TABLE IF NOT EXISTS catalog_snapshots (
      kind        TEXT PRIMARY KEY,
      data        TEXT NOT NULL,
      fetched_at  TEXT NOT NULL
    );
  `);

  return db;
}
```

- [ ] **Step 2: Smoke test manual**

Run: `cd "/Users/sebas/Documents/version 2/carniceria-hub" && node -e "import('./src/main/db/database.js').then(m=>{const db=m.openDatabase(':memory:');console.log(db.prepare('SELECT count(*) c FROM outbox_sales').get().c===0?'OK':'BAD');})"`
Expected: imprime `OK`.

- [ ] **Step 3: Commit**

```bash
git add src/main/db/database.js
git commit -q -m "feat(hub): sqlite schema (outbox_sales, catalog_snapshots)"
```

---

### Task 3: `outboxRepo` (TDD)

**Files:**
- Create: `carniceria-hub/test/outboxRepo.test.js`
- Create: `carniceria-hub/src/main/db/outboxRepo.js`

- [ ] **Step 1: Escribir el test que falla**

```js
import { describe, it, expect, beforeEach } from 'vitest';
import { openDatabase } from '../src/main/db/database.js';
import { OutboxRepo } from '../src/main/db/outboxRepo.js';

describe('OutboxRepo', () => {
  let repo;

  beforeEach(() => {
    repo = new OutboxRepo(openDatabase(':memory:'));
  });

  const payload = { payment_method: 'cash', items: [{ product_id: 1, quantity: 1 }] };

  it('enqueues a sale with status queued and a generated client_reference', () => {
    const row = repo.enqueue(payload);
    expect(row.client_reference).toMatch(/[0-9a-f-]{36}/);
    expect(row.status).toBe('queued');
    expect(row.attempts).toBe(0);
    expect(JSON.parse(row.payload)).toEqual(payload);
  });

  it('uses a provided client_reference when given (idempotent enqueue)', () => {
    const ref = 'fixed-ref-1';
    const a = repo.enqueue(payload, ref);
    const b = repo.enqueue(payload, ref);
    expect(a.client_reference).toBe(ref);
    expect(b.client_reference).toBe(ref);
    expect(repo.count()).toBe(1);
  });

  it('returns pending sales (queued or failed-not-terminal) in arrival order', () => {
    const r1 = repo.enqueue(payload);
    const r2 = repo.enqueue(payload);
    const pending = repo.pending();
    expect(pending.map((r) => r.client_reference)).toEqual([r1.client_reference, r2.client_reference]);
  });

  it('marks a sale as synced with backend data', () => {
    const { client_reference } = repo.enqueue(payload);
    repo.markSynced(client_reference, { id: 42, folio: 'S-00042' });
    const row = repo.find(client_reference);
    expect(row.status).toBe('synced');
    expect(row.backend_sale_id).toBe(42);
    expect(row.backend_folio).toBe('S-00042');
    expect(row.synced_at).toBeTruthy();
    expect(repo.pending()).toHaveLength(0);
  });

  it('marks a transient failure: back to queued, increments attempts, records error', () => {
    const { client_reference } = repo.enqueue(payload);
    repo.markRetry(client_reference, 'network down');
    const row = repo.find(client_reference);
    expect(row.status).toBe('queued');
    expect(row.attempts).toBe(1);
    expect(row.last_error).toBe('network down');
    expect(repo.pending()).toHaveLength(1);
  });

  it('marks a terminal failure: status failed, out of pending', () => {
    const { client_reference } = repo.enqueue(payload);
    repo.markFailed(client_reference, 'invalid product (422)');
    const row = repo.find(client_reference);
    expect(row.status).toBe('failed');
    expect(row.last_error).toBe('invalid product (422)');
    expect(repo.pending()).toHaveLength(0);
  });

  it('counts by status', () => {
    repo.enqueue(payload);
    const { client_reference } = repo.enqueue(payload);
    repo.markFailed(client_reference, 'x');
    expect(repo.countByStatus()).toEqual({ queued: 1, syncing: 0, synced: 0, failed: 1 });
  });
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `cd "/Users/sebas/Documents/version 2/carniceria-hub" && npx vitest run test/outboxRepo.test.js`
Expected: FALLA — `OutboxRepo` no existe.

- [ ] **Step 3: Implementar `outboxRepo.js`**

```js
import { randomUUID } from 'node:crypto';

const PENDING_STATUSES = ['queued'];

export class OutboxRepo {
  /** @param {import('better-sqlite3').Database} db */
  constructor(db) {
    this.db = db;
  }

  /**
   * Inserta una venta en la cola. Idempotente por client_reference:
   * si ya existe, no la duplica y devuelve la fila existente.
   * @param {object} payload Venta tal como la mandó la báscula.
   * @param {string} [clientReference] UUID opcional (lo genera si falta).
   */
  enqueue(payload, clientReference = randomUUID()) {
    const existing = this.find(clientReference);
    if (existing) {
      return existing;
    }
    this.db
      .prepare(
        `INSERT INTO outbox_sales (client_reference, payload, status, attempts, received_at)
         VALUES (?, ?, 'queued', 0, ?)`
      )
      .run(clientReference, JSON.stringify(payload), new Date().toISOString());
    return this.find(clientReference);
  }

  find(clientReference) {
    return this.db
      .prepare('SELECT * FROM outbox_sales WHERE client_reference = ?')
      .get(clientReference);
  }

  /** Ventas por enviar, en orden de llegada. */
  pending() {
    const placeholders = PENDING_STATUSES.map(() => '?').join(',');
    return this.db
      .prepare(
        `SELECT * FROM outbox_sales WHERE status IN (${placeholders}) ORDER BY received_at ASC, rowid ASC`
      )
      .all(...PENDING_STATUSES);
  }

  markSyncing(clientReference) {
    this.db
      .prepare(`UPDATE outbox_sales SET status = 'syncing' WHERE client_reference = ?`)
      .run(clientReference);
  }

  markSynced(clientReference, backend) {
    this.db
      .prepare(
        `UPDATE outbox_sales
         SET status = 'synced', backend_sale_id = ?, backend_folio = ?, synced_at = ?, last_error = NULL
         WHERE client_reference = ?`
      )
      .run(backend.id, backend.folio, new Date().toISOString(), clientReference);
  }

  markRetry(clientReference, error) {
    this.db
      .prepare(
        `UPDATE outbox_sales
         SET status = 'queued', attempts = attempts + 1, last_error = ?
         WHERE client_reference = ?`
      )
      .run(error, clientReference);
  }

  markFailed(clientReference, error) {
    this.db
      .prepare(
        `UPDATE outbox_sales SET status = 'failed', last_error = ? WHERE client_reference = ?`
      )
      .run(error, clientReference);
  }

  count() {
    return this.db.prepare('SELECT count(*) c FROM outbox_sales').get().c;
  }

  countByStatus() {
    const base = { queued: 0, syncing: 0, synced: 0, failed: 0 };
    const rows = this.db.prepare('SELECT status, count(*) c FROM outbox_sales GROUP BY status').all();
    for (const r of rows) {
      base[r.status] = r.c;
    }
    return base;
  }

  /** Ventas en estado failed (para el monitor / acción humana). */
  failed() {
    return this.db
      .prepare(`SELECT * FROM outbox_sales WHERE status = 'failed' ORDER BY received_at ASC`)
      .all();
  }
}
```

- [ ] **Step 4: Correr y verificar que pasa**

Run: `cd "/Users/sebas/Documents/version 2/carniceria-hub" && npx vitest run test/outboxRepo.test.js`
Expected: PASA (7 tests).

- [ ] **Step 5: Commit**

```bash
git add test/outboxRepo.test.js src/main/db/outboxRepo.js
git commit -q -m "feat(hub): outbox repo with state transitions (TDD)"
```

---

### Task 4: `catalogRepo` (TDD)

**Files:**
- Create: `carniceria-hub/test/catalogRepo.test.js`
- Create: `carniceria-hub/src/main/db/catalogRepo.js`

- [ ] **Step 1: Escribir el test que falla**

```js
import { describe, it, expect, beforeEach } from 'vitest';
import { openDatabase } from '../src/main/db/database.js';
import { CatalogRepo } from '../src/main/db/catalogRepo.js';

describe('CatalogRepo', () => {
  let repo;
  beforeEach(() => {
    repo = new CatalogRepo(openDatabase(':memory:'));
  });

  it('returns null for a kind never stored', () => {
    expect(repo.get('products')).toBeNull();
  });

  it('stores and retrieves a snapshot with fetched_at', () => {
    const products = [{ id: 1, name: 'Bistec' }];
    repo.put('products', products);
    const snap = repo.get('products');
    expect(snap.data).toEqual(products);
    expect(snap.fetched_at).toBeTruthy();
  });

  it('overwrites a snapshot on re-put', () => {
    repo.put('categories', [{ id: 1 }]);
    repo.put('categories', [{ id: 1 }, { id: 2 }]);
    expect(repo.get('categories').data).toHaveLength(2);
  });
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `cd "/Users/sebas/Documents/version 2/carniceria-hub" && npx vitest run test/catalogRepo.test.js`
Expected: FALLA — `CatalogRepo` no existe.

- [ ] **Step 3: Implementar `catalogRepo.js`**

```js
export class CatalogRepo {
  /** @param {import('better-sqlite3').Database} db */
  constructor(db) {
    this.db = db;
  }

  /** @param {'products'|'categories'|'branch'} kind */
  get(kind) {
    const row = this.db.prepare('SELECT data, fetched_at FROM catalog_snapshots WHERE kind = ?').get(kind);
    if (!row) {
      return null;
    }
    return { data: JSON.parse(row.data), fetched_at: row.fetched_at };
  }

  put(kind, data) {
    this.db
      .prepare(
        `INSERT INTO catalog_snapshots (kind, data, fetched_at) VALUES (?, ?, ?)
         ON CONFLICT(kind) DO UPDATE SET data = excluded.data, fetched_at = excluded.fetched_at`
      )
      .run(kind, JSON.stringify(data), new Date().toISOString());
  }
}
```

- [ ] **Step 4: Correr y verificar que pasa**

Run: `cd "/Users/sebas/Documents/version 2/carniceria-hub" && npx vitest run test/catalogRepo.test.js`
Expected: PASA (3 tests).

- [ ] **Step 5: Commit**

```bash
git add test/catalogRepo.test.js src/main/db/catalogRepo.js
git commit -q -m "feat(hub): catalog cache repo (TDD)"
```

---

### Task 5: `backendClient` (TDD)

**Files:**
- Create: `carniceria-hub/test/backendClient.test.js`
- Create: `carniceria-hub/src/main/sync/backendClient.js`

El cliente usa `fetch` (global en Node 20). Los tests inyectan un `fetch` falso.

- [ ] **Step 1: Escribir el test que falla**

```js
import { describe, it, expect } from 'vitest';
import { BackendClient } from '../src/main/sync/backendClient.js';

function fakeFetch(responder) {
  const calls = [];
  const fn = async (url, options) => {
    calls.push({ url, options });
    return responder({ url, options });
  };
  fn.calls = calls;
  return fn;
}

const cfg = { backendUrl: 'https://api.test', apiKey: 'real-key' };

describe('BackendClient', () => {
  it('posts a sale with X-Api-Key and client_reference, returns parsed body', async () => {
    const fetch = fakeFetch(() => ({
      ok: true,
      status: 201,
      json: async () => ({ id: 7, folio: 'S-00007', client_reference: 'ref-1' }),
    }));
    const client = new BackendClient(cfg, fetch);
    const res = await client.createSale({ payment_method: 'cash', items: [] }, 'ref-1');

    expect(res.ok).toBe(true);
    expect(res.status).toBe(201);
    expect(res.body).toEqual({ id: 7, folio: 'S-00007', client_reference: 'ref-1' });

    const { url, options } = fetch.calls[0];
    expect(url).toBe('https://api.test/api/v1/sales');
    expect(options.method).toBe('POST');
    expect(options.headers['X-Api-Key']).toBe('real-key');
    expect(JSON.parse(options.body).client_reference).toBe('ref-1');
  });

  it('classifies a 4xx as a non-retryable client error', async () => {
    const fetch = fakeFetch(() => ({ ok: false, status: 422, json: async () => ({ message: 'bad' }) }));
    const client = new BackendClient(cfg, fetch);
    const res = await client.createSale({}, 'ref-2');
    expect(res.ok).toBe(false);
    expect(res.retryable).toBe(false);
    expect(res.status).toBe(422);
  });

  it('classifies a 5xx as retryable', async () => {
    const fetch = fakeFetch(() => ({ ok: false, status: 503, json: async () => ({}) }));
    const client = new BackendClient(cfg, fetch);
    const res = await client.createSale({}, 'ref-3');
    expect(res.retryable).toBe(true);
  });

  it('classifies a thrown network error as retryable', async () => {
    const fetch = async () => { throw new Error('ECONNREFUSED'); };
    const client = new BackendClient(cfg, fetch);
    const res = await client.createSale({}, 'ref-4');
    expect(res.ok).toBe(false);
    expect(res.retryable).toBe(true);
    expect(res.error).toMatch(/ECONNREFUSED/);
  });

  it('ping returns true on success and false on failure', async () => {
    const ok = new BackendClient(cfg, fakeFetch(() => ({ ok: true, status: 200, json: async () => ({}) })));
    expect(await ok.ping()).toBe(true);
    const bad = new BackendClient(cfg, async () => { throw new Error('down'); });
    expect(await bad.ping()).toBe(false);
  });
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `cd "/Users/sebas/Documents/version 2/carniceria-hub" && npx vitest run test/backendClient.test.js`
Expected: FALLA — `BackendClient` no existe.

- [ ] **Step 3: Implementar `backendClient.js`**

```js
export class BackendClient {
  /**
   * @param {{backendUrl: string, apiKey: string}} config
   * @param {typeof fetch} [fetchImpl]
   */
  constructor(config, fetchImpl = globalThis.fetch) {
    this.config = config;
    this.fetch = fetchImpl;
  }

  get base() {
    return this.config.backendUrl.replace(/\/+$/, '');
  }

  async createSale(payload, clientReference) {
    const body = JSON.stringify({ ...payload, client_reference: clientReference });
    try {
      const res = await this.fetch(`${this.base}/api/v1/sales`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Api-Key': this.config.apiKey,
        },
        body,
      });
      const parsed = await res.json().catch(() => ({}));
      if (res.ok) {
        return { ok: true, status: res.status, body: parsed };
      }
      // 4xx = error del cliente (terminal); 5xx = reintentable.
      return {
        ok: false,
        status: res.status,
        retryable: res.status >= 500,
        error: parsed.message || `HTTP ${res.status}`,
      };
    } catch (err) {
      // Fallo de red/DNS/timeout → reintentable.
      return { ok: false, status: 0, retryable: true, error: String(err.message || err) };
    }
  }

  async fetchCatalog() {
    const headers = { Accept: 'application/json', 'X-Api-Key': this.config.apiKey };
    const [branch, categories, products] = await Promise.all([
      this._getAll('/api/v1/branches/me', headers, false),
      this._getAll('/api/v1/categories', headers, false),
      this._getAll('/api/v1/products', headers, true),
    ]);
    return { branch, categories, products };
  }

  /** GET con paginación opcional (sigue meta.last_page). */
  async _getAll(path, headers, paginate) {
    const first = await this.fetch(`${this.base}${path}`, { headers });
    const body = await first.json();
    if (!paginate) {
      return body.data ?? body;
    }
    const all = [...(body.data ?? [])];
    const lastPage = body.meta?.last_page ?? 1;
    for (let page = 2; page <= lastPage; page++) {
      const res = await this.fetch(`${this.base}${path}?page=${page}`, { headers });
      const pageBody = await res.json();
      all.push(...(pageBody.data ?? []));
    }
    return all;
  }

  async ping() {
    try {
      const res = await this.fetch(`${this.base}/api/v1/branches/me`, {
        headers: { Accept: 'application/json', 'X-Api-Key': this.config.apiKey },
      });
      return res.ok;
    } catch {
      return false;
    }
  }
}
```

- [ ] **Step 4: Correr y verificar que pasa**

Run: `cd "/Users/sebas/Documents/version 2/carniceria-hub" && npx vitest run test/backendClient.test.js`
Expected: PASA (5 tests).

- [ ] **Step 5: Commit**

```bash
git add test/backendClient.test.js src/main/sync/backendClient.js
git commit -q -m "feat(hub): backend client with retryable classification (TDD)"
```

---

### Task 6: `syncWorker` (TDD)

**Files:**
- Create: `carniceria-hub/test/syncWorker.test.js`
- Create: `carniceria-hub/src/main/sync/syncWorker.js`

El worker drena el outbox usando un `backendClient` inyectado (falso en tests). Procesa secuencialmente; sin timers reales en tests (se llama `drainOnce()` directamente).

- [ ] **Step 1: Escribir el test que falla**

```js
import { describe, it, expect, beforeEach } from 'vitest';
import { openDatabase } from '../src/main/db/database.js';
import { OutboxRepo } from '../src/main/db/outboxRepo.js';
import { SyncWorker } from '../src/main/sync/syncWorker.js';

function clientReturning(sequence) {
  let i = 0;
  return {
    calls: [],
    async createSale(payload, ref) {
      this.calls.push({ payload, ref });
      const r = sequence[Math.min(i, sequence.length - 1)];
      i++;
      return r;
    },
  };
}

describe('SyncWorker.drainOnce', () => {
  let repo;
  beforeEach(() => {
    repo = new OutboxRepo(openDatabase(':memory:'));
  });
  const payload = { payment_method: 'cash', items: [] };

  it('marks a sale synced on 201 success', async () => {
    const { client_reference } = repo.enqueue(payload);
    const client = clientReturning([{ ok: true, status: 201, body: { id: 9, folio: 'S-00009' } }]);
    const worker = new SyncWorker(repo, client);

    await worker.drainOnce();

    const row = repo.find(client_reference);
    expect(row.status).toBe('synced');
    expect(row.backend_sale_id).toBe(9);
    expect(row.backend_folio).toBe('S-00009');
  });

  it('re-queues and increments attempts on retryable failure', async () => {
    const { client_reference } = repo.enqueue(payload);
    const client = clientReturning([{ ok: false, status: 503, retryable: true, error: 'down' }]);
    const worker = new SyncWorker(repo, client);

    await worker.drainOnce();

    const row = repo.find(client_reference);
    expect(row.status).toBe('queued');
    expect(row.attempts).toBe(1);
    expect(row.last_error).toBe('down');
  });

  it('marks failed on non-retryable (4xx) and does not block the rest', async () => {
    const bad = repo.enqueue(payload).client_reference;
    const good = repo.enqueue(payload).client_reference;
    const client = clientReturning([
      { ok: false, status: 422, retryable: false, error: 'invalid' },
      { ok: true, status: 201, body: { id: 5, folio: 'S-00005' } },
    ]);
    const worker = new SyncWorker(repo, client);

    await worker.drainOnce();

    expect(repo.find(bad).status).toBe('failed');
    expect(repo.find(good).status).toBe('synced');
  });

  it('does not duplicate: a sale already synced is not re-sent', async () => {
    const { client_reference } = repo.enqueue(payload);
    const client = clientReturning([{ ok: true, status: 201, body: { id: 1, folio: 'S-1' } }]);
    const worker = new SyncWorker(repo, client);

    await worker.drainOnce();
    await worker.drainOnce();

    expect(client.calls).toHaveLength(1);
    expect(repo.find(client_reference).status).toBe('synced');
  });

  it('passes the client_reference through to the backend client', async () => {
    const { client_reference } = repo.enqueue(payload);
    const client = clientReturning([{ ok: true, status: 201, body: { id: 1, folio: 'S-1' } }]);
    const worker = new SyncWorker(repo, client);

    await worker.drainOnce();

    expect(client.calls[0].ref).toBe(client_reference);
  });
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `cd "/Users/sebas/Documents/version 2/carniceria-hub" && npx vitest run test/syncWorker.test.js`
Expected: FALLA — `SyncWorker` no existe.

- [ ] **Step 3: Implementar `syncWorker.js`**

```js
export class SyncWorker {
  /**
   * @param {import('../db/outboxRepo.js').OutboxRepo} outbox
   * @param {import('./backendClient.js').BackendClient} client
   * @param {{intervalMs?: number, logger?: {info:Function,error:Function}}} [opts]
   */
  constructor(outbox, client, opts = {}) {
    this.outbox = outbox;
    this.client = client;
    this.intervalMs = opts.intervalMs ?? 5000;
    this.logger = opts.logger ?? { info() {}, error() {} };
    this._timer = null;
    this._running = false;
  }

  /** Procesa todas las ventas pendientes una vez, en orden, secuencialmente. */
  async drainOnce() {
    if (this._running) {
      return;
    }
    this._running = true;
    try {
      const pending = this.outbox.pending();
      for (const row of pending) {
        const payload = JSON.parse(row.payload);
        this.outbox.markSyncing(row.client_reference);
        const res = await this.client.createSale(payload, row.client_reference);

        if (res.ok) {
          this.outbox.markSynced(row.client_reference, {
            id: res.body.id,
            folio: res.body.folio,
          });
        } else if (res.retryable) {
          this.outbox.markRetry(row.client_reference, res.error);
        } else {
          this.outbox.markFailed(row.client_reference, res.error);
        }
      }
    } finally {
      this._running = false;
    }
  }

  /** Arranca el bucle periódico. */
  start() {
    if (this._timer) {
      return;
    }
    this._timer = setInterval(() => {
      this.drainOnce().catch((e) => this.logger.error('drain failed', e));
    }, this.intervalMs);
    this.drainOnce().catch((e) => this.logger.error('drain failed', e));
  }

  stop() {
    if (this._timer) {
      clearInterval(this._timer);
      this._timer = null;
    }
  }

  /** Despierta el drenado inmediatamente (al reconectar). */
  wake() {
    this.drainOnce().catch((e) => this.logger.error('drain failed', e));
  }
}
```

- [ ] **Step 4: Correr y verificar que pasa**

Run: `cd "/Users/sebas/Documents/version 2/carniceria-hub" && npx vitest run test/syncWorker.test.js`
Expected: PASA (5 tests).

- [ ] **Step 5: Commit**

```bash
git add test/syncWorker.test.js src/main/sync/syncWorker.js
git commit -q -m "feat(hub): sync worker draining outbox sequentially (TDD)"
```

---

### Task 7: `localApiServer` (Fastify, TDD de integración)

**Files:**
- Create: `carniceria-hub/test/localApiServer.test.js`
- Create: `carniceria-hub/src/main/server/localApiServer.js`

El servidor recibe el contrato `/api/v1`. Valida el token local (header `X-Api-Key`), encola ventas en el outbox y sirve catálogo desde la caché. Se testea con `app.inject()` de Fastify (sin abrir puerto).

- [ ] **Step 1: Escribir el test que falla**

```js
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { openDatabase } from '../src/main/db/database.js';
import { OutboxRepo } from '../src/main/db/outboxRepo.js';
import { CatalogRepo } from '../src/main/db/catalogRepo.js';
import { buildLocalApiServer } from '../src/main/server/localApiServer.js';

describe('localApiServer', () => {
  let app, outbox, catalog;

  beforeEach(() => {
    const db = openDatabase(':memory:');
    outbox = new OutboxRepo(db);
    catalog = new CatalogRepo(db);
    app = buildLocalApiServer({ outbox, catalog, localToken: 'local-secret' });
  });

  afterEach(async () => {
    await app.close();
  });

  const auth = { 'X-Api-Key': 'local-secret' };
  const sale = { payment_method: 'cash', items: [{ product_id: 1, quantity: 1 }] };

  it('rejects requests without a valid local token (401)', async () => {
    const res = await app.inject({ method: 'POST', url: '/api/v1/sales', payload: sale });
    expect(res.statusCode).toBe(401);
  });

  it('accepts a sale, enqueues it, and returns 202 with client_reference', async () => {
    const res = await app.inject({ method: 'POST', url: '/api/v1/sales', headers: auth, payload: sale });
    expect(res.statusCode).toBe(202);
    const body = res.json();
    expect(body.status).toBe('queued');
    expect(body.client_reference).toMatch(/[0-9a-f-]{36}/);
    expect(outbox.count()).toBe(1);
  });

  it('serves products from cache', async () => {
    catalog.put('products', [{ id: 1, name: 'Bistec' }]);
    const res = await app.inject({ method: 'GET', url: '/api/v1/products', headers: auth });
    expect(res.statusCode).toBe(200);
    expect(res.json().data).toEqual([{ id: 1, name: 'Bistec' }]);
  });

  it('returns 503 for products when the catalog was never fetched', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/products', headers: auth });
    expect(res.statusCode).toBe(503);
  });

  it('reports a queued sale status via GET sales/{ref}', async () => {
    const created = await app.inject({ method: 'POST', url: '/api/v1/sales', headers: auth, payload: sale });
    const ref = created.json().client_reference;
    const res = await app.inject({ method: 'GET', url: `/api/v1/sales/${ref}`, headers: auth });
    expect(res.statusCode).toBe(200);
    expect(res.json().status).toBe('queued');
  });

  it('returns 404 for an unknown sale reference', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/sales/nope', headers: auth });
    expect(res.statusCode).toBe(404);
  });
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `cd "/Users/sebas/Documents/version 2/carniceria-hub" && npx vitest run test/localApiServer.test.js`
Expected: FALLA — `buildLocalApiServer` no existe.

- [ ] **Step 3: Implementar `localApiServer.js`**

```js
import Fastify from 'fastify';

/**
 * Construye el servidor local que las básculas consumen.
 * @param {{
 *   outbox: import('../db/outboxRepo.js').OutboxRepo,
 *   catalog: import('../db/catalogRepo.js').CatalogRepo,
 *   localToken: string,
 *   onSaleEnqueued?: () => void,
 *   logger?: boolean,
 * }} deps
 */
export function buildLocalApiServer({ outbox, catalog, localToken, onSaleEnqueued, logger = false }) {
  const app = Fastify({ logger });

  // Auth por token local de sucursal (header X-Api-Key).
  app.addHook('onRequest', async (req, reply) => {
    const token = req.headers['x-api-key'];
    if (!token || token !== localToken) {
      reply.code(401).send({ message: 'Token local inválido o ausente.' });
    }
  });

  const serveCatalog = (kind) => (req, reply) => {
    const snap = catalog.get(kind);
    if (!snap) {
      reply.code(503).send({ message: 'Hub sin catálogo. Conéctate al backend al menos una vez.' });
      return;
    }
    reply.send({ data: snap.data, fetched_at: snap.fetched_at });
  };

  app.get('/api/v1/branches/me', (req, reply) => {
    const snap = catalog.get('branch');
    if (!snap) {
      reply.code(503).send({ message: 'Hub sin catálogo.' });
      return;
    }
    reply.send({ data: snap.data });
  });

  app.get('/api/v1/categories', serveCatalog('categories'));
  app.get('/api/v1/products', serveCatalog('products'));

  app.post('/api/v1/sales', (req, reply) => {
    const payload = req.body ?? {};
    if (!Array.isArray(payload.items) || payload.items.length === 0) {
      reply.code(422).send({ message: 'La venta requiere items.' });
      return;
    }
    const row = outbox.enqueue(payload);
    if (onSaleEnqueued) {
      onSaleEnqueued();
    }
    reply.code(202).send({ client_reference: row.client_reference, status: row.status });
  });

  app.get('/api/v1/sales/:ref', (req, reply) => {
    const row = outbox.find(req.params.ref);
    if (!row) {
      reply.code(404).send({ message: 'Venta no encontrada en el hub.' });
      return;
    }
    reply.send({
      client_reference: row.client_reference,
      status: row.status,
      backend_folio: row.backend_folio,
      backend_sale_id: row.backend_sale_id,
      last_error: row.last_error,
    });
  });

  return app;
}
```

- [ ] **Step 4: Correr y verificar que pasa**

Run: `cd "/Users/sebas/Documents/version 2/carniceria-hub" && npx vitest run test/localApiServer.test.js`
Expected: PASA (6 tests).

- [ ] **Step 5: Correr toda la suite**

Run: `cd "/Users/sebas/Documents/version 2/carniceria-hub" && npx vitest run`
Expected: PASA todo (outbox 7, catalog 3, backend 5, sync 5, server 6 = 26 tests).

- [ ] **Step 6: Commit**

```bash
git add test/localApiServer.test.js src/main/server/localApiServer.js
git commit -q -m "feat(hub): local /api/v1 server (enqueue + catalog cache) (TDD)"
```

---

### Task 8: `catalogRefresher` y `connectivityMonitor`

**Files:**
- Create: `carniceria-hub/src/main/sync/catalogRefresher.js`
- Create: `carniceria-hub/src/main/sync/connectivityMonitor.js`

Estos coordinan timers y red; se prueban con smoke tests manuales (la lógica pura ya está cubierta en `backendClient`).

- [ ] **Step 1: Implementar `catalogRefresher.js`**

```js
export class CatalogRefresher {
  /**
   * @param {import('../db/catalogRepo.js').CatalogRepo} catalog
   * @param {import('./backendClient.js').BackendClient} client
   * @param {{intervalMs?: number, logger?: {info:Function,error:Function}}} [opts]
   */
  constructor(catalog, client, opts = {}) {
    this.catalog = catalog;
    this.client = client;
    this.intervalMs = opts.intervalMs ?? 15 * 60 * 1000;
    this.logger = opts.logger ?? { info() {}, error() {} };
    this._timer = null;
  }

  /** Baja el catálogo y lo cachea. Si falla, conserva el último bueno. */
  async refresh() {
    try {
      const { branch, categories, products } = await this.client.fetchCatalog();
      if (branch) {
        this.catalog.put('branch', branch);
      }
      if (categories) {
        this.catalog.put('categories', categories);
      }
      if (products) {
        this.catalog.put('products', products);
      }
      this.logger.info('catalog refreshed');
      return true;
    } catch (err) {
      // Conserva el último catálogo bueno; no borra nada.
      this.logger.error('catalog refresh failed, keeping last good', err);
      return false;
    }
  }

  start() {
    if (this._timer) {
      return;
    }
    this.refresh();
    this._timer = setInterval(() => this.refresh(), this.intervalMs);
  }

  stop() {
    if (this._timer) {
      clearInterval(this._timer);
      this._timer = null;
    }
  }
}
```

- [ ] **Step 2: Implementar `connectivityMonitor.js`**

```js
export class ConnectivityMonitor {
  /**
   * @param {import('./backendClient.js').BackendClient} client
   * @param {{intervalMs?: number, onOnline?: Function, onChange?: (online:boolean)=>void}} [opts]
   */
  constructor(client, opts = {}) {
    this.client = client;
    this.intervalMs = opts.intervalMs ?? 10000;
    this.onOnline = opts.onOnline ?? (() => {});
    this.onChange = opts.onChange ?? (() => {});
    this.online = null;
    this._timer = null;
  }

  async check() {
    const online = await this.client.ping();
    if (online !== this.online) {
      this.online = online;
      this.onChange(online);
      if (online) {
        this.onOnline(); // despierta al worker al reconectar
      }
    }
    return online;
  }

  start() {
    if (this._timer) {
      return;
    }
    this.check();
    this._timer = setInterval(() => this.check(), this.intervalMs);
  }

  stop() {
    if (this._timer) {
      clearInterval(this._timer);
      this._timer = null;
    }
  }
}
```

- [ ] **Step 3: Smoke test de carga de módulos**

Run: `cd "/Users/sebas/Documents/version 2/carniceria-hub" && node -e "Promise.all([import('./src/main/sync/catalogRefresher.js'),import('./src/main/sync/connectivityMonitor.js')]).then(()=>console.log('OK_LOAD')).catch(e=>{console.error(e);process.exit(1)})"`
Expected: imprime `OK_LOAD`.

- [ ] **Step 4: Commit**

```bash
git add src/main/sync/catalogRefresher.js src/main/sync/connectivityMonitor.js
git commit -q -m "feat(hub): catalog refresher and connectivity monitor"
```

---

### Task 9: Configuración (`config.js`) y logger

**Files:**
- Create: `carniceria-hub/src/main/logger.js`
- Create: `carniceria-hub/src/main/config.js`

- [ ] **Step 1: Implementar `logger.js`**

```js
/** Logger mínimo. En Electron se podría redirigir a archivo; aquí va a consola. */
export const logger = {
  info: (...a) => console.log('[hub]', ...a),
  error: (...a) => console.error('[hub]', ...a),
};
```

- [ ] **Step 2: Implementar `config.js`**

```js
import Store from 'electron-store';
import { randomUUID } from 'node:crypto';

const DEFAULTS = {
  backendUrl: '',
  apiKey: '',
  localToken: '',
  serverPort: 4599,
  catalogRefreshMinutes: 15,
  mdnsEnabled: true,
};

/** Crea/lee la configuración persistente del hub. */
export function loadConfig() {
  const store = new Store({ name: 'hub-config', defaults: DEFAULTS });
  // Genera un token local la primera vez (para emparejar básculas).
  if (!store.get('localToken')) {
    store.set('localToken', randomUUID());
  }
  return store;
}
```

- [ ] **Step 3: Commit**

```bash
git add src/main/logger.js src/main/config.js
git commit -q -m "feat(hub): persistent config and logger"
```

---

### Task 10: Shell Electron (`main/index.js`) + IPC + preload

**Files:**
- Create: `carniceria-hub/src/main/ipc.js`
- Create: `carniceria-hub/src/preload/preload.js`
- Create: `carniceria-hub/src/main/index.js`

- [ ] **Step 1: Implementar `ipc.js`**

```js
import { ipcMain } from 'electron';

/**
 * Registra los handlers IPC que el renderer (monitor) consulta.
 * @param {{outbox, catalog, config, getOnline: () => boolean}} deps
 */
export function registerIpc({ outbox, catalog, config, getOnline }) {
  ipcMain.handle('hub:status', () => ({
    online: getOnline(),
    counts: outbox.countByStatus(),
    failed: outbox.failed(),
    catalog: {
      products: catalog.get('products')?.fetched_at ?? null,
      categories: catalog.get('categories')?.fetched_at ?? null,
    },
    config: {
      backendUrl: config.get('backendUrl'),
      serverPort: config.get('serverPort'),
      localToken: config.get('localToken'),
      hasApiKey: !!config.get('apiKey'),
    },
  }));

  ipcMain.handle('hub:saveConfig', (_e, patch) => {
    for (const [k, v] of Object.entries(patch)) {
      config.set(k, v);
    }
    return true;
  });
}
```

- [ ] **Step 2: Implementar `preload/preload.js`**

```js
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('hub', {
  status: () => ipcRenderer.invoke('hub:status'),
  saveConfig: (patch) => ipcRenderer.invoke('hub:saveConfig', patch),
});
```

- [ ] **Step 3: Implementar `main/index.js` (cableado completo)**

```js
import { app, BrowserWindow, Tray, Menu, nativeImage } from 'electron';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { openDatabase } from './db/database.js';
import { OutboxRepo } from './db/outboxRepo.js';
import { CatalogRepo } from './db/catalogRepo.js';
import { buildLocalApiServer } from './server/localApiServer.js';
import { BackendClient } from './sync/backendClient.js';
import { SyncWorker } from './sync/syncWorker.js';
import { CatalogRefresher } from './sync/catalogRefresher.js';
import { ConnectivityMonitor } from './sync/connectivityMonitor.js';
import { Bonjour } from 'bonjour-service';
import { loadConfig } from './config.js';
import { registerIpc } from './ipc.js';
import { logger } from './logger.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// Single-instance: una sola copia del hub por máquina.
if (!app.requestSingleInstanceLock()) {
  app.quit();
}

let tray = null;
let win = null;
let online = false;
let services = null;

function createWindow() {
  win = new BrowserWindow({
    width: 880,
    height: 620,
    show: false,
    webPreferences: {
      preload: path.join(__dirname, '../preload/preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
    },
  });

  if (process.env.HUB_DEV_URL) {
    win.loadURL(process.env.HUB_DEV_URL);
  } else {
    win.loadFile(path.join(__dirname, '../../dist/index.html'));
  }

  // No se cierra la app al cerrar la ventana: el servidor sigue recibiendo ventas.
  win.on('close', (e) => {
    if (!app.isQuitting) {
      e.preventDefault();
      win.hide();
    }
  });
}

function createTray() {
  const icon = nativeImage.createEmpty();
  tray = new Tray(icon);
  tray.setToolTip('Carnicería Hub');
  const menu = Menu.buildFromTemplate([
    { label: 'Abrir monitor', click: () => win.show() },
    { type: 'separator' },
    { label: 'Salir', click: () => { app.isQuitting = true; app.quit(); } },
  ]);
  tray.setContextMenu(menu);
  tray.on('click', () => win.show());
}

function startServices() {
  const config = loadConfig();
  const dbPath = path.join(app.getPath('userData'), 'hub.sqlite');
  const db = openDatabase(dbPath);
  const outbox = new OutboxRepo(db);
  const catalog = new CatalogRepo(db);

  const backend = new BackendClient({
    backendUrl: config.get('backendUrl'),
    apiKey: config.get('apiKey'),
  });

  const worker = new SyncWorker(outbox, backend, { logger });
  const refresher = new CatalogRefresher(catalog, backend, {
    intervalMs: config.get('catalogRefreshMinutes') * 60 * 1000,
    logger,
  });
  const monitor = new ConnectivityMonitor(backend, {
    onOnline: () => worker.wake(),
    onChange: (isOnline) => { online = isOnline; },
  });

  const server = buildLocalApiServer({
    outbox,
    catalog,
    localToken: config.get('localToken'),
    onSaleEnqueued: () => worker.wake(),
  });
  server.listen({ host: '0.0.0.0', port: config.get('serverPort') })
    .then((addr) => logger.info('local API on', addr))
    .catch((e) => logger.error('server listen failed', e));

  let bonjour = null;
  if (config.get('mdnsEnabled')) {
    bonjour = new Bonjour();
    bonjour.publish({ name: 'Carniceria Hub', type: 'carnihub', port: config.get('serverPort') });
  }

  worker.start();
  refresher.start();
  monitor.start();

  registerIpc({ outbox, catalog, config, getOnline: () => online });

  return { db, server, worker, refresher, monitor, bonjour };
}

app.on('second-instance', () => {
  if (win) {
    win.show();
  }
});

app.whenReady().then(() => {
  services = startServices();
  createWindow();
  createTray();
});

app.on('window-all-closed', () => {
  // No salir: el hub vive en la bandeja.
});

app.on('before-quit', async () => {
  app.isQuitting = true;
  if (services) {
    services.worker.stop();
    services.refresher.stop();
    services.monitor.stop();
    services.bonjour?.destroy();
    await services.server.close().catch(() => {});
    services.db.close();
  }
});
```

- [ ] **Step 4: Verificar que el núcleo sigue cargando (sin Electron)**

Run: `cd "/Users/sebas/Documents/version 2/carniceria-hub" && npx vitest run`
Expected: PASA todo (26 tests) — `index.js` no se importa en tests.

- [ ] **Step 5: Commit**

```bash
git add src/main/ipc.js src/preload/preload.js src/main/index.js
git commit -q -m "feat(hub): electron shell (tray, window, lifecycle, wiring)"
```

---

### Task 11: Renderer (monitor Vue) + Vite

**Files:**
- Create: `carniceria-hub/index.html`
- Create: `carniceria-hub/vite.config.js`
- Create: `carniceria-hub/src/renderer/main.js`
- Create: `carniceria-hub/src/renderer/App.vue`
- Create: `carniceria-hub/src/renderer/style.css`

- [ ] **Step 1: Crear `index.html`**

```html
<!doctype html>
<html lang="es">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Carnicería Hub</title>
  </head>
  <body>
    <div id="app"></div>
    <script type="module" src="/src/renderer/main.js"></script>
  </body>
</html>
```

- [ ] **Step 2: Crear `vite.config.js`**

```js
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  base: './',
  plugins: [vue()],
  build: { outDir: 'dist' },
});
```

- [ ] **Step 3: Crear `src/renderer/main.js`**

```js
import { createApp } from 'vue';
import App from './App.vue';
import './style.css';

createApp(App).mount('#app');
```

- [ ] **Step 4: Crear `src/renderer/style.css`**

```css
:root { font-family: system-ui, sans-serif; color: #1f2937; }
body { margin: 0; background: #f9fafb; }
.wrap { max-width: 820px; margin: 0 auto; padding: 24px; }
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
.row { display: flex; gap: 16px; flex-wrap: wrap; }
.stat { flex: 1; min-width: 120px; }
.stat b { font-size: 28px; display: block; }
.dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; }
.online { background: #16a34a; } .offline { background: #dc2626; }
label { display: block; font-size: 13px; margin: 8px 0 2px; }
input { width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 8px; }
button { margin-top: 12px; padding: 8px 14px; border: 0; border-radius: 8px; background: #111827; color: #fff; cursor: pointer; }
.failed { color: #b91c1c; font-size: 13px; }
```

- [ ] **Step 5: Crear `src/renderer/App.vue`**

```vue
<script setup>
import { ref, onMounted, onUnmounted } from 'vue';

const status = ref(null);
const form = ref({ backendUrl: '', apiKey: '', serverPort: 4599 });
let timer = null;

async function refresh() {
  status.value = await window.hub.status();
  if (status.value?.config) {
    form.value.backendUrl = status.value.config.backendUrl || '';
    form.value.serverPort = status.value.config.serverPort || 4599;
  }
}

async function save() {
  const patch = { backendUrl: form.value.backendUrl, serverPort: Number(form.value.serverPort) };
  if (form.value.apiKey) {
    patch.apiKey = form.value.apiKey;
  }
  await window.hub.saveConfig(patch);
  form.value.apiKey = '';
  await refresh();
}

onMounted(() => {
  refresh();
  timer = setInterval(refresh, 3000);
});
onUnmounted(() => clearInterval(timer));
</script>

<template>
  <div class="wrap" v-if="status">
    <h1>Carnicería Hub</h1>

    <div class="card">
      <span class="dot" :class="status.online ? 'online' : 'offline'"></span>
      <strong>{{ status.online ? 'Conectado al backend' : 'Sin conexión (encolando)' }}</strong>
    </div>

    <div class="card row">
      <div class="stat"><b>{{ status.counts.queued }}</b> En cola</div>
      <div class="stat"><b>{{ status.counts.syncing }}</b> Enviando</div>
      <div class="stat"><b>{{ status.counts.synced }}</b> Sincronizadas</div>
      <div class="stat"><b>{{ status.counts.failed }}</b> Fallidas</div>
    </div>

    <div class="card" v-if="status.failed.length">
      <h3>Ventas fallidas</h3>
      <div v-for="f in status.failed" :key="f.client_reference" class="failed">
        {{ f.client_reference }} — {{ f.last_error }}
      </div>
    </div>

    <div class="card">
      <h3>Configuración</h3>
      <label>URL del backend</label>
      <input v-model="form.backendUrl" placeholder="https://mi-backend.com" />
      <label>API Key de la sucursal (se guarda solo en el hub)</label>
      <input v-model="form.apiKey" type="password" :placeholder="status.config.hasApiKey ? '•••••• (guardada)' : 'pega la API key'" />
      <label>Puerto del servidor local</label>
      <input v-model="form.serverPort" type="number" />
      <label>Token local (para emparejar básculas)</label>
      <input :value="status.config.localToken" readonly />
      <button @click="save">Guardar</button>
    </div>

    <p style="font-size:12px;color:#6b7280">Catálogo productos: {{ status.catalog.products || 'nunca' }}</p>
  </div>
</template>
```

- [ ] **Step 6: Build del renderer**

Run: `cd "/Users/sebas/Documents/version 2/carniceria-hub" && npm run build:renderer`
Expected: genera `dist/index.html` sin error.

- [ ] **Step 7: Commit**

```bash
git add index.html vite.config.js src/renderer/
git commit -q -m "feat(hub): status monitor renderer (Vue)"
```

---

### Task 12: Arranque manual y verificación end-to-end

- [ ] **Step 1: Correr toda la suite de tests**

Run: `cd "/Users/sebas/Documents/version 2/carniceria-hub" && npx vitest run`
Expected: PASA todo (26 tests).

- [ ] **Step 2: Arranque manual de la app (humano)**

Run: `cd "/Users/sebas/Documents/version 2/carniceria-hub" && npm run build:renderer && npm start`
Expected: aparece un icono en la bandeja; al hacer clic abre el monitor; el servidor local escucha en el puerto configurado. (Requiere entorno gráfico — lo verifica el usuario.)

- [ ] **Step 3: Verificación de contrato (con backend de demo corriendo)**

Configurar en el monitor: `backendUrl` del backend de Sail, pegar la API key de demo (`csa_demo_test_key_for_development_only_1234`), guardar. Esperar el refresco de catálogo. Luego, desde otra terminal, simular una báscula:

```bash
curl -s -X POST http://localhost:4599/api/v1/sales \
  -H 'Content-Type: application/json' \
  -H 'X-Api-Key: <TOKEN_LOCAL_DEL_MONITOR>' \
  -d '{"payment_method":"cash","items":[{"product_id":1,"quantity":1}]}'
```
Expected: responde `202 {"client_reference":"...","status":"queued"}`. El monitor muestra la venta en cola y, al sincronizar, pasa a "Sincronizadas" y aparece en el workbench web.

---

## Self-Review (cobertura del spec)

- **Servidor local expone `/api/v1` (branches/me, categories, products, sales POST/GET)** → Task 7. ✅
- **Outbox durable en SQLite + transiciones de estado** → Tasks 2, 3. ✅
- **Caché de catálogo, sirve offline, conserva último bueno** → Tasks 4, 8 (refresher). ✅
- **SyncWorker: reintentos, backoff, dedupe, 4xx→failed / 5xx→retry, secuencial** → Tasks 5 (clasificación), 6 (worker). ✅
- **ConnectivityMonitor despierta al worker al reconectar** → Task 8. ✅
- **Auth báscula→hub por token local** → Task 7 (hook onRequest) + Task 9 (token generado). ✅
- **`POST sales` responde 202 + client_reference (modelo asíncrono)** → Task 7. ✅
- **App de bandeja always-on, no se cierra al cerrar ventana, single-instance** → Task 10. ✅
- **UI monitor (conexión, cola, fallidas, config con token/QR, catálogo)** → Task 11. (QR de emparejamiento: ver nota.) ✅
- **Descubrimiento mDNS opcional** → Task 10 (Bonjour publish). ✅
- **Persistencia en userData, durable ante crash** → Task 10 (dbPath en userData). ✅

**Notas / ajustes conscientes respecto al spec:**
- El **QR de emparejamiento** se reduce en este plan a mostrar el token local como texto editable/copiable (Task 11). Generar la imagen QR (con la lib `qrcode`) es un añadido menor; se puede sumar como mejora sin cambiar la arquitectura.
- El **empaquetado** (electron-builder) y el **rebuild de better-sqlite3 contra el ABI de Electron** quedan fuera de este plan (Roadmap). En dev se corre con `npm start` usando el Electron instalado.
- `backoff exponencial` por tiempo: el worker reintenta en cada tick del intervalo; la columna `attempts` queda registrada. Un backoff por-venta más fino (saltar ventas cuyo próximo intento aún no llega) es una mejora acotada sobre `pending()` y `attempts` ya presentes; no se incluye para mantener el worker simple en v1.

## Troubleshooting

- **`better-sqlite3` falla al compilar:** requiere herramientas de build (Xcode CLT en macOS: `xcode-select --install`). Alternativa: `npm rebuild better-sqlite3`.
- **`electron` no descarga:** detrás de proxy, configurar `ELECTRON_MIRROR`. La descarga del binario es grande; reintentar `npm install`.
- **Renderer en blanco al `npm start`:** correr `npm run build:renderer` antes, o exportar `HUB_DEV_URL=http://localhost:5173` y `npm run dev:renderer` en paralelo.
