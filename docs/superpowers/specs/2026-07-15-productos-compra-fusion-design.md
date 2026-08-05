# Fusión de productos de compra duplicados

**Fecha:** 2026-07-15
**Estado:** Implementado (2026-07-15) — ver docs/modulos/compras.md § Fusión de productos de compra duplicados
**Alcance:** solo la **fusión de duplicados existentes** en el catálogo de productos de compra (`purchase_products`). La **prevención** de nuevos duplicados (sugerir producto existente al capturar, nota por línea en el formulario, arreglar el pipeline de IA) es un spec aparte y posterior.

## Problema

El catálogo de productos de compra acumula fichas que son el **mismo producto** con variaciones de nombre. Caso real: 80+ fichas "Canal de res 111", "Canal de res 112", "Canal de res 123"… El número de introducción de cada res quedó embebido en el nombre.

**Causa raíz** (confirmada en el código): al capturar una compra, `PurchaseWriter::resolvePurchaseProduct()` hace *find-or-create* por **nombre exacto** (`LOWER(name)`). Como cada número produce un nombre distinto, se crea una ficha nueva cada vez. No existe hoy ninguna función de fusión ni de detección de duplicados para `purchase_products`.

Consecuencia: el catálogo es inmanejable y los reportes se fragmentan (la app **hub** agrupa por el texto `purchase_items.concept`, y aunque el **detalle de proveedor web** agrupa por `purchase_product_id`, el usuario no puede consolidar).

## Decisión

Una función de **fusión manual asistida por búsqueda**: el admin de empresa busca las fichas duplicadas, las selecciona, elige la **canónica** (la que sobrevive) y fusiona. La operación reapunta el historial de compras a la ficha canónica, normaliza el texto histórico preservando el dato variable, y da de baja las fichas absorbidas.

Decisiones tomadas con el usuario (2026-07-15, con mockups en visual companion):

1. **Selección por búsqueda** (opción A): buscador dentro de un modal → "seleccionar todas" → elegir la canónica → fusionar. Descartadas: detección automática de grupos por similitud (más ambiciosa, "¿qué tan parecido es parecido?") y selección manual con checkboxes en la lista completa (tedioso con 80+ fichas).
2. **Historial**: al reapuntar cada línea, **renombrar `concept` al nombre canónico y mover el resto a la nota de la línea** (`notes`). Nunca se pierde información (ver §3). Descartadas: reescribir perdiendo el número, y no tocar el historial (dejaría el hub fragmentado).
3. **Permisos: solo `admin-empresa`.** Consistente con el borrado de productos de compra, que hoy ya es exclusivo de empresa. La fusión es destructiva. Sucursal y hub no la tienen.

## Modelo de datos afectado (sin migraciones)

No se crean ni alteran tablas. La fusión opera sobre columnas existentes:

- `purchase_products`: `SoftDeletes` ya presente → las fichas absorbidas se **soft-deletean**.
- `purchase_items.purchase_product_id` (FK `nullOnDelete`) → se **reapunta** al canónico.
- `purchase_items.concept` (string 160, NOT NULL, snapshot histórico) → se **reescribe** al nombre canónico.
- `purchase_items.notes` (string 500, nullable) → recibe el sufijo/resto del concept viejo.
- `audit_logs` (vía trait `RecordsHistory` en `PurchaseProduct`) → registra la fusión.

## Diseño

### 1. Regla de normalización del texto histórico

Para cada `purchase_item` reapuntado desde una ficha absorbida hacia la canónica (nombre `C`), con `concept` viejo `V` y `notes` viejo `N`:

- **Calcular el "resto"** `R`:
  - Si `V` (case-insensitive) empieza con `C` seguido de un separador (espacio, guion, etc.), `R` = lo que sigue, sin espacios extremos (ej. `V="Canal de res 111"`, `C="Canal de res"` → `R="111"`).
  - En cualquier otro caso (incluido `V` que no contiene `C`), `R` = `V` completo (ej. `V="canal res viejo"` → `R="canal res viejo"`). Así nunca se pierde texto.
  - Si `V` es exactamente `C` (ya normalizado), `R` = `""` (nada que mover).
