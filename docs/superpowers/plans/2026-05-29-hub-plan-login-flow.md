# Carnicería Hub — Plan: Flujo de login (Hito 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que cajero/admin-sucursal inicien sesión en la app `carniceria-hub` contra el backend vía Sanctum (token), con el token cifrado en el proceso main, y un shell mínimo por rol en el renderer.

**Architecture:** Tres capas de un mismo flujo, en orden de dependencia: (A) Backend `carniceria-saas` expone `/api/v1/auth/{login,logout,me}` con Sanctum, aditivo y sin tocar básculas/Inertia. (B) El proceso main del hub autentica vía `AuthApiClient`+`AuthService`, guarda el token cifrado (`safeStorage`) y lo expone por IPC sin que el renderer lo vea. (C) El renderer usa vue-router con login + shell (Home, Device=monitor actual, Config gated).

**Tech Stack:** Laravel 13 + Sanctum 4 + PHPUnit (backend); Electron 33 + electron-store + safeStorage + Vitest (hub main); Vue 3 + vue-router + Vite (renderer).

**Spec:** `docs/superpowers/specs/2026-05-29-hub-login-flow-design.md`.

**No-ruptura:** los endpoints de básculas (`auth.apikey`) y el login Inertia (sesión) quedan intactos. Único cambio de BD: tabla nueva `personal_access_tokens`. NO editar el guard en `config/sanctum.php` (Spatie usa guard `web`, que alinea con `auth:sanctum`).

---

## File Structure

**Backend (`carniceria-saas/`):**
- Create: `database/migrations/2026_05_29_100001_create_personal_access_tokens_table.php` (publicada por Sanctum).
- Modify: `app/Models/User.php` — agregar trait `HasApiTokens`.
- Create: `app/Http/Controllers/Api/AuthController.php` — login/logout/me.
- Modify: `routes/api.php` — rutas `auth/*` fuera de `auth.apikey`.
- Create: `tests/Feature/Api/AuthControllerTest.php`.

**Hub main (`carniceria-hub/`):**
- Create: `src/main/auth/authApiClient.js` — cliente HTTP de los 3 endpoints.
- Create: `src/main/auth/authService.js` — login/logout/getSession/restoreSession + token cifrado.
- Modify: `src/main/ipc.js` — handlers `auth:login/logout/session`.
- Modify: `src/preload/preload.cjs` — exponer `authLogin/authLogout/authSession`.
- Modify: `src/main/index.js` — instanciar AuthService y pasarlo a `registerIpc`.
- Create: `test/authApiClient.test.js`, `test/authService.test.js`.
- Modify: `package.json` — agregar `vue-router`.

**Hub renderer (`carniceria-hub/`):**
- Create: `src/renderer/router.js`, `src/renderer/composables/useAuth.js`.
- Create: `src/renderer/views/LoginView.vue`, `ShellLayout.vue`, `HomeView.vue`, `DeviceView.vue`, `ConfigView.vue`.
- Modify: `src/renderer/App.vue` — `<RouterView/>`.
- Modify: `src/renderer/main.js` — usar el router.
- Create: `test/routerGuard.test.js`.

---

# PARTE A — Backend: API de autenticación

### Task A1: Inicializar Sanctum (migración + trait)

**Files:**
- Create: `database/migrations/2026_05_29_100001_create_personal_access_tokens_table.php`
- Modify: `app/Models/User.php`

- [ ] **Step 1: Publicar la migración de Sanctum**

Run: `vendor/bin/sail artisan vendor:publish --tag=sanctum-migrations`
Expected: crea una migración `*_create_personal_access_tokens_table.php` en `database/migrations/`. (No publicar config; no tocar `config/sanctum.php`.)

- [ ] **Step 2: Migrar**

Run: `vendor/bin/sail artisan migrate`
Expected: `... create_personal_access_tokens_table ... DONE`.

- [ ] **Step 3: Agregar `HasApiTokens` al `User`**

En `app/Models/User.php`, agregar el import y el trait:

```php
use Laravel\Sanctum\HasApiTokens;
```

Y en la línea de traits de la clase, cambiar:

```php
    use HasFactory, HasRoles, Notifiable;
```
por:
```php
    use HasApiTokens, HasFactory, HasRoles, Notifiable;
```

- [ ] **Step 4: Verificar el trait**

