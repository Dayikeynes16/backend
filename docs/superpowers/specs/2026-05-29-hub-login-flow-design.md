# Carnicería Hub — Flujo de inicio de sesión (Hito 2) — Diseño congelado

**Fecha:** 2026-05-29
**Estado:** Aprobado para Hito 2 (alcance: auth + shell mínimo)
**Autor:** colaboración con Claude (brainstorming + propuesta)

## En palabras simples (léelo primero)

Hoy la app de escritorio `carniceria-hub` solo recibe ventas de las básculas y las
reenvía al backend (eso es el Hito 1, ya construido). **No tiene login ni pantallas
de usuario.**

Este hito agrega la **puerta de entrada de personas**: una pantalla donde el
**cajero** o el **admin de sucursal** inician sesión con su email y contraseña (los
mismos de la web), y, ya adentro, un **shell** (cascarón de la app) que muestra
quién está conectado, su sucursal, y el estado del hub (la cola de ventas que ya
existía). **Todavía no** hay pantallas de cobro ni turnos — eso viene después.

**Lo más importante:** no rompemos nada de lo que ya funciona. Las básculas
(que usan `X-Api-Key`) y la app web (que usa sesión de Inertia) siguen **idénticas**.
Todo lo de este hito se **agrega al lado**.

## Decisiones tomadas (brainstorming 2026-05-29)

1. **Alcance:** login + manejo de sesión/token + shell mínimo (identidad, rol,
   sucursal, y el monitor del hub integrado). **Sin pantallas de caja.**
2. **Login online, sesión persiste offline:** ingresar credenciales requiere
   conexión (valida contra el backend); una vez logueado, el token se guarda y la
   sesión sigue válida entre reinicios aunque esté offline.
3. **Login como puerta; monitor/config adentro:** al abrir, lo primero es el login.
   Ya autenticado, el shell muestra el contenido por rol y el monitor/config del hub
   como secciones; la **config sensible (API key) solo la ve admin-sucursal**. El
   pipe de básculas sigue corriendo en segundo plano sin login.
4. **Roles permitidos en el hub:** solo `cajero` y `admin-sucursal`.
   `admin-empresa` y `superadmin` siguen siendo web.
5. **Enfoque técnico:** auth en el **proceso main** (el renderer nunca ve el token);
   token cifrado con `safeStorage`; renderer con **vue-router**.

## Estado actual relevante del backend

- Sanctum (`laravel/sanctum ^4.0`) está **vendored pero NO inicializado**: no hay
  `config/sanctum.php`, no hay migración `personal_access_tokens`, y el `User` **no**
  tiene el trait `HasApiTokens`.
- `User` usa Spatie `HasRoles`, y tiene `tenant_id`, `branch_id`,
  `force_password_change`, e implementa `MustVerifyEmail`.
- `routes/api.php`: el grupo `prefix('v1')->middleware('auth.apikey')` sirve a las
  básculas. Los nuevos endpoints de auth van bajo `prefix('v1')` **fuera** de ese
  grupo.

## Garantía de no-ruptura (producción)

- **Básculas (`X-Api-Key`):** sus endpoints (`branches/me`, `categories`,
  `products`, `sales`) quedan idénticos, con `auth.apikey` intacto. Los `/api/v1/auth/*`
  son rutas separadas que no pasan por ese middleware.
- **Web Inertia (sesión/cookies):** Sanctum por token Bearer es un guard aparte. NO
  se agrega el middleware stateful SPA de Sanctum al grupo web → la sesión Breeze no
  cambia.
- **Base de datos:** único cambio = tabla nueva `personal_access_tokens` (aditiva) +
  trait `HasApiTokens` en `User` (no cambia columnas existentes).
- La suite de tests existente (básculas e Inertia/Auth) debe seguir verde; se corre
  como verificación.

## Sección 1 — Backend: API de autenticación

**Setup de Sanctum (aditivo):**
- Crear migración `personal_access_tokens` (vía publish del provider de Sanctum).
- Agregar `Laravel\Sanctum\HasApiTokens` al modelo `User`.

