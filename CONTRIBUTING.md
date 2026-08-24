# Cómo se trabaja en este proyecto

Reglas de la casa. Las tres primeras son las que más cuesta descubrir solo.

## 1. La documentación se actualiza en el mismo cambio que el código

**Esto es parte de la definición de "terminado", no una tarea aparte.** Un cambio de funcionalidad sin su documentación actualizada está incompleto, aunque los tests pasen.

Al cerrar un cambio:

- **Actualiza el doc vivo del módulo** en `docs/modulos/` (o `docs/api/`, `docs/frontend/`). Si el módulo es nuevo, crea su doc y añádelo a `docs/README.md`.
- **Cambia el header `Estado:`** del spec que acabas de implementar: "Aprobado para implementación" → "Implementado (fecha)", enlazando al doc vivo. Nunca dejes "pendiente" sobre algo que ya está en producción.
- **Actualiza la tabla "Estado del sistema"** de `docs/README.md` si cambió la completitud de un módulo.
- **Actualiza `docs/arquitectura/ecosistema.md`** si el cambio toca el nivel del ecosistema: una superficie de API nueva, cómo se comunican dos aplicaciones, una regla de compatibilidad, o el mecanismo de publicación.

**No dupliques contenido entre documentos.** El doc vivo es la verdad sobre cómo funciona hoy; los specs y planes son historia congelada y de ellos solo cambia el header de estado.

## 2. La Scale API no admite cambios incompatibles

`/api/v1/*` (el contrato con las básculas) es intocable en el sentido fuerte: **hay equipos en producción que no se auto-actualizan.** Un cambio incompatible no falla en las pruebas, falla en el mostrador.

Prohibido: quitar o renombrar un campo de la respuesta, cambiar su tipo o formato, volver requerido un parámetro opcional, cambiar un código de estado, retirar un endpoint.

Todo cambio debe ser **aditivo**: campos nuevos siempre opcionales, con valor por defecto para quien no los mande. Si no puede ser aditivo, se versiona (`/api/v2/`) y se mantiene v1 viva. Regla completa: [docs/api/endpoints.md](docs/api/endpoints.md).

## 3. Los flujos de dinero pasan por sus servicios

Nunca escribas montos ni estados de pago a mano. Usa siempre el servicio correspondiente, dentro de una transacción:

`SalePaymentService` · `PurchasePaymentService` · `ShiftService` · `ExpenseWriter` · `PurchaseWriter` · `ProviderWriter` · `ExpenseCategoryWriter`

Los docs vivos y los tests documentan invariantes que estos servicios garantizan (por ejemplo: `amount_paid` solo se toca desde `PurchasePaymentService`). Léelos antes de cambiar un módulo existente.

## 4. Antes de tocar un módulo, léelo

Su doc vivo en `docs/modulos/` y el spec correspondiente documentan decisiones que parecen arbitrarias y no lo son. Cambiarlas sin leerlas rompe tests que existen precisamente por eso.

## Flujo de trabajo

1. **Rama desde `main`**, nombrada por tipo: `feat/`, `fix/`, `refactor/`, `perf/`, `docs/`, `test/`.
2. **Commits en español**, con formato convencional y ámbito. El mensaje describe el efecto, no la mecánica:
   ```
   feat(ventas): el cliente y el nombre dictado dejan de competir en la venta
   fix(hub-api): el módulo de clientes también cierra sus lecturas
   perf(agenda): la campana carga al montar y deja de sondear cada 60s
   docs(spec): del pago a su venta
   ```
3. **Formato y pruebas** antes de abrir el PR:
   ```bash
   ./vendor/bin/sail bin pint
   ./vendor/bin/sail composer run test
   ```
4. **Pull request a `main`.** El CI corre la suite en cada push y cada PR.

> **Ojo con el árbol de trabajo compartido.** A veces hay varias sesiones trabajando sobre los mismos repos. Antes de confirmar, revisa `git status` y confirma solo lo tuyo: nada de `git add -A` ni `--amend` a ciegas.

## Convenciones de código

- **Idioma:** el dominio, la interfaz y la documentación van en **español** (ventas, sucursales, turnos, cobros). Los identificadores de código en **inglés**, salvo cuando calcan un nombre de ruta o columna en español (`categorias`, `clientes`, `turno`).
- **Todo dentro de Sail.** PHP, Artisan, Composer y Node se ejecutan con `./vendor/bin/sail` delante.
- **PHP:** llaves siempre, promoción de propiedades en el constructor, tipos de retorno y de parámetros explícitos, `TitleCase` en claves de enum. PSR-12 vía Pint.
- **Vue:** Composition API con `<script setup>`, un solo elemento raíz por componente. Antes de crear un componente, busca si ya existe uno que sirva.
- **Artisan para generar archivos:** `sail artisan make:...` con `--no-interaction`, no los escribas a mano.
- **Modelos nuevos:** casi todos llevan `BelongsToTenant`. Crea su factory y su seeder.

## Pruebas

Todo cambio se prueba de forma automática: test nuevo, o test existente actualizado.

```bash
./vendor/bin/sail composer run test                              # toda la suite
./vendor/bin/sail artisan test --compact --filter=NombreDelTest  # lo justo, mientras trabajas
```

La mayoría deben ser feature tests. Usa las factories y sus estados antes de montar datos a mano. **No borres tests** sin acordarlo antes.

## Reglas del asistente IA

Si tocas `app/Services/Ai/`, estas son inviolables:

- Ninguna herramienta puede confirmar un borrador por su cuenta; toda escritura pasa por `AssistantDraftController@confirm`, que revalida todo.
- La IA nunca decide autorización ni genera cifras finales — la interfaz las renderiza desde el JSON de la herramienta.
- `branch_id` se fuerza en el servidor para admin-sucursal.
- Las herramientas nuevas se registran en `AppServiceProvider` y declaran `jsonSchema()` con `additionalProperties: false`.

Detalle en [docs/modulos/asistente-ia.md](docs/modulos/asistente-ia.md).

## Los otros repositorios

Este es uno de cuatro. Un cambio aquí puede romper `carniceria-hub`, `bascula` o `bascula-android`, sobre todo si toca una superficie de API o un evento de broadcast. El mapa de qué consume qué está en [docs/arquitectura/ecosistema.md](docs/arquitectura/ecosistema.md).

---

¿Primera vez? Empieza por [docs/guias/entorno-local.md](docs/guias/entorno-local.md).
