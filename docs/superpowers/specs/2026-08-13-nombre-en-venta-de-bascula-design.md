# Nombre en la venta desde la báscula, con dictado por voz

**Estado:** Implementado (2026-08-13) — doc viva: [ventas.md](../../modulos/ventas.md#nombre-de-la-venta-desde-la-báscula)
**Fecha:** 2026-08-13
**Módulos afectados:** [ventas](../../modulos/ventas.md), [api de básculas](../../api/endpoints.md), `bascula-android`
**No afecta a:** `carniceria-hub` (cero cambios), [clientes-telefonos](../../modulos/clientes-telefonos.md) (la cartera no se toca)

## Problema

El mostrador despacha varias ventas a la vez y la cola de la Mesa de Trabajo no
las distingue: todas dicen `S-01042 · Báscula 2 · $480`. Quien pesa sabe de quién
es cada una, pero esa información se pierde entre la báscula y la caja, y el
cajero acaba preguntando en voz alta de quién es cada bolsa.

La báscula es una tablet Android sin teclado cómodo y con las manos ocupadas:
escribir el nombre es fricción real justo en el momento de más prisa.

## Decisión

Un campo **"A nombre de"** en la pantalla de venta de la báscula, con un botón de
micrófono que dicta el nombre y lo transcribe con Whisper. El nombre viaja en el
campo `sales.contact_name` que ya existe, y se muestra junto al folio en la cola.

**Es una etiqueta, no un cliente.** No crea ni asocia registros en `customers`, no
aplica precios preferenciales y `customer_id` sigue en `null`.

## Por qué una etiqueta y no un cliente

La identidad de un cliente en este sistema es **el teléfono**: es único por
sucursal, se normaliza a E.164 y resuelve o crea el cliente
(ver [clientes-telefonos](../../modulos/clientes-telefonos.md)).

Un nombre dictado no tiene esa propiedad. "Juan" son cinco personas, y un dictado
imperfecto ("Mari" por "Mary") asociaría la venta a la persona equivocada,
arrastrando con ella precios preferenciales y deuda. Meter nombres en la cartera
desharía justo lo que se acaba de ordenar.

Si una venta necesita cliente de verdad, el flujo existente sigue disponible: el
cajero captura el teléfono en la Mesa de Trabajo y el sistema resuelve o crea el
cliente como hoy.

## Decisiones

| Decisión | Razón |
|---|---|
| El campo reusa **`sales.contact_name`**, no una columna nueva | Ya existe (string 255, nullable), lo usan los pedidos web con la misma semántica —el nombre de contacto de la venta— y `Sucursal/SaleDetail.vue` ya lo pinta. Una columna nueva duplicaría el concepto |
| Se añade como campo **`nullable`** a la API de básculas | Cambio aditivo: las básculas viejas no lo mandan, la validación no lo exige y su comportamiento no cambia. Es el requisito duro de este diseño |
| **El audio va siempre a la nube**, aunque la venta vaya al hub | La báscula guarda las credenciales de la nube incluso emparejada al hub — las necesita para `CLOUD_FALLBACK` (`ServerSelector.kt`). Así el hub queda en cero cambios, sin release ni instalación en las Surfaces, y el dictado sobrevive a que el hub se caiga |
| Endpoint de transcripción **propio de la Scale API** | El del asistente (`asistente/transcribir`) exige sesión web; la báscula se autentica con `X-Api-Key`. El servicio `AssistantTranscriber` se reusa sin tocarlo |
| El campo es **de texto con el micrófono como atajo** | Sin nube el dictado no puede funcionar, pero la venta nunca debe bloquearse por el nombre. Se escribe con el teclado de la tablet |
| El texto transcrito **se muestra editable**, no se aplica a ciegas | Whisper devuelve lo que oye: puede incluir muletillas o "a nombre de doña Mary" completo. El operador lo corrige antes de cobrar |
| Límite **por API key**, sin tocar `ai_monthly_budget_cents` | El presupuesto mensual de IA es para el asistente y la captura de gastos. Contener el abuso no exige meter las básculas en esa contabilidad, y agotar el presupuesto no debe apagar el micrófono en pleno mostrador |

## Alcance

### Backend (`carniceria-saas`)

**1. `Api/SaleController::store`** — una línea en la validación y una en el `create`:

```php
'contact_name' => 'nullable|string|max:255',
```

**2. Endpoint de transcripción** en la Scale API, bajo `auth.apikey`:

```
POST /api/v1/transcribe
  multipart/form-data: audio
  → 200 { "text": "Doña Mary" }
  → 429 si excede el límite por hora
  → 422 formato o tamaño inválido
```

Reusa `AssistantTranscriber` (Whisper, idioma `es`, no persiste el audio) y los
límites de tamaño ya configurados (`ai.expenses.max_audio_bytes`, 10 MB).
`RateLimiter` por API key: 120 dictados/hora.

**3. Frontend web** — `contact_name` junto al folio en la tarjeta de la cola de
Caja y Sucursal, y la línea en el detalle de Caja (Sucursal ya la tiene). Solo
vista: la venta ya viaja con el campo.

### Báscula (`bascula-android`)

- `CreateSaleRequest` gana `contact_name`.
- Campo de texto "A nombre de" con botón de micrófono en la pantalla de venta.
- Grabar → `POST` multipart a `config.cloud` → el texto cae en el campo, editable.
- El micrófono se muestra deshabilitado si no hay credenciales de nube o no responde.

### Fuera de alcance

- **El hub**: ni una línea.
- **Clientes**: `customer_id` sigue en `null`; nada toca `customers`.
- **El ticket impreso**: no muestra el nombre (decidido; puede añadirse después).
- **Básculas viejas**: no mandan el campo y no se enteran del cambio.

## Flujo

```
1. El operador pesa y arma la venta como hoy.
2. Toca el micrófono y dicta: "Doña Mary".
3. La báscula manda el audio a la nube (X-Api-Key).
4. Whisper devuelve el texto; cae en el campo, editable.
5. Al cobrar, la venta viaja con contact_name — al hub o a la nube, según el modo.
6. El hub reenvía el payload completo sin cambios (`backendClient.js`).
7. La cola de la Mesa de Trabajo muestra "S-01042 · 👤 Doña Mary".
```

Si el paso 3 falla, el paso 4 no ocurre y el operador escribe el nombre a mano.
Los pasos 5-7 son idénticos con o sin dictado.

## Errores y degradación

| Situación | Comportamiento |
|---|---|
| Sin internet en la báscula | Micrófono deshabilitado; el campo se escribe a mano |
| Whisper falla o tarda | Mensaje breve en la báscula; el campo queda vacío y editable |
| Excede 120 dictados/hora | 429; el micrófono avisa y se puede escribir |
| El operador no pone nombre | La venta sube con `contact_name = null`, como hoy |
| Báscula vieja | No manda el campo; nada cambia |

## Riesgos

| Riesgo | Mitigación |
|---|---|
| La transcripción incluye ruido ("a nombre de doña Mary") | El campo es editable y el operador lo ve antes de cobrar. Si resulta molesto en uso real, se puede limpiar el prefijo con una regla simple antes de plantear GPT, que costaría por dictado |
| Gasto de Whisper sin control | Límite por API key y tope de tamaño. 120 dictados/hora de 30 s ≈ $0.36/hora en el peor caso |
| La API key vive en una tablet del mostrador | Ya es así para vender; el endpoint nuevo no amplía la superficie más allá de transcribir audio |
| El nombre se confunde con un cliente real | Se muestra con un icono distinto al del cliente asignado, y no aparece en la cartera ni en las métricas de clientes |

## Tests

- `Api/SaleController` acepta `contact_name` y lo persiste; **una venta sin el campo se crea igual que antes** (garantía para las básculas viejas).
- El endpoint de transcripción: 200 con audio válido, 401 sin API key, 422 con formato inválido, 429 al exceder el límite.
- El límite se cuenta **por API key**, no global: dos básculas de la misma sucursal no se consumen la cuota entre sí.
- Frontend: la tarjeta muestra el nombre cuando existe y no rompe cuando es `null`.

## Preguntas abiertas

- ¿Conviene que el nombre viaje también al ticket impreso? Se dejó fuera de esta
  iteración y **sigue abierto**. Si el uso real resulta ser "apartados que se
  recogen después", el ticket pasa a tener sentido y sería un cambio sobre
  `TicketPrinter.vue` y la configuración de ticket por sucursal.