**Endpoints nuevos en `routes/api.php`**, bajo `prefix('v1')`, fuera de `auth.apikey`:

| Endpoint | Middleware | Comportamiento |
| --- | --- | --- |
| `POST /api/v1/auth/login` | `throttle:10,1` | Valida `email`+`password`+`device_name`; exige rol ∈ {cajero, admin-sucursal}; devuelve `token` + `user`. |
| `POST /api/v1/auth/logout` | `auth:sanctum` | Revoca el token actual (`currentAccessToken()->delete()`). |
| `GET /api/v1/auth/me` | `auth:sanctum` | Devuelve el `user` actual (para validar/restaurar sesión). |

**Forma de la respuesta `user`:** `{ id, name, email, role, branch_id, branch_name, tenant_id, tenant_slug }`. `role` = el nombre del rol Spatie (cajero | admin-sucursal).

**Reglas de error:**
- Credenciales inválidas → **401** `{ message }`.
- Rol no permitido (admin-empresa/superadmin u otro) → **403** `{ message: 'Este usuario no puede usar el hub.' }`.
- `force_password_change` activo → **409** `{ message: 'Debes cambiar tu contraseña en la web antes de usar el hub.' }`.
- Validación faltante → **422** (estándar Laravel).

**Controlador:** `app/Http/Controllers/Api/AuthController.php` (nuevo), métodos `login`, `logout`, `me`. El token se crea con `device_name` como nombre para poder identificarlo/revocarlo.

## Sección 2 — Hub (proceso main): autenticación y token

Toda la lógica vive en main; el renderer nunca ve el token.

- **`src/main/auth/authApiClient.js` — `AuthApiClient`:** cliente HTTP de los 3
  endpoints, con `fetch` inyectable (testeable como `BackendClient`). `login(creds)`,
  `logout(token)`, `me(token)`. Clasifica respuestas (200 / 401 / 403 / 409 / red).
- **`src/main/auth/authService.js` — `AuthService`:**
  - `login(email, password, deviceName)`: llama a `AuthApiClient.login`; si OK, cifra
    el token con `safeStorage.encryptString` y lo guarda (base64) en `electron-store`;
    guarda `user` (no sensible) en `electron-store`. Devuelve `{ authenticated, user }`
    o un error tipificado (`invalid_credentials` | `role_forbidden` |
    `password_change_required` | `offline`).
  - `logout()`: llama a `AuthApiClient.logout(token)`; borra token cifrado + `user`.
  - `getSession()`: devuelve `{ authenticated, user }` desde lo guardado.
  - `restoreSession()` (al arrancar): si hay token, **online** valida con `me` (401 →
    limpia → no autenticado); **offline** confía en la sesión guardada.
- **Almacenamiento del token:** `safeStorage.encryptString(token)` → base64 en
  `electron-store` (clave `authToken`). Si `safeStorage.isEncryptionAvailable()` es
  falso, guarda el token en claro con log de advertencia (documentado).
- **IPC (`src/main/ipc.js`, ampliado):** `auth:login`, `auth:logout`, `auth:session`.
  El renderer solo recibe `{ authenticated, user }` o `{ error }`. Nunca el token.

## Sección 3 — Renderer (Vue + vue-router)

Reestructura el renderer (hoy un único `App.vue`):

```
src/renderer/
  router.js                 # rutas + guard global de auth
  composables/useAuth.js    # estado de sesión (llama window.hub.auth*)
  views/
    LoginView.vue           # email + password + device_name; muestra errores
    ShellLayout.vue         # barra con usuario/rol/sucursal + logout + nav; <RouterView>
    HomeView.vue            # identidad + estado de conexión
    DeviceView.vue          # monitor actual (cola queued/syncing/synced/failed)
    ConfigView.vue          # config del hub (backend URL, API key, puerto, token local) — solo admin-sucursal
  App.vue                   # contiene <RouterView>
```

