# Adjuntos unificados: visor + componente compartido de subida (comprobantes de pago, Gastos, Compras)

**Fecha:** 2026-07-17
**Estado:** Aprobado — pendiente de plan
**Alcance:** frontend (Vue) + rutas backend nuevas de solo-lectura (preview inline) + una corrección de autorización en 2 controladores existentes. Toca `PaymentReceiptsPanel.vue` (comprobantes de pago), `GastoFormModal.vue` (Gastos), `CompraFormModal.vue` (Compras), `CompraDetailModal.vue` y `GastoDetailModal.vue` (swap/arreglo de visor), y las páginas `Pages/Caja/Compras/Index.vue` / `Pages/Caja/Gastos/Index.vue` (corrección de rutas de adjuntos — ver "Caja: el visor debe funcionar ahí también", hallazgo en vivo del usuario + bugs preexistentes encontrados al investigarlo). Fuera de alcance: los flujos de "captura con IA" (`GastoCapturaIAModal.vue`, `CompraCapturaIAModal.vue` — ya son consistentes entre sí y no se tocan), fotos de producto (`Productos/Create.vue`/`Edit.vue`), logo de marca (`Empresa/Personalizacion.vue`), adjuntos del chat del asistente (`ChatInputBar.vue`). El hub (Electron) queda fuera — es un proyecto aparte con su propia paridad visual pendiente.

## Problema

Este mismo feature de "comprobantes de pago en transferencias" (specs `2026-07-15-comprobantes-transferencia-design.md`) shippeó con un panel de ver/gestionar comprobantes (`PaymentReceiptsPanel.vue`) funcional pero pobre: lista de archivos con solo nombre+tamaño (sin vista previa de la imagen/PDF) y un botón "Agregar comprobante" chico en una caja punteada, poco intuitivo para un usuario no técnico.

Al revisar el resto de la app se encontraron **tres patrones visuales distintos** para "adjuntar un archivo", sin ninguna razón de negocio que los diferencie:

| Lugar | Patrón actual |
|---|---|
| `Gastos/GastoFormModal.vue` | 2 botones grandes ("📷 Tomar foto" / "📎 Adjuntar archivo") + grid de miniaturas (fotos reales) + visor de pantalla completa (`AttachmentViewerModal.vue`) |
| `Compras/CompraFormModal.vue` | Un `<input type="file">` nativo estilizado con clases `file:` de Tailwind. Sin cámara, sin miniaturas, sin visor |
| `PaymentReceiptsPanel.vue` (nuevo) | Lista de texto (nombre + tamaño) + caja punteada chica "Agregar comprobante". Sin miniaturas, sin visor |

El usuario pidió explícitamente unificar: un solo componente, mismo comportamiento en toda la app, partiendo del patrón de Gastos (el más maduro) y mejorándolo.

## Decisiones (aprobadas por el usuario, sesión de brainstorming con visual companion)

