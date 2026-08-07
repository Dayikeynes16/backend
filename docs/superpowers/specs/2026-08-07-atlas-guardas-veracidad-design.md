# Guardas de veracidad del manifiesto del Atlas

**Estado:** Diseño aprobado; implementación pendiente
**Fecha:** 2026-08-07
**Aplicación anfitriona:** `carniceria-saas`
**Doc viva del módulo:** [atlas-vivo.md](../../frontend/atlas-vivo.md)
**Independiente de:** [rediseño visual](2026-08-07-atlas-rediseno-visual-design.md) — puede implementarse antes, después o en paralelo

## El problema

El manifiesto del Atlas pasa hoy 50 pruebas y un validador en verde mientras
contiene datos falsos. No es un fallo de las pruebas: es que **comprueban una cosa
distinta de la que creemos**.

`validate-architecture-manifest.mjs` y las suites de `tests/Architecture/`
verifican **coherencia interna**: que el esquema cuadre, que los identificadores
sean únicos, que las referencias apunten a entidades existentes, que todo estado
concluyente cite evidencia. El único archivo que el validador abre es el propio
manifiesto. **Nunca leen el código de los cuatro repositorios.**

La auditoría del 2026-08-06 encontró, con todo en verde:

| Hallazgo | Alcance real |
|---|---|
| El commit fijado de `repo.saas` (`098daec`) solo existía en una rama local nunca publicada | **27 de 53** enlaces a GitHub daban 404 |
| Seis `evidence[].symbol` no existen con ese nombre | `createLocalApiServer` es `buildLocalApiServer`; `runMigrations` es `MIGRATIONS`/`migrate`; `main` no existe |
| Dos módulos declarados `pending` / `in-review` ya estaban en `main` | Caducaron 37 y 70 minutos después del snapshot |

El primer hallazgo ya se corrigió a mano (commit `58f2f6f`). Este spec existe para
que **la próxima vez lo atrape una máquina**, porque la deriva demostró ser de
horas, no de meses.

## Principio de diseño

**La validación estructural y la verificación de veracidad son cosas distintas y
no deben mezclarse.**

- `validate:architecture` se queda como está: puro, rápido, sin red, sin git, sin
  dependencias. Corre siempre.
- `verify:architecture` es nuevo: contrasta el manifiesto contra los repositorios
  reales usando git. Es lento, necesita acceso a los cuatro repos y puede quedar
  parcialmente indisponible.

Mezclarlos convertiría el validador rápido en uno que falla sin red — y un
validador que falla por motivos ajenos al manifiesto se acaba ignorando.

## Las cuatro guardas

### G1 · El commit fijado tiene que estar publicado

Para cada `repositories[].commit`:

```bash
git merge-base --is-ancestor <commit> origin/main
```

**Falla** si el commit no es alcanzable desde `origin/main`. Habría atrapado sola
el hallazgo de los 27 enlaces rotos, que es el más caro de los tres porque
degrada silenciosamente la función principal del Atlas: abrir el código.

Un commit que existe localmente pero no en el remoto es indistinguible de uno
inventado para cualquiera que no sea el autor.

### G2 · Los archivos citados tienen que existir en ese commit

Para cada `sourceFiles[].path`, contra el commit de su `repositoryId`:

```bash
git cat-file -e <commit>:<path>
```

**Falla** si el archivo no existe. Se resuelve en lote con `git cat-file --batch-check`
para no pagar 53 procesos.

### G3 · Los símbolos citados tienen que aparecer en su archivo

Para cada `evidence[].symbol`, en el contenido de su `sourceFileId` en ese commit.

Esta es la guarda delicada, porque un `grep` literal produce ruido: `Api\Hub\SaleController`
no aparece así escrito dentro del archivo, y `PaymentController::store` tampoco.
La regla es:

1. Se normaliza el símbolo en **segmentos significativos**, partiendo por `\`, `::`,
   `->` y `/`. `Api\Hub\SaleController` → `["Api","Hub","SaleController"]`.
2. La evidencia pasa si **el último segmento** aparece literalmente en el archivo.
3. Un campo opcional `evidence[].symbolMatch` permite ajustar el rigor:

| Valor | Significado |
|---|---|
| `literal` (por defecto) | El último segmento debe aparecer tal cual |
| `any-segment` | Basta con que aparezca cualquier segmento |
| `exempt` | No se verifica — **exige `symbolMatchReason` no vacío** |

`exempt` sin razón es un error de validación. Así una excepción queda escrita y
auditable en vez de ser un agujero silencioso.

Casos que esta regla habría atrapado: `createLocalApiServer`, `runMigrations`,
`main`, `ticket`, `Api`, y los símbolos en minúscula que en realidad son clases
(`catalogRepo` frente a `CatalogRepo`).

### G4 · Integración continua y aviso de deriva

`carniceria-saas` no tiene CI hoy. Se crea `.github/workflows/architecture.yml`
con tres tareas:

| Tarea | Cuándo | Qué hace | Si falta acceso |
|---|---|---|---|
| `validate` | push y PR que toquen el Atlas | `npm ci`, `validate:architecture`, `test:architecture` | No aplica: no necesita nada externo |
| `verify` | igual | G1, G2 y G3 sobre los cuatro repos | Se degrada a `repo.saas` y avisa; no rompe el build |
| `drift` | cron semanal | Compara `snapshotRefs` con `origin/main` de los cuatro repos | Informa lo que pudo comprobar |

**La deriva no es un error.** El manifiesto documenta un snapshot declarado; que
`origin/main` haya avanzado es normal. `drift` informa cuántos commits de
distancia hay por repositorio y, si alguno supera un umbral configurable, deja el
aviso visible para que alguien decida re-auditar.

## Acceso a los repositorios

El verificador necesita los cuatro repos. Los resuelve en este orden:

1. **Rutas locales.** Por defecto, hermanas del proyecto (`../carniceria-hub`,
   `../bascula`, `../bascula-android`), configurables por
   `ATLAS_REPOS_<ID>` o un `atlas-repos.json` opcional. Es el camino del día a día:
   instantáneo y sin red.
2. **Clon parcial cacheado.** Si no hay ruta local, clona con
   `--filter=blob:none --no-checkout` a un directorio de caché y hace
   `git fetch origin <commit>` del SHA concreto. Evita descargar árboles enteros.
3. **No verificable.** Si tampoco hay red o credenciales, ese repositorio se marca
   como no verificado y se informa.

Un repositorio no verificable **no rompe** la ejecución normal. Con `--strict` sí:
ese es el modo que corre CI cuando hay credenciales, y el que debería exigirse
antes de dar por buena una re-auditoría.

Los cuatro repos son privados y del mismo propietario, así que `verify` en CI
necesita un token con lectura sobre los tres externos, en el secreto
`ATLAS_REPOS_TOKEN`. Sin ese secreto la tarea se degrada sola.

## Arquitectura

Se separa la decisión de la ejecución, para que la parte con reglas sea
verificable sin red:

| Archivo | Responsabilidad única |
|---|---|
| `scripts/verify-architecture-evidence.mjs` | Entrada por línea de comandos, salida y código de salida |
| `lib/architecture/evidenceRules.js` | **Puro.** Normaliza símbolos, aplica `symbolMatch`, decide veredictos |
| `lib/architecture/repoResolver.js` | Ubica cada repositorio: local, caché o no disponible |
| `lib/architecture/gitProbe.js` | Envoltura fina de git: `isAncestor`, `fileExists`, `readFile`, `aheadCount` |

`evidenceRules.js` no sabe qué es git; recibe un sondeador y devuelve veredictos.
Las pruebas le inyectan uno simulado, así que la lógica que más se va a equivocar
—la normalización de símbolos— se prueba sin clonar nada.

### Salida

```text
Atlas · verificación de evidencia contra los repositorios

  repo.saas            5180648  ✓ publicado    27 archivos  27 símbolos
  repo.hub             cd663a6  ✓ publicado    12 archivos  11 símbolos  1 exento
  repo.scale-web       a5a54da  ✓ publicado     6 archivos   6 símbolos
  repo.scale-android   8828dae  ✓ publicado     8 archivos   8 símbolos

  Deriva: repo.hub va 14 commits por delante del snapshot declarado.

  2 problemas:
   ✗ evidence[29] ev.hub.local-api — símbolo «createLocalApiServer» ausente
       en src/main/localApiServer.js (commit cd663a6)
   ✗ sourceFiles[41] file.hub.migrations — la ruta no existe en cd663a6