- **Rutas:** `/login` (pública) y `/` (ShellLayout) con hijos `home`, `device`,
  `config`.
- **Guard global:** sin sesión → redirige a `/login`. `config` exige rol
  `admin-sucursal` (cajero: no aparece en nav y la ruta redirige a `/home`).
- **`useAuth`:** al cargar llama `window.hub.authSession()`; expone `user`,
  `isAuthenticated`, `login()`, `logout()`. La UI por rol usa `user.role`.
- **Reubicación:** el monitor del `App.vue` actual se mueve a `DeviceView.vue`; la
  config a `ConfigView.vue` (gated). Los handlers IPC `hub:status`/`hub:saveConfig`
  ya existentes se mantienen.
- **Preload (`preload.cjs`):** se amplía `window.hub` con `authLogin(creds)`,
  `authLogout()`, `authSession()`.

## Flujo de datos

```
LOGIN:
  LoginView → useAuth.login → IPC auth:login → AuthService.login
    → POST /api/v1/auth/login → 200 {token, user}
    → cifra token (safeStorage) + guarda user → {authenticated:true, user}
    → router → /home

ARRANQUE (restaurar sesión):
  useAuth (onMounted) → IPC auth:session → AuthService.restoreSession
    → ¿token? online: GET /auth/me (401→limpia→/login) | offline: confía → {authenticated, user}

LOGOUT:
  ShellLayout → useAuth.logout → IPC auth:logout → POST /auth/logout + borra token → /login
```

## Sección 4 — Manejo de errores y casos borde

| Caso | Comportamiento |
| --- | --- |
| Credenciales incorrectas | `401` → "Email o contraseña incorrectos". |
| Rol no permitido | `403` → "Este usuario no puede usar el hub". |
| `force_password_change` activo | `409` → "Debes cambiar tu contraseña en la web antes de usar el hub". |
| Offline al intentar login | Sin red → "Necesitas conexión para iniciar sesión la primera vez". |
| Token expirado/revocado | `401` en `me`/`logout` → limpia sesión → `/login`. |
| Backend caído en restoreSession (offline) | Confía en la sesión guardada → entra al shell. |
| `safeStorage` no disponible | Guarda token sin cifrar + log de advertencia (documentado). |
| Cajero intenta `/config` | Guard redirige a `/home`; nav no muestra "Config". |

## Sección 5 — Testing

- **Backend (PHPUnit):** `tests/Feature/Api/AuthControllerTest.php` — login de cajero y
  admin-sucursal devuelve token; admin-empresa/superadmin → 403; password malo → 401;
  `force_password_change` → 409; `logout` revoca el token; `me` con token válido
  devuelve el usuario y sin token → 401. **Regresión:** correr
  `PresentationSaleContractTest`, `SaleIdempotencyTest` y los tests de Auth/Inertia
  para confirmar que nada se rompió.
- **Hub (Vitest):** `AuthApiClient` (fetch inyectado: 200/401/403/409/red);
  `AuthService` con `safeStorage` y `store` falsos (login cifra y guarda; logout
  limpia; restoreSession online valida y offline confía).
- **Renderer:** test del guard de router (sin sesión → `/login`; cajero no entra a
  `/config`).

## Sección 6 — Fuera de alcance (este hito)

Cambio de contraseña en el hub, refresh tokens, login offline con credenciales
cacheadas, pantallas de caja (cobros/turnos), QR de emparejamiento, empaquetado.
Quedan para hitos posteriores.

## Notas de implementación

- Usar el skill de Electron instalado en `carniceria-hub/.agents/skills/electron/`
  (IPC `invoke/handle`, `contextIsolation` on, `nodeIntegration` off, preload
  `.cjs` + `contextBridge`, `safeStorage` para secretos).
- `vue-router` se agrega como dependencia del proyecto hub.
- El backend cambia en su propia rama; el hub en su repo. Tres entregables
  (backend auth, hub main+renderer) pero un solo flujo de login.