| Decisión | Valor |
|---|---|
| Punto de entrada del clip 📎 en las listas de pagos (Pagos, Historial, Mesa de trabajo, ficha de cliente) | **Sin cambios.** Se queda el badge chico "📎 N" tal cual — el usuario lo aprobó explícitamente y pidió enfocar el trabajo en lo que hay *dentro* del panel |
| Alcance de la unificación | 4 consumidores del visor/picker: `GastoFormModal.vue` (refactor), `CompraFormModal.vue` (se pone al día), `PaymentReceiptsPanel.vue` (ya construido, se adapta al componente compartido), y `CompraDetailModal.vue` (swap de visor, ver fila siguiente) |
| `CompraDetailModal.vue` tiene su propio visor duplicado | Hallazgo del review de esta spec: el modal "Ver compra" ya muestra los adjuntos con su propio lightbox hecho a mano (`viewer`/`openViewer`/`closeViewer`, líneas ~276-288), **no** usa `AttachmentViewerModal.vue` — a diferencia de `GastoDetailModal.vue`, que sí lo usa (línea 3 y 163). Sin corregir esto, Compras se quedaría con dos UIs de adjuntos distintas después del cambio, contradiciendo el objetivo de unificar. Se incluye en el alcance: reemplazar el lightbox inline de `CompraDetailModal.vue` por el `AttachmentViewerModal.vue` compartido, igual que ya hace `GastoDetailModal.vue` |
| Flujos de "captura con IA" (Gastos/Compras) | Fuera de alcance — ya comparten `CameraCaptureModal.vue` entre sí y tienen un propósito distinto (extracción por IA, no solo adjuntar) |
| Componente nuevo | `resources/js/Components/AttachmentsPicker.vue` — reemplaza la UI de adjuntos en los 3 lugares. Presentacional/"tonto": no hace peticiones de red, no decide permisos, no conoce rutas — el padre sigue siendo dueño de esa lógica (mismo principio ya usado en `ConfirmDialog.vue` y en el flujo draft+confirm del asistente) |
| Modos de operación | `mode="staged"` (Gastos, Compras: los archivos nuevos se acumulan localmente y se envían junto con el resto del formulario al guardar) y `mode="immediate"` (Comprobantes de pago: cada selección/soltado sube al instante, sin paso de "guardar" alrededor) |
| Piezas reutilizadas sin cambios | `Components/CameraCaptureModal.vue` (cámara nativa en móvil / webcam en escritorio) y `utils/device.js#isMobileDevice` — ya son genéricos, cero cambios |
| Visor | `Components/Gastos/AttachmentViewerModal.vue` se **mueve** a `Components/AttachmentViewerModal.vue` (ya era 100% genérico por props: `attachments`, `previewUrl`, `downloadUrl`) y pasa a ser compartido por los 4 consumidores. Al mover el archivo hay que actualizar **ambos** import sites existentes: `GastoFormModal.vue` (se toca de todos modos por el refactor) y `GastoDetailModal.vue` (no se refactoriza su lógica, solo se le actualiza la ruta del import) |
| Mejoras aprobadas sobre el patrón actual de Gastos | (1) Arrastrar y soltar (drag & drop) en la zona "Adjuntar archivo", además de clic. (2) Estado "subiendo…" visible por miniatura (spinner + error inline) — necesario para `mode="immediate"`. (3) Mensaje de estado vacío cuando aún no hay archivos, en vez de solo cuadros punteados sin texto |
| Backend nuevo | Los comprobantes de pago solo tienen ruta de "descargar" (`Content-Disposition: attachment`), que fuerza guardar el archivo en vez de mostrarlo. Para el visor hace falta una ruta de "vista previa" (`inline`), espejo exacto de `ExpenseAttachmentController@preview` |
| Backend de Gastos/Compras (Empresa/Sucursal) | Sin cambios — ya tienen su endpoint de preview |
| Backend de Gastos/Compras (Caja) | **Cambia** — ver sección "Caja: el visor debe funcionar ahí también" más abajo: 6 rutas nuevas + corrección de autorización en `ExpenseAttachmentController`/`PurchaseAttachmentController` |

## Componente `AttachmentsPicker.vue`

Vive en `resources/js/Components/` (no en una subcarpeta de feature, porque ahora es genérico).

**Responsabilidad:** mostrar los adjuntos ya subidos como miniaturas (foto real si es imagen, ícono si es PDF/otro), abrir el visor al tocar una miniatura, ofrecer "Tomar foto"/"Adjuntar archivo" (clic o arrastrar), validar tipo/tamaño/cantidad en el cliente, y comunicar selecciones/borrados al padre por eventos. **No decide permisos, no arma URLs de rutas del backend, no hace `fetch`/`router.post` — eso lo resuelve cada padre**, igual que hoy.

Props (borrador, se cierra en el plan de implementación):