- **Nuevo `notes`**:
  - Si `R` está vacío → `notes` queda igual (`N`).
  - Si `N` está vacío → `notes` = `R`.
  - Si ambos tienen contenido → `notes` = `R + " · " + N` (el dato variable primero), truncado a 500.
- **Nuevo `concept`** = `C`.

Las líneas que ya pertenecían a la ficha canónica **no se tocan** (su concept ya es el histórico correcto). Normalizar retroactivamente las líneas del propio canónico queda fuera de alcance.

### 2. Servicio `PurchaseProductMergeService`

`app/Services/Purchases/PurchaseProductMergeService.php` (nuevo). Método público:

```
merge(PurchaseProduct $canonical, array $absorbedIds): MergeResult
```

- Todo dentro de `DB::transaction`.
- `lockForUpdate` sobre el canónico y los absorbidos (scoped al tenant vía el global scope de `BelongsToTenant`).
- Filtra `absorbedIds`: descarta el propio canónico, los que no existan o no sean del tenant (defensa multi-tenant + idempotencia si otra sesión ya borró uno).
- Para cada absorbido: recorre sus `purchase_items`, aplica la regla §1 (update de `purchase_product_id`, `concept`, `notes`), luego `->delete()` (soft) la ficha.
- Devuelve un `MergeResult` con `{ absorbed_count, relinked_items_count }` para el flash de éxito.
- Registra en auditoría vía `AuditLogger` (evento de fusión con snapshot: canónico + lista de nombres absorbidos + conteo de líneas).

### 3. Endpoints (solo admin-empresa)

En `routes/web.php`, dentro del grupo `empresa` (mismo bloque que el resto de `productos-compra`):

- `GET productos-compra/fusionar/candidatos?q=` → `Empresa\PurchaseProductController@mergeCandidates`: devuelve `{ data: [{id, name, unit}] }` de productos activos que coinciden con `q` (hasta 500). **Necesario** porque el índice pagina de 25 en 25 y el modal debe poder alcanzar las 80+ fichas de un mismo tipo, cosa que filtrar la página ya cargada no permite.
- `POST productos-compra/fusionar/preview` → `Empresa\PurchaseProductController@mergePreview`: recibe `{ canonical_id, absorbed_ids[] }`, devuelve JSON `{ absorbed_count, items_count, unit_mismatch: bool }` **sin ejecutar nada** (para el panel de impacto y la advertencia de unidades).
- `POST productos-compra/fusionar` → `Empresa\PurchaseProductController@merge`: ejecuta la fusión, redirige al índice con flash de éxito.

Ambos validan que `canonical_id` y `absorbed_ids` existan y sean del tenant. `merge` re-verifica todo server-side (no confía en el preview). Sucursal (`Sucursal\PurchaseProductController`) y hub (`Api\Hub\PurchaseProductController`) **no** reciben estas rutas.

### 4. Validaciones y casos borde

- `absorbed_ids` no vacío tras filtrar; el canónico nunca se auto-absorbe.
- Todas las fichas del mismo tenant (defensa; el global scope ya ayuda, pero se valida explícito).
- **Unidades distintas** (kg vs pza): se permite; cada `purchase_item` conserva su propia `unit`, así que no se corrompe nada. El preview marca `unit_mismatch: true` y el modal **advierte** ("estas fichas tienen unidades distintas, ¿seguro que son el mismo producto?"), sin bloquear.
- Fusión **no reversible** desde la UI (el soft-delete permite recuperación manual por un dev). Confirmación fuerte obligatoria mostrando el impacto.

### 5. UI

