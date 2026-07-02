# Retiros de efectivo para cajero + corte con desglose neto

**Fecha:** 2026-07-02
**Estado:** Aprobado (diseño)

## Contexto y problema

Dos necesidades del dueño operando la caja:

1. **Retiros de efectivo desde el rol cajero.** Hoy los retiros (`CashWithdrawal`)
   solo se pueden registrar desde el panel de sucursal (`admin-sucursal`). El
   cajero, que es quien físicamente saca dinero del cajón durante el turno, no
   tiene forma de registrarlo. La UI de retiros ya existe pero vive incrustada
   en `Sucursal/Turno/Active.vue`.

2. **El mensaje de corte confunde cuando hay descuadres cruzados.** El reporte
   de WhatsApp y las pantallas de corte hoy encabezan con el veredicto de
   **efectivo** (`difference`). Si al cajero le falta $50 en efectivo pero le
   sobran $50 en tarjeta (típico: cobró una venta con el método equivocado), el
   mensaje grita "⚠️ Faltante de $50 en efectivo" cuando en realidad **la caja
   neta cuadra**. El dueño necesita ver primero el **veredicto neto del turno**
   (vendido/cobrado → esperado total vs declarado total) y luego el desglose por
   método que explica de dónde salen las diferencias que se compensan.

### Bug colateral detectado

En `ShiftReportMessageService::buildShiftCloseText()`, el bloque "ARQUEO DE
EFECTIVO" muestra la cuenta:

```
Fondo inicial + Efectivo cobrado − Retiros = Esperado en cajón
```

Pero `expected_amount` se calcula en `ShiftCashOutCalculator` como:

```
opening + total_cash − withdrawals − cash_expenses − cash_provider_payments
```

Es decir, **cuando hay gastos o compras en efectivo, la aritmética impresa en el
mensaje no cuadra** (falta restar gastos y compras). Las pantallas Vue sí los
muestran ("Salidas en efectivo"), pero el texto de WhatsApp no. Se corrige como
parte de este trabajo.

## Objetivos

- El cajero puede **registrar** retiros de efectivo durante su turno abierto.
- El cajero puede **eliminar** sus propios retiros mientras el turno siga
  abierto (paridad con la corrección de gastos/compras propias de la caja).
- El mensaje de corte (WhatsApp) y las pantallas de corte cuentan la misma
  historia, en este orden: **veredicto neto → resumen del turno → desglose por
  método → arqueo de efectivo detallado**.
- El veredicto distingue explícitamente el caso de **compensación** (el neto
  cuadra o el faltante real es menor que el descuadre de un método individual).
- El arqueo de efectivo del mensaje cuadra aritméticamente (incluye gastos y
  compras en efectivo).

## No-objetivos (YAGNI)

- No se cambia cómo se **calculan** los totales del turno (`ShiftTotalsCalculator`,
  `ShiftCashOutCalculator` quedan intactos salvo su consumo).
- No se agrega categorización ni límites a los retiros (siguen siendo monto +
  motivo libre).
- No se toca el flujo de reapertura/recálculo de cortes históricos.
- No se cambia el modelo de datos (`cash_withdrawals` ya existe con `shift_id`,
  `user_id`, `amount`, `reason`, `created_at`).

---

## Parte 1 — Retiros de efectivo para el cajero

### Rutas

En el grupo `caja` (`routes/web.php`, middleware `role:cajero|superadmin`),
junto a las rutas de turno de caja:

```php
Route::post('turno/retiros', [WithdrawalController::class, 'store'])
    ->name('turno.withdrawal.store');
Route::delete('turno/retiros/{withdrawal}', [WithdrawalController::class, 'destroy'])
    ->name('turno.withdrawal.destroy');
```

Se reutiliza el **mismo `App\Http\Controllers\Sucursal\WithdrawalController`**
(no se crea uno nuevo; la lógica es idéntica y ya resuelve el turno abierto del
usuario autenticado). Los nombres de ruta quedan `caja.turno.withdrawal.store`
y `caja.turno.withdrawal.destroy`.

### Cambio en `WithdrawalController@store`

Ninguno funcional. Ya busca `CashRegisterShift::where('user_id', $user->id)
->whereNull('closed_at')->firstOrFail()`, lo que funciona igual para cajero.

Mejora menor: el mensaje de éxito formatea el monto crudo (`"Retiro de
\${$validated['amount']}"`). Se deja igual para no ampliar alcance, salvo que el
reviewer lo marque.

### Cambio en `WithdrawalController@destroy`