```
mode: 'staged' | 'immediate'        (requerido)
attachments: Array                   (ya subidos: {id, original_name, mime_type, size_bytes})
maxCount: Number                     (default 3; Gastos usa 5, Compras usa 5)
allowedMimes: Array                  (default: jpg/jpeg/png/webp/pdf)
maxBytes: Number                     (default 5 MB)
previewUrl: Function(attachment)     (URL de vista previa inline, para miniaturas y visor)
downloadUrl: Function(attachment)    (URL de descarga forzada)
canManage: Boolean                   (default true; oculta agregar/eliminar si false)
uploading: Boolean                   (solo mode="immediate": el padre indica que hay una subida en curso)
uploadError: String                  (solo mode="immediate": mensaje de error a mostrar)
```

Emits:

```
update:newFiles   (mode="staged": el padre junta el File[] final para enviarlo con el resto del formulario)
remove-existing    (attachment) — el padre decide cómo borrar (llamada ya existente, sin cambios de comportamiento)
files-selected     (File[]) — mode="immediate": el padre dispara la subida real (router.post, como ya hace PaymentReceiptsPanel hoy)
delete             (attachment) — mode="immediate": el padre dispara el borrado real (router.delete, como ya existe)
```

El componente valida localmente (tipo, tamaño, cupo restante) antes de emitir — mismos mensajes de error que ya existen en `GastoFormModal.vue` hoy (reutilizados, no reinventados).

## Cambios por consumidor

- **`PaymentReceiptsPanel.vue`**: pasa a usar `AttachmentsPicker` con `mode="immediate"`. La lógica de `router.post`/`router.delete`, manejo de `onError`, y el comentario sobre el fallback al modal de error de Inertia en 403 **no cambian** — solo cambia qué renderiza (miniaturas + visor en vez de lista de texto).
- **`GastoFormModal.vue`**: refactor para consumir `AttachmentsPicker` con `mode="staged"` en vez de su implementación inline actual (~150 líneas de grid/queue/preview se remueven del archivo y se delegan al componente). Sin cambios de comportamiento visible salvo las 3 mejoras (drag&drop, estado vacío; "subiendo" no aplica en modo staged).
- **`CompraFormModal.vue`**: reemplaza el `<input type="file">` nativo por `AttachmentsPicker` con `mode="staged"` — gana cámara, miniaturas y visor por primera vez. Backend de Compras ya tiene su endpoint de preview: `PurchaseAttachmentController@preview`, rutas `empresa.compras.adjuntos.preview` y `sucursal.compras.adjuntos.preview` (mismo patrón exacto que `ExpenseAttachmentController@preview`). Nota para el plan: hoy `CompraFormModal.vue` solo recibe `routes: {store, update}` como prop y **no muestra los adjuntos ya existentes en modo edición** — a diferencia de `GastoFormModal.vue`, que sí recibe `attachmentPreviewRouteName`/`attachmentDownloadRouteName`/`attachmentDestroyRouteName` y renderiza `existingAttachments`. Hay que agregarle esas mismas props (u equivalentes) a `CompraFormModal.vue`, **incluida su variante Caja** (ver sección siguiente).
- **`CompraDetailModal.vue`**: reemplaza su lightbox inline (`viewer`/`openViewer`/`closeViewer` + el `<div v-if="viewer">` de las líneas ~276-288) por `AttachmentViewerModal` (compartido), igual que ya hace `GastoDetailModal.vue`. Es un swap de visor únicamente — no toca su lista de miniaturas existente ni el botón eliminar, que ya funcionan.

## Caja: el visor debe funcionar ahí también (corrección en vivo del usuario)

Decisión inicial de esta spec ("Caja queda fuera") **revertida por el usuario**: Caja también debe poder usar el visor de adjuntos en Gastos y Compras. Al investigar por qué no funcionaba hoy se encontraron **dos bugs preexistentes** (no introducidos por este cambio, pero hay que arreglarlos para cumplir el pedido):