Run: `vendor/bin/sail artisan tinker --execute 'echo method_exists(new App\Models\User, "createToken") ? "OK_TOKENS" : "NO_TOKENS";'`
Expected: imprime `OK_TOKENS`.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/*_create_personal_access_tokens_table.php app/Models/User.php
git commit -m "feat(auth): initialize Sanctum (personal_access_tokens + HasApiTokens)"
```

---

### Task A2: `AuthController` + rutas + tests (TDD)

**Files:**
- Test: `tests/Feature/Api/AuthControllerTest.php`
- Create: `app/Http/Controllers/Api/AuthController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    private function login(string $email, string $password = 'password'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
            'device_name' => 'Hub Sucursal 1',
        ]);
    }

    public function test_cajero_can_login_and_gets_token_and_user(): void
    {
        $res = $this->login('caja@test.local');

        $res->assertOk();
        $this->assertNotEmpty($res->json('token'));
        $res->assertJsonPath('user.email', 'caja@test.local');
        $res->assertJsonPath('user.role', 'cajero');
        $res->assertJsonPath('user.branch_id', $this->branch->id);
        $res->assertJsonPath('user.branch_name', 'Sucursal 1');
        $res->assertJsonPath('user.tenant_slug', 'test-tenant');
    }

    public function test_admin_sucursal_can_login(): void
    {
        $this->login('suc@test.local')->assertOk()->assertJsonPath('user.role', 'admin-sucursal');
    }

    public function test_admin_empresa_is_forbidden(): void
    {
        $this->login('admin@test.local')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Este usuario no puede usar el hub.');
    }

    public function test_superadmin_is_forbidden(): void
    {
        $this->makeUser('super@test.local', 'superadmin', null);
        $this->login('super@test.local')->assertStatus(403);
    }

    public function test_wrong_password_returns_401(): void
    {
        $this->login('caja@test.local', 'nope')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Email o contraseña incorrectos.');
    }

    public function test_force_password_change_returns_409(): void
    {
        $this->cajero->forceFill(['force_password_change' => true])->save();
        $this->login('caja@test.local')->assertStatus(409);
    }

    public function test_me_returns_user_with_valid_token(): void
    {
        $token = $this->login('caja@test.local')->json('token');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'caja@test.local');
    }

    public function test_me_without_token_is_401(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_logout_revokes_token(): void
    {
        $token = $this->login('caja@test.local')->json('token');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')->assertOk();

        // El mismo token ya no sirve.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')->assertStatus(401);
    }
}
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `vendor/bin/sail artisan test --compact --filter=AuthControllerTest`
Expected: FALLA — rutas/controlador no existen (404/500).

- [ ] **Step 3: Implementar `AuthController`**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    private const HUB_ROLES = ['cajero', 'admin-sucursal'];

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
            'device_name' => 'required|string|max:120',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Email o contraseña incorrectos.'], 401);
        }

        if (! $user->hasAnyRole(self::HUB_ROLES)) {
            return response()->json(['message' => 'Este usuario no puede usar el hub.'], 403);
        }

        if ($user->force_password_change) {
            return response()->json([
                'message' => 'Debes cambiar tu contraseña en la web antes de usar el hub.',
            ], 409);
        }

        $token = $user->createToken($request->device_name)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    /**
     * @return array{id:int,name:string,email:string,role:?string,branch_id:?int,branch_name:?string,tenant_id:?int,tenant_slug:?string}
     */
    private function userPayload(User $user): array
    {
        $user->loadMissing(['branch', 'tenant']);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getRoleNames()->first(),
            'branch_id' => $user->branch_id,
            'branch_name' => $user->branch?->name,
            'tenant_id' => $user->tenant_id,
            'tenant_slug' => $user->tenant?->slug,
        ];
    }
}
```

- [ ] **Step 4: Agregar las rutas en `routes/api.php`**

Agregar el import al inicio (junto a los otros `use`):

```php
use App\Http\Controllers\Api\AuthController;
```

Y agregar este bloque **después** del grupo `prefix('v1')->middleware('auth.apikey')` existente (NO dentro de él):

```php
Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('api.auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('api.auth.me');
    });
});
```

- [ ] **Step 5: Correr y verificar que pasa**

Run: `vendor/bin/sail artisan test --compact --filter=AuthControllerTest`
Expected: PASA (9 tests).

- [ ] **Step 6: Regresión — nada roto en básculas ni Inertia**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Api/SaleIdempotencyTest.php tests/Feature/PresentationSaleContractTest.php`
Expected: PASA. Luego los de auth web:
Run: `vendor/bin/sail artisan test --compact tests/Feature/Auth`
Expected: PASA.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Api/AuthController.php routes/api.php tests/Feature/Api/AuthControllerTest.php
git commit -m "feat(api): hub auth endpoints (login/logout/me) via Sanctum"
```

---

# PARTE B — Hub (proceso main)

Todos los comandos de esta parte se ejecutan en `/Users/sebas/Documents/version 2/carniceria-hub`.

### Task B1: Agregar vue-router como dependencia

**Files:**
- Modify: `package.json`

- [ ] **Step 1: Instalar vue-router**

Run: `cd "/Users/sebas/Documents/version 2/carniceria-hub" && npm install vue-router@^4.5.0`
Expected: agrega `vue-router` a `dependencies` sin error.

- [ ] **Step 2: Commit**

```bash
git add package.json package-lock.json
git commit -m "chore(hub): add vue-router"
```

---

### Task B2: `AuthApiClient` (TDD)

**Files:**
- Test: `test/authApiClient.test.js`
- Create: `src/main/auth/authApiClient.js`

- [ ] **Step 1: Escribir el test que falla**

```js
import { describe, it, expect } from 'vitest';
import { AuthApiClient } from '../src/main/auth/authApiClient.js';

function fakeFetch(responder) {
  const calls = [];
  const fn = async (url, options) => {
    calls.push({ url, options });
    return responder({ url, options });
  };
  fn.calls = calls;
  return fn;
}

const cfg = { backendUrl: 'https://api.test' };
const creds = { email: 'caja@test.local', password: 'password', deviceName: 'Hub 1' };

