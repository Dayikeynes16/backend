# "Nuevo cliente" en la barra de herramientas — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que "Nuevo cliente" viva junto a buscar y filtrar, y que la cabecera de Clientes quede solo con el título y el contador.

**Architecture:** Tres ediciones de maquetación, una por pantalla. No se toca lógica, ni props, ni permisos: el mismo `@click` cambia de sitio dentro del template.

**Tech Stack:** Vue 3 + Tailwind (Inertia en la web, renderer del hub).

**Spec:** `docs/superpowers/specs/2026-08-15-boton-nuevo-cliente-toolbar-design.md`

## Global Constraints

- **Cero cambios de lógica.** No se tocan `<script setup>`, props, rutas ni permisos. Solo `<template>`.
- **El texto es "Nuevo cliente" completo**, nunca "Nuevo" a secas.
- **El botón conserva el rojo sólido** en la web (`bg-red-600`) y la variante primaria en el hub.
- **El "Crear el primero" del estado vacío no se toca** en ninguna de las tres.
- **Las tres pantallas quedan igual entre sí.** El riesgo de este trabajo es que una se quede a medias.
- Sin cobertura automática de maquetación: las suites solo confirman que no se rompió nada.

---

### Task 1: Web — pantalla de admin-sucursal

**Files:**
- Modify: `resources/js/Pages/Sucursal/Clientes/Index.vue` (cabecera `:106-118`, toolbar `:146-179`)

- [x] **Step 1: Saca el botón de la cabecera y deja el contador**

Reemplaza el bloque `<template #header>` completo por:

```vue
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-gray-900">Clientes</h1>
                    <p class="mt-0.5 text-xs text-gray-500">Cartera de clientes registrados en esta sucursal.</p>
                </div>
                <!-- La cabecera dice dónde estás y cuánto hay; lo que *hace algo*
                     vive en la barra de herramientas, junto a buscar y filtrar. -->
                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">{{ totalLabel }}</span>
            </div>
        </template>
```

- [x] **Step 2: Pon el botón al final de la fila de filtros**

En la toolbar, sustituye la pastilla del contador —que acabas de subir a la cabecera— por el botón. Es la línea `<span class="rounded-full bg-gray-100 …">{{ totalLabel }}</span>` que hay **dentro** de `<div class="flex flex-wrap items-center gap-2">`, justo después del `<select v-model="sort">`:

```vue
                        <button type="button" @click="showCreate = true"
                            class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 active:scale-[.98]">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            Nuevo cliente
                        </button>
```

El `py-2` en vez de `py-2.5` lo alinea con los demás controles de esa fila, que son más bajos que el botón de cabecera.

- [x] **Step 3: Verifica que compila**

Run: `npm run build`
Expected: build sin errores.

- [x] **Step 4: Commit**

```bash
git add resources/js/Pages/Sucursal/Clientes/Index.vue
git commit -m "refactor(clientes): Nuevo cliente baja a la barra de herramientas (sucursal)"
```

---

### Task 2: Web — pantalla del cajero

**Files:**
- Modify: `resources/js/Pages/Caja/Clientes/Index.vue` (cabecera `:99-111`, toolbar)

> Casi gemela de la anterior; la única diferencia real es el subtítulo. Se repite el código en vez de remitir a la Task 1 porque son archivos distintos y conviene no confundirlos.

- [x] **Step 1: Saca el botón de la cabecera y deja el contador**

Reemplaza el bloque `<template #header>` completo por:

```vue
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-gray-900">Clientes</h1>
                    <p class="mt-0.5 text-xs text-gray-500">Consulta la cartera y registra cobros de cuentas pendientes.</p>
                </div>
                <!-- La cabecera dice dónde estás y cuánto hay; lo que *hace algo*
                     vive en la barra de herramientas, junto a buscar y filtrar. -->
                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">{{ totalLabel }}</span>
            </div>
        </template>
```

Ojo: **el subtítulo es distinto al de la pantalla de admin**. No lo copies de la Task 1.