```

Código de salida `1` si hay problemas; `0` con solo avisos de deriva.

## Cambios al manifiesto

Dos campos opcionales en `evidence[]`. **Ningún cambio semántico**: no se toca
ningún estado, evidencia ni relación auditada.

| Ruta | Tipo | Obligatorio | Descripción |
|---|---|---|---|
| `evidence[].symbolMatch` | `"literal" \| "any-segment" \| "exempt"` | no | Rigor de G3; por defecto `literal` |
| `evidence[].symbolMatchReason` | `string` | solo si `symbolMatch` es `exempt` | Por qué no se puede verificar |

`metadata.schemaVersion` sube a `1.0.1`. Si el rediseño visual se implementa
antes, absorbe este cambio en su `1.1.0`.

## Alcance del arreglo de datos

Este spec incluye **corregir los hallazgos que las guardas destapen al ejecutarse
por primera vez**, porque un verificador nuevo que arranca en rojo se desactiva a
la semana. En concreto, los ya conocidos de la auditoría:

- Los seis símbolos incorrectos de la categoría P3.
- Las cuatro atribuciones archivo↔dato de la categoría P2: `cashier_expenses_enabled`
  y `cashier_purchases_enabled` atribuidos a `routes/api.php`; dos feature flags
  declarados en el módulo del Hub que no existen en ese repositorio; WAL y
  respaldos atribuidos a `migrations.js` en vez de `database.js` y `backup.js`;
  el evento `AgendaItemAssigned` apuntando al controlador en vez de a su clase.

**Fuera de alcance**, y conviene decirlo: los dos estados caducados de la
categoría P1 (`scale-android.pairing` y `hub.pairing.scale-devices`, ambos ya
consolidados en `main`). Ninguna de las cuatro guardas los detecta — un estado
`pending` es una afirmación sobre lo que *no* existe, y eso no se comprueba
buscando. Corregirlos es una re-auditoría manual, que es exactamente lo que `drift`
existe para recordar.

## Pruebas

| Suite | Verifica |
|---|---|
| `evidence-rules.test.mjs` | Segmentación de símbolos (`\`, `::`, `->`, `/`); los tres modos de `symbolMatch`; `exempt` sin razón es error; veredictos con sondeador simulado |
| `repo-resolver.test.mjs` | Preferencia local sobre caché; repositorio ausente marcado no verificable; `--strict` convierte no verificable en fallo |
| `architecture-manifest.test.mjs` | Ampliada: los campos nuevos son opcionales y compatibles hacia atrás |

Las pruebas **no tocan la red ni clonan nada**: todo el acceso a git pasa por el
sondeador inyectado. La ruta real de git se ejerce en CI, que es donde tiene
sentido pagar ese costo.

## Criterios de aceptación

- [ ] `npm run verify:architecture` corre en local contra los cuatro repos del workspace.
- [ ] G1 falla ante un commit no alcanzable desde `origin/main`.
- [ ] G2 falla ante una ruta inexistente en el commit fijado.
- [ ] G3 falla ante un símbolo ausente, y `exempt` sin razón es error de validación.
- [ ] Un repositorio inaccesible informa sin romper; con `--strict` falla.
- [ ] `drift` informa la distancia en commits por repositorio sin marcarla como error.
- [ ] El workflow corre `validate` y `test:architecture` en cada push que toque el Atlas.
- [ ] `verify` se degrada limpiamente cuando falta `ATLAS_REPOS_TOKEN`.
- [ ] Los hallazgos P2 y P3 quedan corregidos y la verificación arranca en verde.
- [ ] `validate:architecture` sigue sin requerir red, git ni dependencias nuevas.
- [ ] La doc viva documenta el comando nuevo y su papel en el procedimiento de actualización.