Hoy solo permiten borrar los roles admin. Se amplía para permitir que **el
cajero dueño del turno abierto** borre sus propios retiros:

```php
public function destroy(CashWithdrawal $withdrawal): RedirectResponse
{
    $user = Auth::user();
    $shift = $withdrawal->shift;

    // Aislamiento de tenant/sucursal primero (aplica a todos los roles).
    if (! $shift || $shift->branch_id !== $user->branch_id) {
        abort(403, 'Este retiro no pertenece a tu sucursal.');
    }
    if ($shift->tenant_id !== $user->tenant_id) {
        abort(403, 'Este retiro no pertenece a tu empresa.');
    }

    $isManager = $user->hasRole('admin-sucursal')
        || $user->hasRole('admin-empresa')
        || $user->hasRole('superadmin');

    // El cajero dueño puede borrar SOLO en su propio turno abierto.
    $isOwnerOnOpenShift = $shift->user_id === $user->id
        && $shift->closed_at === null;

    if (! $isManager && ! $isOwnerOnOpenShift) {
        abort(403);
    }

    $withdrawal->delete();

    return back()->with('success', 'Retiro eliminado.');
}
```

Nota sobre el aislamiento cross-tenant: `CashRegisterShift` aplica `TenantScope`,
por lo que para un retiro de **otro tenant** la relación `$withdrawal->shift`
resuelve a `null` (el scope filtra el shift fuera del tenant actual) y la guarda
`! $shift` dispara el primer 403. La comparación `$shift->tenant_id !==
$user->tenant_id` queda como defensa en profundidad, aunque en la práctica es
inalcanzable para cross-tenant porque `$shift` ya es `null`. El resultado (403)
y el test se sostienen.

Reglas resultantes:

| Actor                         | Turno abierto propio | Turno cerrado propio | Turno de otro |
| ----------------------------- | -------------------- | -------------------- | ------------- |
| Cajero                        | ✅ borra             | ❌ 403               | ❌ 403        |
| admin-sucursal/empresa/super  | ✅ (misma sucursal)  | ✅ (misma sucursal)  | ✅ (misma suc.)|

Nota: `CashWithdrawal` no usa `BelongsToTenant`, por eso el aislamiento se valida
a mano vía `shift` (igual que hoy). El binding `{withdrawal}` es implícito por id;
la verificación de sucursal/tenant impide IDOR entre tenants.

### UI — componente compartido `WithdrawalsPanel.vue`

Se extrae el bloque de retiros hoy incrustado en `Sucursal/Turno/Active.vue`
(líneas 49-101) a `resources/js/Components/Turno/WithdrawalsPanel.vue`.

**Props:**

```js
withdrawals: { type: Array, default: () => [] },   // shift.withdrawals
storeRouteName: { type: String, required: true },   // p.ej. 'caja.turno.withdrawal.store'
destroyRouteName: { type: String, required: true }, // p.ej. 'caja.turno.withdrawal.destroy'
tenantSlug: { type: String, required: true },
```

Encapsula el `useForm` del alta, el toggle del formulario, y el `router.delete`
con confirm. Emite nada; usa navegación Inertia con `preserveScroll`. El botón
"Eliminar" siempre se muestra: el panel solo se renderiza en pantallas de turno
**abierto** (Active de caja y sucursal), donde tanto el cajero dueño como el
admin pueden borrar. No hay prop `canDelete` porque no existe un montaje donde
el borrado esté prohibido.

**Montaje:**

- `Sucursal/Turno/Active.vue`: reemplaza el bloque inline por
  `<WithdrawalsPanel>` en el slot `#extra`, con las rutas `sucursal.*`.
- `Caja/Turno/Active.vue`: se agrega el slot `#extra` con `<WithdrawalsPanel>`
  usando rutas `caja.*`.

El efectivo esperado que muestra `CierreTurnoPanel` ya descuenta retiros
(`totals.expected_cash` viene de `ShiftCashOutCalculator`), y el controlador de
caja (`Caja/TurnoController@index`) ya calcula `withdrawals` y recarga
`$shift->load('withdrawals')`. **No hay cambios de cálculo.** Tras registrar o
borrar un retiro, la recarga Inertia de la página trae los totales frescos.

### Tests (Parte 1)

Feature test `tests/Feature/Caja/CajaWithdrawalTest.php`:

- Cajero con turno abierto registra retiro → 302 + fila en `cash_withdrawals`.
- Cajero registra retiro sin turno abierto → falla (firstOrFail → 404/redirect).
- Cajero borra su propio retiro con turno abierto → registro eliminado.
- Cajero **no** puede borrar su retiro con **turno cerrado** → 403, registro intacto.
- Cajero **no** puede borrar retiro de **otro usuario** → 403.
- Cajero **no** puede borrar retiro de **otro tenant** → 403.
- (Regresión) admin-sucursal sigue pudiendo borrar retiro de turno cerrado de su sucursal.

---

## Parte 2 — Corte con desglose neto

### Fuente única de verdad: `ShiftVerdictService` (PHP)

En vez de duplicar la lógica del veredicto en PHP (texto WhatsApp) y JS
(pantallas), se calcula **una sola vez en el backend** con un servicio nuevo
`app/Services/ShiftVerdictService.php`, y se consume desde:

- `ShiftReportMessageService` → construye el texto de WhatsApp.
- Los controladores de corte (`Caja/TurnoController@showCorte`,
  `Sucursal/CashShiftController@show`) → pasan el veredicto como **prop Inertia**
  `verdict` a las pantallas. Como Inertia renderiza en el server, esto NO agrega
  un round-trip: la prop llega en la respuesta inicial. Las pantallas solo
  **renderizan** el veredicto; no reimplementan la regla. Esto elimina la
  duplicación PHP/JS y su riesgo de divergencia (no hay runner de tests JS en el
  proyecto para cubrir un helper JS).

**Firma:** `ShiftVerdictService::build(CashRegisterShift $shift): array`, puro,
operando sobre campos ya persistidos. Devuelve un array-shape:

```php
[
  'status' => 'balanced'|'cross_balanced'|'net_off'|'undeclared',
  'tone' => 'ok'|'warn'|'bad'|'neutral',
  'headline' => string,        // p.ej. "Faltante total de $50.00"
  'detail' => ?string,         // línea de compensación, si aplica
  'expected_total' => float,
  'declared_total' => float,   // null-safe: 0 si no declarado
  'total_diff' => float,       // declared_total − expected_total
  'by_method' => [ ['key','label','expected','declared','diff','applies'], ... ],
]
```

### Criterio de "método aplicable" (unificado con las tablas)

**Decisión (resuelve la inconsistencia detectada en revisión):** el servicio usa
el MISMO criterio y las MISMAS derivaciones que las tablas por método ya
existentes en `Caja/Turno/Corte.vue` (líneas 47-60) y `Sucursal/Cortes/Show.vue`
(líneas 78-92), para que el resumen neto siempre sea igual a la suma de las filas
visibles:

- **Aplica** un método si `declared_* !== null` **o** `total_* > 0`.
- `expected` = efectivo→`expected_amount`; tarjeta→`total_card`;
  transferencia→`total_transfer`.
- `declared` = `declared_* ?? total_*` (si no se declaró pero hubo movimiento, se
  toma el total como declarado, igual que la tabla).
- `diff` = `difference_*` (ya persistido; 0 cuando el método no se declaró).

Con esto, un método con movimiento pero sin declarar (`declared_* === null &&
total_* > 0`, alcanzable solo en cortes históricos/recalculados o cerrados desde
sucursal) aparece tanto en la tabla como en el total neto, y las sumas cuadran.

### Reglas del veredicto

Sobre los métodos aplicables:
- `totalDiff` = suma de `diff` de los aplicables.
- `anyMethodOff` = existe algún aplicable con `diff ≠ 0`.
- `signsMixed` = coexisten `diff` positivos y negativos entre aplicables.
- `allUndeclared` = **ningún** método aplicable tiene `declared_* !== null`
  (cierre realmente sin arqueo).

Veredicto (en orden de prioridad):

1. **Sin conteo declarado** (`allUndeclared`): `📋 Cierre sin conteo declarado.`
   → `status: undeclared`, `tone: neutral`.
   (Se corrige el gate: hoy `verdictLine` mira solo `declared_amount === null`;
   ahora exige que TODOS los métodos aplicables estén sin declarar, para no
   ocultar datos cuando p.ej. efectivo no se declaró pero tarjeta sí.)
2. **Todo cuadra** (`!anyMethodOff`): `✅ Caja cuadrada — sin diferencias.`
   → `status: balanced`, `tone: ok`.
3. **Neto cuadra pero hay cruces** (`totalDiff === 0 && anyMethodOff`):
   `⚖️ La caja cuadra en total, pero hay diferencias cruzadas entre métodos.`
   + `detail`: "faltan $X en {método}, sobran $Y en {método} — posible cobro
   registrado con otro método." → `status: cross_balanced`, `tone: warn`.