- Botón **"Fusionar duplicados"** en la pantalla Productos de compra, **visible solo para admin-empresa** (la pantalla ya distingue rol; el botón se oculta en sucursal).
- Modal `resources/js/Components/Compras/FusionarProductosModal.vue`:
  - Buscador con búsqueda **contra el servidor** (endpoint de candidatos, debounce ~250 ms) — no filtra solo la página paginada, que solo trae 25 fichas.
  - Checkboxes + "seleccionar todas las coincidencias".
  - Selector de **ficha canónica** entre las seleccionadas (por defecto, la de **nombre más corto** — suele ser la forma "limpia", p. ej. "Canal de res" sobre "Canal de res 111"; el usuario puede cambiarla).
  - Al confirmar selección, llama a `.../fusionar/preview` y muestra el **panel de impacto** ("83 fichas → 'Canal de res' · 340 líneas se reapuntarán") + la advertencia de unidades si aplica.
  - Diálogo de confirmación fuerte (`ConfirmDialog` existente, variante danger) antes del `POST .../fusionar`.
- Estilo Tailwind consistente con el resto de la pantalla; sin `@material/web`.

#### Calidad visual (requisito explícito del usuario)

El modal debe sentirse **moderno y pulido, con estética tipo app de iOS**, no un formulario genérico:

- **Superficies y forma:** esquinas redondeadas generosas (`rounded-2xl`/`rounded-3xl`), fondos limpios, sombras suaves difusas (no bordes duros), spacing amplio y respiración entre secciones.
- **Jerarquía tipográfica clara:** título grande y con peso, subtítulos discretos, cifras del panel de impacto destacadas (número grande + etiqueta pequeña), estilo "Settings de iOS".
- **Selección con feedback inmediato:** filas de producto tipo lista iOS (separadores sutiles, no tabla densa); el ítem seleccionado y el canónico se distinguen con acento de color y check animado; toques/hover con transición suave.
- **Movimiento con intención:** entrada/salida del modal y del panel de impacto animadas (fade + scale/slide corto, ~200 ms, `ease-out`), respetando `prefers-reduced-motion`. Sin animaciones gratuitas.
- **Estados cuidados:** loading del preview con skeleton o spinner discreto; estado vacío del buscador; la advertencia de unidades como "banner" suave, no una alerta agresiva.
- **Coherencia:** usa la paleta y tokens Tailwind ya presentes en la web; no reintroduce Material Design. Para la ejecución, aplicar los skills `emil-design-eng` y/o `frontend-design` al construir el componente.

El pulido visual es parte de la definición de done de la tarea de UI: el review de esa tarea debe evaluarlo, no solo la corrección funcional.

### 6. Tests

Feature tests (`tests/Feature/`), con factories:

- Reapunta `purchase_items` de los absorbidos al canónico.
- Normaliza `concept` al nombre canónico.
- Mueve el sufijo a `notes` cuando la nota estaba vacía; antepone con " · " cuando ya había nota; no pierde texto cuando el concept viejo no contenía el canónico.
- Soft-delete de los absorbidos; el canónico sobrevive intacto.
- Atomicidad: si un update falla, ningún cambio persiste.
- Solo empresa: 403 para admin-sucursal y cajero en ambas rutas.
- Cross-tenant: no se puede fusionar una ficha de otro tenant (ni como canónico ni como absorbido).
- No auto-fusión (canónico entre los absorbidos se ignora sin efecto).
- Preview devuelve conteos correctos y `unit_mismatch`.

### 7. Documentación

- Actualizar `docs/modulos/compras.md` (sección de productos de compra) con la función de fusión.
- Este spec: `Estado:` → "Implementado" al terminar, enlazando al doc vivo.

## Fuera de alcance (spec de prevención, posterior)

- Sugerir el producto existente más parecido al capturar (manual y IA).
- Mostrar el campo de nota por línea (`notes`) en `CompraFormModal` para que el número de la res se capture ahí desde el inicio.
- Reapuntar el pipeline de IA (`PurchaseContextBuilder`, `AiPurchaseProposalParser`) al catálogo de compra (`PurchaseProduct`) en vez del de venta (`Product`).
- Detección automática de grupos de duplicados por similitud de nombre.
- Normalización retroactiva del `concept` de las líneas que ya pertenecían al canónico.
- Fusión desde sucursal o hub.
