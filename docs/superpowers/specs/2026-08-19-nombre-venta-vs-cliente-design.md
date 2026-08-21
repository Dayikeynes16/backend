# Dos nombres en una venta: cuál se muestra

- **Estado:** **Implementado en la web (2026-08-21)** — doc viva: [ventas.md](../../modulos/ventas.md#cuando-la-venta-lleva-los-dos-nombres). La paridad en `carniceria-hub` queda pendiente (ver §6).
- **Fecha:** 2026-08-19
- **Repos afectados:** `carniceria-saas` (mesa de trabajo y detalle, cajero y sucursal) y `carniceria-hub` (lista de ventas y detalle)
- **Alcance:** solo presentación. No toca datos, ni la asignación de cliente, ni el dictado en la báscula. **Ningún cambio de backend**: los dos campos ya viajan a las dos superficies.

---

## 1. Problema

Una venta puede llevar dos nombres a la vez y hoy nadie dice cuál es cuál.

- **`contact_name`** — el nombre dictado en la báscula ("a nombre de Juan"). No es un cliente: es **la etiqueta con la que se encuentra la bolsa en el mostrador**.
- **`customer`** — con quién es la cuenta: crédito, fiado, precios preferenciales, historial.

En el hub conviven, pero compiten. `contact_name` es un badge violeta arriba con icono de persona (`SalesView.vue:479`) y `customer.name` una línea gris abajo **con el mismo icono y sin rótulo** (`SalesView.vue:494`). El comentario del código ya intuía el problema —"violeta, no azul: el azul ya significa cliente asignado y esto NO es un cliente"— pero la línea de abajo se añadió después y quedó con el icono repetido.

En la web es al revés: la tarjeta de la mesa de trabajo **no muestra el cliente en absoluto**, solo el dictado; el cliente aparece únicamente al abrir el detalle.

Y cuando los dos nombres son la misma persona —el caso más común: dictan "Juan" y luego se asigna el cliente "Juan Pérez"— el cajero ve el nombre dos veces.

### El caso que lo complica

Un cliente creado automáticamente desde una venta se llama **`"Cliente 55 1234 5678"`** con `name_pending = true` (`ResolveCustomerByPhone::placeholderName`). El frontend no trata ese campo en ninguna parte. Con una regla ingenua, esas ventas mostrarían `Cliente 55 1234 5678` **más** `A nombre de Juan`: dos líneas para decir que se llama Juan.

## 2. Decisión

**El cliente manda cuando tiene un nombre de verdad; el dictado sobrevive solo cuando aporta algo que el cliente no dice.**

| Cliente | Dictado | Se muestra |
|---|---|---|
| — | — | nada |
| — | Juan | 🏷 **A nombre de Juan** (violeta, como hoy) |
| Juan Pérez | — | 👤 **Juan Pérez** |
| Juan Pérez | Juan | 👤 **Juan Pérez** — el dictado se calla |
| Carnicería López | el de la gorra | 👤 **Carnicería López** · 🏷 **el de la gorra** |
| `name_pending` | Juan | 👤 **Juan** — el dictado ocupa el lugar del placeholder |
| `name_pending` | — | 👤 **Cliente 55 1234 5678** (como hoy) |

Dos reglas, entonces:

1. **Se colapsa la redundancia, no la información.** Si el dictado no añade nada al nombre del cliente, desaparece.
2. **Un placeholder no es un nombre.** Con `name_pending`, el nombre dictado es el mejor nombre disponible y ocupa su sitio — con el icono de cliente, porque cliente sí hay.

### Cuándo se consideran el mismo nombre

Normalizar ambos (recortar, minúsculas, sin diacríticos, espacios colapsados) y comparar **por palabras completas**: coinciden si la lista de palabras del más corto es prefijo de la del más largo.

- `Juan` ≡ `Juan Pérez` ✓ — el caso común, dictan el nombre de pila
- `juan perez` ≡ `Juan Pérez` ✓ — acentos y mayúsculas no cuentan
- `Ana` ≢ `Anabel Ruiz` ✗ — por palabras completas, no por prefijo de texto; si no, "Ana" se comería a Anabel
- `Pérez` ≢ `Juan Pérez` ✗ — el prefijo va desde el principio; dictar el apellido es una etiqueta distinta y se conserva

Ante la duda **se muestran los dos**: mostrar de más es un ruido, mostrar de menos es perder la referencia del paquete.

## 3. Detalles

- **El dictado nunca se borra del dato**, solo se calla en pantalla. A diferencia de `contact_phone`, que `AssignCustomerToSale` sí limpia al asignar cliente en ventas POS ("el cliente trae su propio teléfono"), aquí no hay dato redundante que limpiar: el nombre dictado es la referencia física del paquete y sigue en el detalle y en el ticket.
- **Iconos distintos.** El cliente lleva el icono de persona; el dictado, uno de etiqueta. Hoy comparten el mismo y es media causa de la confusión.
- **El rótulo "A nombre de" solo acompaña al dictado.** El cliente no necesita rótulo: su icono y su sitio ya lo dicen.

## 4. Lo que NO cambia

- **El banner de pedido web.** En ventas `origin === 'web'`, `contact_name` sí es el cliente que hizo el pedido, con su teléfono, y `Sucursal/SaleDetail.vue:449` lo rotula "Cliente:" con razón. Ese bloque se queda como está.
- **La báscula Android**, que también lista ventas: queda fuera a propósito para no exigir un release de APK por un cambio de presentación.
- Datos, endpoints, resources, permisos y el flujo de asignar cliente.

## 5. Archivos

Cinco pantallas, ninguna con cambios de backend.

| Repo | Archivo | Qué cambia |
|---|---|---|
| web | `resources/js/utils/saleNames.js` | **nuevo** — la regla, en un solo sitio |
| web | `Pages/Caja/Workbench.vue:142` | la tarjeta pasa a mostrar también el cliente |
| web | `Pages/Sucursal/Workbench.vue:185` | igual |
| web | `Components/Caja/SaleDetail.vue:252` | la cabecera aplica la regla |
| web | `Components/Sucursal/SaleDetail.vue` | igual (sin tocar el banner web) |
| hub | `src/renderer/lib/saleNames.js` | **nuevo** — la misma regla, junto a `saleItemDisplay.js` |
| hub | `views/SalesView.vue:479,494` | los dos nombres dejan de competir |
| hub | `components/SaleDetailModal.vue:624` | la cabecera aplica la regla |

Los datos ya están en las dos superficies: la mesa de trabajo entrega los modelos con `customer` eager-loaded (`Caja/WorkbenchController.php:51`) y el hub lo expone en `HubSaleResource:63`.

---

## 6. Al implementarlo (2026-08-21)

Dos cosas que este spec no había previsto.

**El bloque de cliente del detalle también aplica la regla.** El spec solo nombraba la cabecera, pero dejarlo ahí se contradecía a sí mismo: con `name_pending` la cabecera habría dicho "Juan" y el bloque de abajo "Cliente 55 1234 5678" al mismo tiempo. En las dos pantallas de detalle, el nombre del bloque de cliente y sus iniciales salen ahora del mismo `saleNames()`.

**El hub necesita un cambio de backend, al contrario de lo que decía §1.** `HubSaleResource` expone `customer` con `id`, `name` y `phone`, **sin `name_pending`**, así que el hub no puede distinguir un placeholder de un nombre real. La regla del hub queda incompleta hasta añadir ese campo al resource (y desplegarlo), o el caso `name_pending` se comportaría allí como un cliente con nombre de verdad. La web no tiene ese problema: sus dos controladores ya cargan `customer:id,name,name_pending,phone`.