- [x] **Step 2: Pon el botón al final de la fila de filtros**

Igual que en la otra pantalla: sustituye la pastilla `<span class="rounded-full bg-gray-100 …">{{ totalLabel }}</span>` de dentro de `<div class="flex flex-wrap items-center gap-2">` por:

```vue
                        <button type="button" @click="showCreate = true"
                            class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 active:scale-[.98]">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            Nuevo cliente
                        </button>
```

- [x] **Step 3: Verifica que compila y que la suite sigue verde**

Run: `npm run build && ./vendor/bin/sail artisan test --compact --filter="Cliente|Customer"`
Expected: build sin errores y tests verdes. No debería cambiar ninguno: esto es maquetación.

- [x] **Step 4: Commit**

```bash
git add resources/js/Pages/Caja/Clientes/Index.vue
git commit -m "refactor(clientes): Nuevo cliente baja a la barra de herramientas (caja)"
```

---

### Task 3: Hub

**Files:**
- Modify: `carniceria-hub/src/renderer/views/CustomersView.vue` (cabecera `:143-149`, filtros `:162-192`)

> El hub lista en tarjetas, no en tabla, pero tiene la misma fila de filtros. Y **no tiene contador en la cabecera**: el total aparece dentro del botón "Cargar más ({{ meta.total }} en total)". Se le añade uno, para que las tres pantallas digan lo mismo.

- [x] **Step 1: Cambia el botón de la cabecera por el contador**

Reemplaza el bloque de cabecera:

```vue
    <div class="mb-4 flex items-start justify-between gap-3">
      <div>
        <h2 class="ui-page-title mb-0">Clientes</h2>
        <p class="ui-page-subtitle">Cartera de clientes registrados en esta sucursal.</p>
      </div>
      <!-- La cabecera dice dónde estás y cuánto hay; lo que *hace algo* vive en
           la fila de filtros, junto a buscar y ordenar. -->
      <span
        v-if="meta.total"
        class="shrink-0 rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600"
      >{{ meta.total }} clientes</span>
    </div>
```

- [x] **Step 2: Pon el botón al final de la fila de filtros**

En el `<div class="flex flex-wrap items-end gap-3">` de la tarjeta de filtros, después del `<label>` de "Con deuda", añade:

```vue
        <UiButton icon="plus" class="ml-auto" @click="openCreate">Nuevo cliente</UiButton>
```

El `ml-auto` lo empuja al extremo derecho de la fila; `items-end` en el contenedor ya lo alinea con los selects, que llevan etiqueta encima.

- [x] **Step 3: Verifica que compila y que la suite sigue verde**

Run (desde `carniceria-hub/`): `npm run build:renderer && npx vitest run`
Expected: build sin errores y la suite del hub verde (415 tests al implementarlo).

- [x] **Step 4: Commit**

```bash
git add src/renderer/views/CustomersView.vue
git commit -m "refactor(hub): Nuevo cliente baja a la fila de filtros"
```

---

### Task 4: Documentación

**Files:**
- Modify: `docs/superpowers/specs/2026-08-15-boton-nuevo-cliente-toolbar-design.md:3`

- [x] **Step 1: Marca el spec como implementado**

```markdown
- **Estado:** Implementado (2026-08-15) · plan en `docs/superpowers/plans/2026-08-15-boton-nuevo-cliente-toolbar.md`
```

- [x] **Step 2: Commit**

```bash
git add docs/
git commit -m "docs: boton Nuevo cliente en la toolbar implementado"
```

---

## Verificación manual

Lo único que prueba de verdad este cambio:

1. **Las tres pantallas** —Clientes de sucursal, Clientes de caja y Clientes del hub— con el botón en la fila de filtros y el contador arriba.
2. **Una ventana estrecha** en cada una: la fila debe **envolverse**, no recortar el texto del botón.
3. **Sin clientes**: el estado vacío debe seguir mostrando "Crear el primero" en el centro.