describe('AuthApiClient', () => {
  it('login posts credentials and returns {ok, token, user} on 200', async () => {
    const fetch = fakeFetch(() => ({
      ok: true, status: 200,
      json: async () => ({ token: 't-1', user: { email: 'caja@test.local', role: 'cajero' } }),
    }));
    const client = new AuthApiClient(cfg, fetch);
    const res = await client.login(creds);

    expect(res.ok).toBe(true);
    expect(res.token).toBe('t-1');
    expect(res.user.role).toBe('cajero');
    const { url, options } = fetch.calls[0];
    expect(url).toBe('https://api.test/api/v1/auth/login');
    expect(JSON.parse(options.body)).toEqual({ email: creds.email, password: creds.password, device_name: 'Hub 1' });
  });

  it('login maps 401 to invalid_credentials', async () => {
    const fetch = fakeFetch(() => ({ ok: false, status: 401, json: async () => ({ message: 'x' }) }));
    const res = await new AuthApiClient(cfg, fetch).login(creds);
    expect(res.ok).toBe(false);
    expect(res.error).toBe('invalid_credentials');
  });

  it('login maps 403 to role_forbidden', async () => {
    const fetch = fakeFetch(() => ({ ok: false, status: 403, json: async () => ({ message: 'x' }) }));
    expect((await new AuthApiClient(cfg, fetch).login(creds)).error).toBe('role_forbidden');
  });

  it('login maps 409 to password_change_required', async () => {
    const fetch = fakeFetch(() => ({ ok: false, status: 409, json: async () => ({ message: 'x' }) }));
    expect((await new AuthApiClient(cfg, fetch).login(creds)).error).toBe('password_change_required');
  });

  it('login maps a thrown network error to offline', async () => {
    const fetch = async () => { throw new Error('ECONNREFUSED'); };
    expect((await new AuthApiClient(cfg, fetch).login(creds)).error).toBe('offline');
  });

  it('me returns user on 200 and null on 401', async () => {
    const ok = new AuthApiClient(cfg, fakeFetch(() => ({ ok: true, status: 200, json: async () => ({ user: { email: 'a' } }) })));
    expect((await ok.me('t')).user.email).toBe('a');
    const bad = new AuthApiClient(cfg, fakeFetch(() => ({ ok: false, status: 401, json: async () => ({}) })));
    expect(await bad.me('t')).toBeNull();
    const { options } = ok.fetch.calls[0];
    expect(options.headers.Authorization).toBe('Bearer t');
  });

  it('logout calls the endpoint with the bearer token', async () => {
    const client = new AuthApiClient(cfg, fakeFetch(() => ({ ok: true, status: 200, json: async () => ({}) })));
    await client.logout('t-9');
    const { url, options } = client.fetch.calls[0];
    expect(url).toBe('https://api.test/api/v1/auth/logout');
    expect(options.method).toBe('POST');
    expect(options.headers.Authorization).toBe('Bearer t-9');
  });
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `npx vitest run test/authApiClient.test.js`
Expected: FALLA — `AuthApiClient` no existe.

- [ ] **Step 3: Implementar `authApiClient.js`**

```js
const ERROR_BY_STATUS = {
  401: 'invalid_credentials',
  403: 'role_forbidden',
  409: 'password_change_required',
};

export class AuthApiClient {
  /**
   * @param {{backendUrl: string}} config
   * @param {typeof fetch} [fetchImpl]
   */
  constructor(config, fetchImpl = globalThis.fetch) {
    this.config = config;
    this.fetch = fetchImpl;
  }

  get base() {
    return this.config.backendUrl.replace(/\/+$/, '');
  }

  async login({ email, password, deviceName }) {
    try {
      const res = await this.fetch(`${this.base}/api/v1/auth/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ email, password, device_name: deviceName }),
      });
      const body = await res.json().catch(() => ({}));
      if (res.ok) {
        return { ok: true, token: body.token, user: body.user };
      }
      return { ok: false, error: ERROR_BY_STATUS[res.status] ?? 'server_error', status: res.status };
    } catch {
      return { ok: false, error: 'offline' };
    }
  }

  /** @returns {Promise<{user:object}|null>} null si el token no sirve. */
  async me(token) {
    try {
      const res = await this.fetch(`${this.base}/api/v1/auth/me`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
      if (!res.ok) {
        return null;
      }
      return await res.json();
    } catch {
      return null;
    }
  }

  async logout(token) {
    try {
      await this.fetch(`${this.base}/api/v1/auth/logout`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
    } catch {
      // Logout local igual procede aunque el backend no responda.
    }
  }
}
```

- [ ] **Step 4: Correr y verificar que pasa**

Run: `npx vitest run test/authApiClient.test.js`
Expected: PASA (7 tests).

- [ ] **Step 5: Commit**

```bash
git add test/authApiClient.test.js src/main/auth/authApiClient.js
git commit -m "feat(hub): auth api client with error mapping (TDD)"
```

---

### Task B3: `AuthService` (TDD)

**Files:**
- Test: `test/authService.test.js`
- Create: `src/main/auth/authService.js`

`AuthService` recibe dependencias inyectables: `apiClient`, `store` (get/set/delete), y `safeStorage` (isEncryptionAvailable/encryptString/decryptString). Esto lo hace testeable sin Electron.

- [ ] **Step 1: Escribir el test que falla**

```js
import { describe, it, expect, beforeEach } from 'vitest';
import { AuthService } from '../src/main/auth/authService.js';

function makeStore() {
  const data = {};
  return {
    data,
    get: (k) => (k in data ? data[k] : undefined),
    set: (k, v) => { data[k] = v; },
    delete: (k) => { delete data[k]; },
  };
}

// safeStorage falso: "cifra" envolviendo en base64.
const safeStorage = {
  isEncryptionAvailable: () => true,
  encryptString: (s) => Buffer.from(`enc:${s}`),
  decryptString: (buf) => Buffer.from(buf).toString().replace(/^enc:/, ''),
};

const user = { id: 1, email: 'caja@test.local', role: 'cajero', branch_id: 7 };

function apiStub(overrides = {}) {
  return {
    login: async () => ({ ok: true, token: 'tok-1', user }),
    me: async () => ({ user }),
    logout: async () => {},
    ...overrides,
  };
}

describe('AuthService', () => {
  let store;
  beforeEach(() => { store = makeStore(); });

  it('login stores encrypted token + user and returns authenticated session', async () => {
    const svc = new AuthService({ apiClient: apiStub(), store, safeStorage });
    const res = await svc.login('caja@test.local', 'password', 'Hub 1');

    expect(res).toEqual({ authenticated: true, user });
    // El token se guardó cifrado (no en claro).
    expect(store.get('authToken')).toBeTruthy();
    expect(String(store.get('authToken'))).not.toContain('tok-1');
    expect(store.get('authUser')).toEqual(user);
  });

  it('login returns the error code on failure and stores nothing', async () => {
    const api = apiStub({ login: async () => ({ ok: false, error: 'invalid_credentials' }) });
    const svc = new AuthService({ apiClient: api, store, safeStorage });
    const res = await svc.login('x', 'y', 'Hub 1');

    expect(res).toEqual({ authenticated: false, error: 'invalid_credentials' });
    expect(store.get('authToken')).toBeUndefined();
  });

  it('getSession reflects stored user', async () => {
    const svc = new AuthService({ apiClient: apiStub(), store, safeStorage });
    expect(svc.getSession()).toEqual({ authenticated: false, user: null });
    await svc.login('caja@test.local', 'password', 'Hub 1');
    expect(svc.getSession()).toEqual({ authenticated: true, user });
  });

  it('logout clears token and user', async () => {
    const svc = new AuthService({ apiClient: apiStub(), store, safeStorage });
    await svc.login('caja@test.local', 'password', 'Hub 1');
    await svc.logout();
    expect(store.get('authToken')).toBeUndefined();
    expect(store.get('authUser')).toBeUndefined();
    expect(svc.getSession().authenticated).toBe(false);
  });

  it('restoreSession online validates token via me() and keeps session', async () => {
    const svc = new AuthService({ apiClient: apiStub(), store, safeStorage });
    await svc.login('caja@test.local', 'password', 'Hub 1');
    const res = await svc.restoreSession();
    expect(res).toEqual({ authenticated: true, user });
  });

  it('restoreSession clears session when me() says token is invalid', async () => {
    const api = apiStub({ me: async () => null });
    const svc = new AuthService({ apiClient: api, store, safeStorage });
    await svc.login('caja@test.local', 'password', 'Hub 1');
    // Forzar que me() devuelva null en la restauración:
    svc.apiClient.me = async () => null;
    const res = await svc.restoreSession();
    expect(res.authenticated).toBe(false);
    expect(store.get('authToken')).toBeUndefined();
  });

  it('falls back to plaintext token when encryption is unavailable', async () => {
    const noEnc = { isEncryptionAvailable: () => false, encryptString: () => { throw new Error('no'); }, decryptString: () => { throw new Error('no'); } };
    const svc = new AuthService({ apiClient: apiStub(), store, safeStorage: noEnc });
    await svc.login('caja@test.local', 'password', 'Hub 1');
    expect(store.get('authToken')).toBe('tok-1');
    expect(svc.getSession().authenticated).toBe(true);
  });
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `npx vitest run test/authService.test.js`
Expected: FALLA — `AuthService` no existe.

- [ ] **Step 3: Implementar `authService.js`**

```js
const TOKEN_KEY = 'authToken';
const USER_KEY = 'authUser';
const ENC_FLAG = 'authTokenEncrypted';

export class AuthService {
  /**
   * @param {{
   *   apiClient: import('./authApiClient.js').AuthApiClient,
   *   store: { get:Function, set:Function, delete:Function },
   *   safeStorage: { isEncryptionAvailable:Function, encryptString:Function, decryptString:Function },
   *   logger?: {info:Function,error:Function},
   * }} deps
   */
  constructor({ apiClient, store, safeStorage, logger }) {
    this.apiClient = apiClient;
    this.store = store;
    this.safeStorage = safeStorage;
    this.logger = logger ?? { info() {}, error() {} };
  }

  async login(email, password, deviceName) {
    const res = await this.apiClient.login({ email, password, deviceName });
    if (!res.ok) {
      return { authenticated: false, error: res.error };
    }
    this._saveToken(res.token);
    this.store.set(USER_KEY, res.user);
    return { authenticated: true, user: res.user };
  }

  async logout() {
    const token = this._readToken();
    if (token) {
      await this.apiClient.logout(token);
    }
    this._clear();
    return { authenticated: false };
  }

  getSession() {
    const user = this.store.get(USER_KEY) ?? null;
    return { authenticated: !!(user && this._readToken()), user };
  }

  /** Al arrancar: online valida con me(); offline confía en lo guardado. */
  async restoreSession() {
    const token = this._readToken();
    const user = this.store.get(USER_KEY) ?? null;
    if (!token || !user) {
      this._clear();
      return { authenticated: false, user: null };
    }
    const result = await this.apiClient.me(token);
    if (result === null) {
      // null = token inválido/revocado (no es lo mismo que offline; el cliente
      // devuelve null en 401). Limpiamos la sesión.
      this._clear();
      return { authenticated: false, user: null };
    }
    return { authenticated: true, user };
  }

  _saveToken(token) {
    if (this.safeStorage.isEncryptionAvailable()) {
      const encrypted = this.safeStorage.encryptString(token);
      this.store.set(TOKEN_KEY, Buffer.from(encrypted).toString('base64'));
      this.store.set(ENC_FLAG, true);
    } else {
      this.logger.error('safeStorage no disponible: el token se guarda sin cifrar');
      this.store.set(TOKEN_KEY, token);
      this.store.set(ENC_FLAG, false);
    }
  }

  _readToken() {
    const raw = this.store.get(TOKEN_KEY);
    if (!raw) {
      return null;
    }
    if (this.store.get(ENC_FLAG)) {
      try {
        return this.safeStorage.decryptString(Buffer.from(raw, 'base64'));
      } catch {
        return null;
      }
    }
    return raw;
  }

  _clear() {
    this.store.delete(TOKEN_KEY);
    this.store.delete(USER_KEY);
    this.store.delete(ENC_FLAG);
  }
}
```

- [ ] **Step 4: Correr y verificar que pasa**

Run: `npx vitest run test/authService.test.js`
Expected: PASA (7 tests).

- [ ] **Step 5: Toda la suite del hub**

Run: `npx vitest run`
Expected: PASA todo (núcleo Hito 1: 26 + authApiClient 7 + authService 7 = 40).

- [ ] **Step 6: Commit**

```bash
git add test/authService.test.js src/main/auth/authService.js
git commit -m "feat(hub): auth service with encrypted token storage (TDD)"
```

---

### Task B4: Cablear IPC + preload + index

**Files:**
- Modify: `src/main/ipc.js`
- Modify: `src/preload/preload.cjs`
- Modify: `src/main/index.js`

- [ ] **Step 1: Ampliar `registerIpc` en `src/main/ipc.js`**

Cambiar la firma para recibir `auth` y añadir los handlers. El archivo completo queda:

```js
import { ipcMain } from 'electron';

/**
 * Registra los handlers IPC que el renderer consulta.
 * @param {{outbox, catalog, config, auth, getOnline: () => boolean}} deps
 */
export function registerIpc({ outbox, catalog, config, auth, getOnline }) {
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

  ipcMain.handle('auth:login', (_e, { email, password, deviceName }) =>
    auth.login(email, password, deviceName)
  );
  ipcMain.handle('auth:logout', () => auth.logout());
  ipcMain.handle('auth:session', () => auth.restoreSession());
}
```

- [ ] **Step 2: Ampliar `src/preload/preload.cjs`**

```js
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('hub', {
  status: () => ipcRenderer.invoke('hub:status'),
  saveConfig: (patch) => ipcRenderer.invoke('hub:saveConfig', patch),
  authLogin: (creds) => ipcRenderer.invoke('auth:login', creds),
  authLogout: () => ipcRenderer.invoke('auth:logout'),
  authSession: () => ipcRenderer.invoke('auth:session'),
});
```

- [ ] **Step 3: Instanciar `AuthService` en `src/main/index.js`**

Agregar los imports junto a los demás:

```js
import { safeStorage } from 'electron';
import { AuthApiClient } from './auth/authApiClient.js';
import { AuthService } from './auth/authService.js';
```

Dentro de `startServices()`, después de crear `config` y antes del `return`, crear el servicio de auth y pasarlo a `registerIpc`. Reemplazar la línea `registerIpc({ outbox, catalog, config, getOnline: () => online });` por:

```js
  const authApiClient = new AuthApiClient({ backendUrl: config.get('backendUrl') });
  const auth = new AuthService({ apiClient: authApiClient, store: config, safeStorage, logger });

  registerIpc({ outbox, catalog, config, auth, getOnline: () => online });
```

(`config` es el `electron-store`, que ya expone `get/set/delete` — sirve como `store` del AuthService.)

- [ ] **Step 4: Verificar que la suite sigue verde (sin Electron)**

Run: `npx vitest run`
Expected: PASA (40 tests; `index.js`/`ipc.js`/`preload.cjs` no se importan en tests).

- [ ] **Step 5: Commit**

```bash
git add src/main/ipc.js src/preload/preload.cjs src/main/index.js
git commit -m "feat(hub): wire auth service into IPC, preload and main"
```

---

# PARTE C — Hub (renderer)

### Task C1: Router + composable useAuth + App.vue

**Files:**
- Create: `src/renderer/router.js`
- Create: `src/renderer/composables/useAuth.js`
- Modify: `src/renderer/main.js`
- Modify: `src/renderer/App.vue`

- [ ] **Step 1: Crear `src/renderer/composables/useAuth.js`**

```js
import { ref } from 'vue';

const user = ref(null);
const ready = ref(false);

export function useAuth() {
  async function hydrate() {
    const session = await window.hub.authSession();
    user.value = session.authenticated ? session.user : null;
    ready.value = true;
    return session;
  }

  async function login(email, password, deviceName) {
    const res = await window.hub.authLogin({ email, password, deviceName });
    if (res.authenticated) {
      user.value = res.user;
    }
    return res;
  }

  async function logout() {
    await window.hub.authLogout();
    user.value = null;
  }

  const isAuthenticated = () => !!user.value;
  const hasRole = (role) => user.value?.role === role;

  return { user, ready, hydrate, login, logout, isAuthenticated, hasRole };
}
```

- [ ] **Step 2: Crear `src/renderer/router.js`**

```js
import { createRouter, createWebHashHistory } from 'vue-router';
import { useAuth } from './composables/useAuth.js';
import LoginView from './views/LoginView.vue';
import ShellLayout from './views/ShellLayout.vue';
import HomeView from './views/HomeView.vue';
import DeviceView from './views/DeviceView.vue';
import ConfigView from './views/ConfigView.vue';

const routes = [
  { path: '/login', name: 'login', component: LoginView, meta: { public: true } },
  {
    path: '/',
    component: ShellLayout,
    children: [
      { path: '', redirect: { name: 'home' } },
      { path: 'home', name: 'home', component: HomeView },
      { path: 'device', name: 'device', component: DeviceView },
      { path: 'config', name: 'config', component: ConfigView, meta: { role: 'admin-sucursal' } },
    ],
  },
];

export const router = createRouter({
  history: createWebHashHistory(),
  routes,
});

router.beforeEach(async (to) => {
  const auth = useAuth();
  if (!auth.ready.value) {
    await auth.hydrate();
  }
  if (to.meta.public) {
    return auth.isAuthenticated() && to.name === 'login' ? { name: 'home' } : true;
  }
  if (!auth.isAuthenticated()) {
    return { name: 'login' };
  }
  if (to.meta.role && !auth.hasRole(to.meta.role)) {
    return { name: 'home' };
  }
  return true;
});
```

- [ ] **Step 3: Reemplazar `src/renderer/main.js`**

```js
import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router.js';
import './style.css';

createApp(App).use(router).mount('#app');
```

- [ ] **Step 4: Reemplazar `src/renderer/App.vue`**

```vue
<script setup>
import { RouterView } from 'vue-router';
</script>

<template>
  <RouterView />
</template>
```

- [ ] **Step 5: Commit (las views se crean en las tareas siguientes)**

Este paso no compila aún (faltan las views). Continuar a Task C2–C4 y commitear al final de C4 cuando el build pase. (No commitear todavía.)

---

### Task C2: LoginView

**Files:**
- Create: `src/renderer/views/LoginView.vue`

- [ ] **Step 1: Crear `src/renderer/views/LoginView.vue`**

```vue
<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuth } from '../composables/useAuth.js';

const router = useRouter();
const { login } = useAuth();

const email = ref('');
const password = ref('');
const error = ref('');
const loading = ref(false);

const MESSAGES = {
  invalid_credentials: 'Email o contraseña incorrectos.',
  role_forbidden: 'Este usuario no puede usar el hub.',
  password_change_required: 'Debes cambiar tu contraseña en la web antes de usar el hub.',
  offline: 'Necesitas conexión para iniciar sesión la primera vez.',
  server_error: 'Error del servidor. Intenta de nuevo.',
};

async function submit() {
  error.value = '';
  loading.value = true;
  try {
    const res = await login(email.value, password.value, 'Hub');
    if (res.authenticated) {
      router.push({ name: 'home' });
    } else {
      error.value = MESSAGES[res.error] ?? 'No se pudo iniciar sesión.';
    }
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div class="wrap">
    <div class="card login">
      <h1>Carnicería Hub</h1>
      <p class="sub">Inicia sesión para continuar</p>
      <form @submit.prevent="submit">
        <label>Email</label>
        <input v-model="email" type="email" required autofocus />
        <label>Contraseña</label>
        <input v-model="password" type="password" required />
        <p v-if="error" class="err">{{ error }}</p>
        <button type="submit" :disabled="loading">{{ loading ? 'Entrando…' : 'Entrar' }}</button>
      </form>
    </div>
  </div>
</template>

<style scoped>
.login { max-width: 360px; margin: 64px auto; }
.sub { color: #6b7280; margin-top: -8px; }
.err { color: #b91c1c; font-size: 13px; }
button:disabled { opacity: 0.6; }
</style>
```

---

### Task C3: ShellLayout + Home + Device + Config views

**Files:**
- Create: `src/renderer/views/ShellLayout.vue`
- Create: `src/renderer/views/HomeView.vue`
- Create: `src/renderer/views/DeviceView.vue`
- Create: `src/renderer/views/ConfigView.vue`

- [ ] **Step 1: Crear `src/renderer/views/ShellLayout.vue`**

```vue
<script setup>
import { RouterView, RouterLink, useRouter } from 'vue-router';
import { useAuth } from '../composables/useAuth.js';

const router = useRouter();
const { user, logout, hasRole } = useAuth();

async function doLogout() {
  await logout();
  router.push({ name: 'login' });
}
</script>

<template>
  <div class="shell">
    <header class="bar">
      <strong>Carnicería Hub</strong>
      <nav>
        <RouterLink :to="{ name: 'home' }">Inicio</RouterLink>
        <RouterLink :to="{ name: 'device' }">Dispositivo</RouterLink>
        <RouterLink v-if="hasRole('admin-sucursal')" :to="{ name: 'config' }">Config</RouterLink>
      </nav>
      <span class="who" v-if="user">{{ user.name }} · {{ user.role }} · {{ user.branch_name }}
        <button class="link" @click="doLogout">Salir</button>
      </span>
    </header>
    <main class="wrap">
      <RouterView />
    </main>
  </div>
</template>

<style scoped>
.bar { display: flex; align-items: center; gap: 16px; padding: 10px 20px; background: #111827; color: #fff; }
.bar nav { display: flex; gap: 12px; flex: 1; }
.bar a { color: #cbd5e1; text-decoration: none; }
.bar a.router-link-active { color: #fff; font-weight: 600; }
.who { font-size: 13px; color: #cbd5e1; }
.link { background: none; border: 0; color: #fca5a5; cursor: pointer; margin-left: 8px; padding: 0; }
</style>
```

- [ ] **Step 2: Crear `src/renderer/views/HomeView.vue`**

```vue
<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useAuth } from '../composables/useAuth.js';

const { user } = useAuth();
const online = ref(false);
let timer = null;

async function refresh() {
  const s = await window.hub.status();
  online.value = s.online;
}
onMounted(() => { refresh(); timer = setInterval(refresh, 3000); });
onUnmounted(() => clearInterval(timer));
</script>

<template>
  <div class="card" v-if="user">
    <h2>Hola, {{ user.name }}</h2>
    <p>Rol: <strong>{{ user.role }}</strong></p>
    <p>Sucursal: <strong>{{ user.branch_name }}</strong></p>
    <p>
      <span class="dot" :class="online ? 'online' : 'offline'"></span>
      {{ online ? 'Hub conectado al backend' : 'Hub sin conexión (encolando)' }}
    </p>
  </div>
</template>
```

- [ ] **Step 3: Crear `src/renderer/views/DeviceView.vue`** (el monitor actual)

```vue
<script setup>
import { ref, onMounted, onUnmounted } from 'vue';

const status = ref(null);
let timer = null;

async function refresh() {
  status.value = await window.hub.status();
}
onMounted(() => { refresh(); timer = setInterval(refresh, 3000); });
onUnmounted(() => clearInterval(timer));
</script>

<template>
  <div v-if="status">
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

    <p style="font-size: 12px; color: #6b7280">Catálogo productos: {{ status.catalog.products || 'nunca' }}</p>
  </div>
</template>
```

- [ ] **Step 4: Crear `src/renderer/views/ConfigView.vue`** (config, solo admin-sucursal)

```vue
<script setup>
import { ref, onMounted } from 'vue';

const status = ref(null);
const form = ref({ backendUrl: '', apiKey: '', serverPort: 4599 });

async function refresh() {
  status.value = await window.hub.status();
  form.value.backendUrl = status.value.config.backendUrl || '';
  form.value.serverPort = status.value.config.serverPort || 4599;
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

onMounted(refresh);
</script>

<template>
  <div class="card" v-if="status">
    <h3>Configuración del hub</h3>
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
</template>
```

- [ ] **Step 5: Build del renderer**

Run: `cd "/Users/sebas/Documents/version 2/carniceria-hub" && npm run build:renderer`
Expected: compila sin error (genera `dist/`).

- [ ] **Step 6: Commit (Tasks C1–C3)**

```bash
git add src/renderer/ package.json
git commit -m "feat(hub): renderer login + role-based shell (router, views)"
```

---

### Task C4: Test del guard del router

**Files:**
- Test: `test/routerGuard.test.js`

El guard depende de `window.hub`. Se testea la lógica de decisión extrayéndola: el guard ya está en `router.js`, pero para testear sin DOM, probamos la función de decisión pura. Refactor mínimo: exportar la decisión.

- [ ] **Step 1: Extraer la decisión del guard en `router.js`**

Agregar al final de `src/renderer/router.js` (y usarla dentro de `beforeEach`):

```js
/**
 * Decisión pura del guard (testeable sin DOM).
 * @param {{public?:boolean, role?:string, name?:string}} toMeta
 * @param {{authenticated:boolean, role:?string, toName?:string}} authState
 */
export function guardDecision(toMeta, authState) {
  if (toMeta.public) {
    return authState.authenticated && toMeta.name === 'login' ? { name: 'home' } : true;
  }
  if (!authState.authenticated) {
    return { name: 'login' };
  }
  if (toMeta.role && authState.role !== toMeta.role) {
    return { name: 'home' };
  }
  return true;
}
```

Y reescribir `beforeEach` para delegar en ella:

```js
router.beforeEach(async (to) => {
  const auth = useAuth();
  if (!auth.ready.value) {
    await auth.hydrate();
  }
  return guardDecision(
    { public: to.meta.public, role: to.meta.role, name: to.name },
    { authenticated: auth.isAuthenticated(), role: auth.user.value?.role }
  );
});
```

- [ ] **Step 2: Escribir el test**

```js
import { describe, it, expect } from 'vitest';
import { guardDecision } from '../src/renderer/router.js';

describe('guardDecision', () => {
  it('redirects unauthenticated users to login on protected routes', () => {
    expect(guardDecision({ name: 'home' }, { authenticated: false, role: null })).toEqual({ name: 'login' });
  });

  it('lets authenticated users into protected routes', () => {
    expect(guardDecision({ name: 'home' }, { authenticated: true, role: 'cajero' })).toBe(true);
  });

  it('bounces an authenticated user away from /login', () => {
    expect(guardDecision({ public: true, name: 'login' }, { authenticated: true, role: 'cajero' })).toEqual({ name: 'home' });
  });

  it('blocks a cajero from a route requiring admin-sucursal', () => {
    expect(guardDecision({ name: 'config', role: 'admin-sucursal' }, { authenticated: true, role: 'cajero' })).toEqual({ name: 'home' });
  });

  it('allows admin-sucursal into a role-gated route', () => {
    expect(guardDecision({ name: 'config', role: 'admin-sucursal' }, { authenticated: true, role: 'admin-sucursal' })).toBe(true);
  });
});
```

> Nota: `router.js` importa `.vue` y vue-router; Vitest (environment node) puede fallar al importar SFCs. Para aislar la función pura, si la importación de `router.js` arrastra SFCs, mover `guardDecision` a `src/renderer/guard.js` y reimportarla en `router.js`. En ese caso el test importa desde `../src/renderer/guard.js`.

- [ ] **Step 3: Correr el test**

Run: `cd "/Users/sebas/Documents/version 2/carniceria-hub" && npx vitest run test/routerGuard.test.js`
Expected: PASA (5 tests). Si falla por import de SFC, aplicar la nota (mover a `guard.js`) y reintentar.

- [ ] **Step 4: Suite completa + build**

Run: `npx vitest run && npm run build:renderer`
Expected: tests verdes (45) y build OK.

- [ ] **Step 5: Commit**

```bash
git add test/routerGuard.test.js src/renderer/
git commit -m "test(hub): router guard decision (TDD)"
```

---

### Task C5: Verificación manual end-to-end (humano)

- [ ] **Step 1: Bajar binario de Electron (si falta) y arrancar**

Run: `cd "/Users/sebas/Documents/version 2/carniceria-hub" && node node_modules/electron/install.js && npm run build:renderer && npm start`
Expected: abre la app; primero la pantalla de login.

- [ ] **Step 2: Login con un cajero de demo**

Con el backend de Sail corriendo y la `backendUrl` configurada (si es la primera vez, configurarla requiere entrar… ver nota), iniciar sesión con `cajero@eltoro.test` / `password`.
Expected: entra al shell; "Inicio" muestra nombre/rol/sucursal; "Dispositivo" muestra el monitor; "Config" NO aparece (es cajero). Con `sucursal@eltoro.test` sí aparece "Config".

> Nota de bootstrap: el `backendUrl` vive en `electron-store` y hoy se edita desde ConfigView (tras login). Para el primer arranque, fijarlo una vez por env var o sembrarlo en la config del hub antes de login. Esto se documenta como mejora menor (pantalla de "configurar backend" previa al login) — fuera de alcance de este hito.

---

## Self-Review (cobertura del spec)

- **Sanctum init (migración + HasApiTokens)** → Task A1. ✅
- **Endpoints login/logout/me fuera de auth.apikey** → Task A2 (Step 4). ✅
- **Rol ∈ {cajero, admin-sucursal} con hasAnyRole; 403/401/409** → Task A2 (controlador + tests). ✅
- **Forma de `user` (role, branch_name, tenant_slug)** → Task A2 (`userPayload` + test). ✅
- **No romper básculas/Inertia** → Task A2 (Step 6 regresión con rutas exactas). ✅
- **Auth en main, token cifrado safeStorage, fallback** → Tasks B3 (`_saveToken`/`_readToken` + test de fallback). ✅
- **AuthApiClient con mapeo de errores** → Task B2. ✅
- **IPC auth:login/logout/session + preload, renderer nunca ve el token** → Task B4. ✅
- **vue-router; Login + Shell (Home/Device/Config); Config gated admin-sucursal** → Tasks B1, C1–C3. ✅
- **restoreSession online valida / offline confía** → Task B3 (`restoreSession` + tests). ✅
- **Guard de router (sin sesión→login; cajero no entra a config)** → Task C4. ✅
- **Mover monitor actual a DeviceView; config a ConfigView** → Task C3 (Steps 3–4). ✅

**Notas conscientes:**
- El **bootstrap del `backendUrl`** antes del primer login es un hueco de UX (hoy la config se edita tras login). Se marca como mejora menor (pantalla previa de "configurar backend") fuera de alcance; en dev se siembra la config una vez.
- El test del guard usa una **función pura `guardDecision`** para no depender del DOM/SFC en Vitest (environment node).
- Rutas de test de regresión fijadas con path exacto: `tests/Feature/Api/SaleIdempotencyTest.php`, `tests/Feature/PresentationSaleContractTest.php`, `tests/Feature/Auth`.
- NO se publica ni edita `config/sanctum.php` (el guard `web` de Spatie ya alinea con `auth:sanctum`).