1. **`Pages/Caja/Compras/Index.vue`**: el objeto `cajaCompraRoutes` que se le pasa a `CompraDetailModal` como prop `routes` solo trae `{ cancel, pagoStore, pagoDestroy }` — **le faltan** `adjuntoDownload`, `adjuntoPreview`, `adjuntoDestroy`. Hoy, si un cajero intenta ver un adjunto de una compra, `route(undefined, ...)` truena.
2. **`Pages/Caja/Gastos/Index.vue`**: pasa a `GastoDetailModal` `preview-route-name="caja.gastos.index"`, `download-route-name="caja.gastos.index"` y a `GastoFormModal` `attachment-destroy-route-name="caja.gastos.store"` — **nombres de ruta equivocados** (apuntan al listado y al alta de gastos, no a rutas de adjuntos). Parece un copy-paste sin las rutas reales, porque **no existen** rutas `caja.gastos.adjuntos.*` todavía.

### Backend nuevo: rutas de adjuntos para Caja (Gastos y Compras)

Se agregan 6 rutas nuevas, reutilizando los controladores existentes (`ExpenseAttachmentController`, `PurchaseAttachmentController`) sin crear clases nuevas:
- `caja.gastos.adjuntos.download`, `caja.gastos.adjuntos.preview`, `caja.gastos.adjuntos.destroy`
- `caja.compras.adjuntos.download`, `caja.compras.adjuntos.preview`, `caja.compras.adjuntos.destroy`

**Autorización — hallazgo de seguridad al diseñar esto:** hoy `ExpenseAttachmentController::authorizeAccess()` y `PurchaseAttachmentController::authorizeAccess()` solo acotan por sucursal cuando el usuario es `admin-sucursal`; un `cajero` pasaría el chequeo sin ninguna restricción de sucursal ni de dueño (el gap no era explotable porque no existían rutas `caja.*` — al agregarlas, se vuelve explotable si no se corrige). Se sigue el mismo patrón que ya usa `PaymentReceiptController` (`authorizeView`/`authorizeMutation` separados) y se acota con las reglas **ya existentes** en los índices de Caja:
- **Ver/descargar/previsualizar** (`authorizeView`, usado por `download()`/`preview()`): cajero limitado a **su propia sucursal** (igual que admin-sucursal hoy) **y**, para Gastos, además a **sus propios gastos** (`expense.user_id === $user->id`) — porque `Caja\GastoController@index` ya filtra así (`branch_id` + `user_id`); para Compras, solo sucursal (sin filtro por `user_id`), porque `Caja\PurchaseController@index` ya muestra todas las compras de la sucursal a cualquier cajero.
- **Eliminar** (`authorizeMutation`, usado por `destroy()`): además de lo anterior, exige que el registro esté ligado al turno abierto del cajero — mismo campo que ya calcula `can_manage` en los índices: `expense.cash_register_shift_id === $turnoAbierto->id` (Gastos) / `purchase.cash_register_shift_id === $turnoAbierto->id` (Compras). Admin-sucursal/superadmin no cambian (acceso total a su sucursal / cualquiera).

### Frontend: corregir el wiring de Caja

- `Pages/Caja/Compras/Index.vue`: agregar `adjuntoDownload: 'caja.compras.adjuntos.download'`, `adjuntoPreview: 'caja.compras.adjuntos.preview'`, `adjuntoDestroy: 'caja.compras.adjuntos.destroy'` a `cajaCompraRoutes`.
- `Pages/Caja/Gastos/Index.vue`: corregir `preview-route-name`/`download-route-name` en `GastoDetailModal` y `attachment-destroy-route-name` en `GastoFormModal` a las 3 rutas nuevas de arriba (hoy apuntan, por error, a rutas de listado/alta).
- `CompraFormModal.vue` en modo Caja gana las mismas props de adjuntos que su versión Empresa/Sucursal (ver punto anterior) — mismo componente, ya no hay diferencia por rol salvo qué rutas se le pasan.