4. **Descuadre neto real** (`totalDiff !== 0`):
   `⚠️ {Faltante|Sobrante} total de $|totalDiff|.` → `status: net_off`,
   `tone: bad`.
   - Si además `signsMixed` (compensación parcial): `detail` = "el {faltante|
     sobrante} real es $|totalDiff|: faltan $X en {m}, sobran $Y en {m}."

`Faltante` cuando `totalDiff < 0`, `Sobrante` cuando `> 0` (declarado − esperado).

### `ShiftReportMessageService` — nueva estructura del texto

El servicio llama a `ShiftVerdictService::build($shift)` y usa `headline` +
`detail` para el veredicto de arriba, y `by_method` / `*_total` para el resumen y
el desglose. Orden de secciones:

```
*CORTE DE CAJA*
_Tenant — Sucursal_

<VEREDICTO NETO>            ← nuevo, basado en el helper de arriba

Cierre / Cajero / Turno
━━━━━━━━━━━━━━━━━━

📊 *RESUMEN DEL TURNO*
• Vendido: N ventas · $sales_generated_amount
  (Canceladas: … — si las hay)
• Cobrado en el turno: $total_collected
  (↳ de ventas del turno / ↳ abonos a fiados anteriores — si aplica)
• Esperado total (todos los métodos): $expectedTotal
• Declarado por el cajero: $declaredTotal   (o "no declarado")
• *Diferencia total: $signed(totalDiff)* {✅ | ⚠️}

💳 *DESGLOSE POR MÉTODO*   _esperado → declarado_
• Efectivo: $exp → $dec (±$diff faltante/sobrante | ✅)
• Tarjeta: …    (solo métodos aplicables)
• Transferencia: …

🧾 *ARQUEO DE EFECTIVO*
• Fondo inicial: $opening
• + Efectivo cobrado: $total_cash
• − Retiros: $withdrawals              (si > 0)
• − Gastos en efectivo: $cash_expenses (si > 0)   ← NUEVO, corrige el bug
• − Compras en efectivo: $cash_provider_payments (si > 0) ← NUEVO
• = Esperado en cajón: $expected_amount
• Contado por el cajero: $declared_amount  (o "no declarado")
• Diferencia: …

_Notas del cajero…_ (si hay)
━━━━━━━━━━━━━━━━━━
_Reporte automático del corte_
```

Definiciones (idénticas a las de `ShiftVerdictService`, ver arriba):
- `expectedTotal` = suma de `expected` de métodos aplicables (efectivo usa
  `expected_amount`; tarjeta/transferencia usan `total_card`/`total_transfer`).
- `declaredTotal` = suma de `declared` de métodos aplicables, donde
  `declared = declared_* ?? total_*`.
- `totalDiff` = `declaredTotal − expectedTotal` (= suma de `difference_*`).
- Los datos de gastos/compras salen de `total_cash_expenses` y
  `total_cash_provider_payments` (ya persistidos en el shift al cerrar), así que
  **no** hace falta cargar relaciones nuevas para el texto.

El límite `MAX_TEXT_BYTES` y `truncateIfNeeded` se conservan.

### Pantallas Vue

Ambos controladores pasan una prop nueva `verdict` (el array de
`ShiftVerdictService::build`). Las pantallas **renderizan** ese objeto; no
recalculan la regla. Las tablas por método existentes (que ya derivan sus filas
de los campos del `shift`) se conservan tal cual y quedan alineadas con el
resumen porque comparten el criterio de aplicabilidad.

**`Caja/Turno/Corte.vue` y `Sucursal/Cortes/Show.vue`:**

- Se añade, arriba de todo (bajo el banner de éxito / WhatsApp), un **bloque de
  veredicto** que muestra `verdict.headline` con tono `verdict.tone`
  (verde/ámbar/rojo/neutro) y, cuando existe, `verdict.detail` (línea de
  compensación).
- El bloque "Header info" gana la línea **Vendido vs Cobrado** (hoy solo muestra
  "Total cobrado"): se agrega `sales_generated_amount`/`sales_generated_count`
  como "Vendido" para el paralelo vendido→cobrado.
- Se añade una fila resumen **Esperado total / Declarado total / Diferencia
  total** (de `verdict.expected_total` / `declared_total` / `total_diff`) como
  encabezado de la tabla por método; el desglose por método queda como detalle.
- La tabla por método existente no cambia de estructura.

Cambios de backend acotados: cada controlador agrega
`'verdict' => $verdictService->build($shift)` a su `Inertia::render`
(inyectando `ShiftVerdictService`). Los campos `sales_generated_*` ya vienen en
`shift`, no requieren carga extra.

### Tests (Parte 2)

La lógica del veredicto vive en PHP (`ShiftVerdictService`), así que los tests
la cubren directamente y el texto/pantallas solo la consumen.

Unit test `tests/Unit/ShiftVerdictServiceTest.php` cubriendo `build`:

- **Cuadrado:** diferencias 0 → `status: balanced`, `total_diff` 0.
- **Faltante simple:** efectivo −$50 → `status: net_off`, headline "Faltante
  total de $50.00", `detail` null.
- **Compensación total:** efectivo −$50, tarjeta +$50 → `status:
  cross_balanced`, `total_diff` 0, `detail` con "faltan $50… sobran $50…".
- **Compensación parcial:** efectivo −$80, tarjeta +$30 → `status: net_off`,
  headline "Faltante total de $50.00", `detail` "faltan $80… sobran $30…".
- **Método con movimiento sin declarar:** `declared_card === null`,
  `total_card > 0` → aparece en `by_method` con `applies: true`, y
  `expected_total`/`declared_total` incluyen ese método (suma cuadra).
- **Sin conteo declarado:** todos los `declared_*` null → `status: undeclared`.

Unit/Feature test `tests/Unit/ShiftReportMessageServiceTest.php` cubriendo
`buildShiftCloseText` (integración del texto):

- El texto contiene el headline del veredicto, el bloque "RESUMEN DEL TURNO" con
  "Diferencia total", y el "DESGLOSE POR MÉTODO".
- **Arqueo con gastos/compras:** con `total_cash_expenses`/
  `total_cash_provider_payments` > 0, el texto incluye las líneas "− Gastos" y
  "− Compras", y se verifica `fondo + cobrado − retiros − gastos − compras ==
  expected` (corrige el bug del arqueo).

---

## Archivos afectados

**Backend**
- `routes/web.php` — 2 rutas nuevas en grupo `caja`.
- `app/Http/Controllers/Sucursal/WithdrawalController.php` — `destroy` amplía permiso al cajero dueño en turno abierto.
- `app/Services/ShiftVerdictService.php` — **nuevo**, fuente única del veredicto neto y totales.
- `app/Services/ShiftReportMessageService.php` — consume `ShiftVerdictService`; reestructura el texto: veredicto neto, resumen, desglose por método, arqueo con gastos/compras.
- `app/Http/Controllers/Caja/TurnoController.php` — `showCorte` pasa prop `verdict`.
- `app/Http/Controllers/Sucursal/CashShiftController.php` — `show` pasa prop `verdict`.

**Frontend**
- `resources/js/Components/Turno/WithdrawalsPanel.vue` — **nuevo**, extraído de Sucursal.
- `resources/js/Pages/Sucursal/Turno/Active.vue` — usa `WithdrawalsPanel`.
- `resources/js/Pages/Caja/Turno/Active.vue` — agrega slot `#extra` con `WithdrawalsPanel`.
- `resources/js/Pages/Caja/Turno/Corte.vue` — renderiza prop `verdict` + vendido/cobrado + total neto.
- `resources/js/Pages/Sucursal/Cortes/Show.vue` — idem.

**Tests**
- `tests/Feature/Caja/CajaWithdrawalTest.php` — **nuevo**.
- `tests/Unit/ShiftVerdictServiceTest.php` — **nuevo**.
- `tests/Unit/ShiftReportMessageServiceTest.php` — **nuevo** (integración del texto).

## Riesgos y decisiones

- **Paridad de permisos:** el cajero borrando retiros propios en turno abierto es
  consistente con la corrección de gastos/compras propias que ya permite la caja
  (ver rutas `caja.gastos.destroy`, `caja.compras.cancel`). Al cerrar el turno,
  el retiro queda inmutable para el cajero; solo un admin lo altera reabriendo.
- **Sin duplicación de lógica:** el veredicto se calcula solo en PHP
  (`ShiftVerdictService`) y se comparte entre el texto de WhatsApp y las
  pantallas vía prop Inertia. No hay helper JS que pueda divergir, y como Inertia
  renderiza server-side no hay round-trip extra. Los tests PHP cubren la regla.
- **Corte histórico:** el servicio opera sobre campos ya persistidos
  (`difference_*`, `declared_*`, `total_*`), así que cortes viejos se
  reinterpretan correctamente sin migración.