## Backend: endpoints de vista previa para comprobantes de pago

Espejo exacto de `ExpenseAttachmentController@preview` (mismo patrón de headers: `Content-Disposition: inline`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, max-age=300`, lee el archivo con `Storage::disk(...)->get()` y lo devuelve con `response()`).

Nuevo método `preview()` en:
- `Sucursal\PaymentReceiptController` (pagos de venta)
- `Sucursal\CustomerPaymentReceiptController` (cobros globales)

Mismo control de acceso que `download()` (llama al `authorizeView()` privado ya existente — solo lectura, sin regla de turno/mutación).

Rutas nuevas (4), mismo prefijo/agrupación que las de `download`/`destroy` ya existentes:
- `sucursal.pagos.receipts.preview`, `caja.pagos.receipts.preview`
- `sucursal.cobros.receipts.preview`, `caja.cobros.receipts.preview` (caja también puede previsualizar, igual que ya puede descargar)

## Riesgo de la refactorización de Gastos/Compras

`GastoFormModal.vue` y `CompraFormModal.vue` son formularios ya en producción. El refactor:
- No toca las rutas ni la validación backend de adjuntos de Gastos/Compras (mismos endpoints `store`/`destroy`/`preview`/`download` de siempre).
- Solo reemplaza la capa de presentación de "elegir/ver archivos" por el componente compartido.
- Mitigación: el submit final sigue mandando el mismo shape de datos (`File[]` en `form.attachments`/`form.<campo>`) — el componente no cambia el contrato con el backend, solo cómo se juntan los archivos en el cliente.
- No hay test runner de JS en el proyecto (frontend se verifica por build + click-through manual, igual que en T8/T9 de este mismo feature) — se hará una pasada manual de "crear gasto con foto", "crear compra con PDF", "editar gasto existente y borrar un adjunto", "editar compra existente y ver adjuntos" y "abrir 'Ver compra' y usar el visor" tras el refactor.

## Pruebas

- Backend: 2 tests nuevos (uno por controlador) para `preview()` en comprobantes de pago — status 200, `Content-Disposition: inline`, y reutilización de los escenarios de autorización ya cubiertos por `download()` en `PaymentReceiptTest.php` / `CustomerPaymentReceiptTest.php`.
- Backend: tests nuevos para las rutas `caja.gastos.adjuntos.*` / `caja.compras.adjuntos.*` en los archivos de test que ya cubren `ExpenseAttachmentController`/`PurchaseAttachmentController` (buscar los existentes de admin-sucursal y agregar el caso cajero): cajero ve/descarga adjuntos de **sus propios** gastos en su sucursal (200); cajero **no** ve adjuntos de gastos de otro cajero (403); cajero ve/descarga adjuntos de **cualquier** compra de su sucursal (200, sin filtro por dueño); cajero **no** ve adjuntos de otra sucursal (403 o 404, igual que hoy con admin-sucursal); cajero puede eliminar solo si el gasto/compra pertenece a su turno abierto (403 si no).
- Frontend: `vendor/bin/sail npm run build` verde + verificación manual de los 3 formularios (crear/editar gasto, crear/editar compra, adjuntar/ver/borrar comprobante de pago) y de las pantallas de Caja (ver adjuntos de un gasto propio, ver adjuntos de una compra de la sucursal) — sin test runner de JS en el proyecto, consistente con el resto del feature.
- Regresión: suite completa de PHP (`vendor/bin/sail artisan test --compact`) para confirmar que no se rompió nada de Gastos/Compras/Comprobantes ni el acceso ya existente de admin-empresa/admin-sucursal a esos mismos adjuntos.

## Documentación

Al implementar: actualizar `docs/modulos/comprobantes-pago.md` (ya existe, creado en T9) para mencionar el componente compartido y el endpoint de preview; nota breve en `docs/modulos/gastos.md` y `docs/modulos/compras.md` si describen la UI de adjuntos (confirmar en el plan si aplica).
