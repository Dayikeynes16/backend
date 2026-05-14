# Gastos — Form más intuitivo + captura con cámara

**Fecha:** 2026-05-13
**Estado:** aprobado para implementar
**Alcance:** UX del modal de registro/edición de gastos. Cero cambios de backend.

## Motivación

El form actual de `GastoFormModal.vue` cumple pero está optimizado para escritorio
y ordenado por convención más que por uso real: el usuario pone primero
"concepto" (algo que no necesariamente recuerda al pagar) y al final el monto y
los adjuntos (que son justo lo que sí tiene a la mano). No hay manera de capturar
una foto del ticket directamente desde el dispositivo. Y hay un bug reportado
donde, al cerrar y reabrir el modal, queda información del registro previo.

## Decisiones tomadas

- **Cámara**: botón nativo con `<input type="file" accept="image/*" capture="environment">`.
  Sin `getUserMedia`. En móvil abre la cámara trasera del SO; en desktop se
  comporta como abrir el explorador de archivos. Cero permisos especiales, cero
  HTTPS obligatorio para esta primera versión. Si después necesitamos preview en
  vivo / retake, lo sumamos sin tirar lo de ahora.
- **Subcategorías inline**: NO. Se mantiene la pestaña de Categorías separada.
- **Recientes / atajos de monto**: NO. Se evalúan en F2 si hay evidencia.
- **Selectores**: se quedan como `<select>`. Sin recientes, los chips no aportan
  contra la simplicidad del select.

## Cambios

### 1. Orden del form (de arriba a abajo)

| Antes | Después |
|---|---|
| Concepto | **Monto (MXN)** |
| Categoría / Subcategoría | **Adjuntos** (foto + archivo) |
| Monto + Fecha | Concepto |
| Sucursal (si aplica) | Categoría / Subcategoría |
| Notas | Fecha |
| Adjuntos | Sucursal (si aplica) |
|   | Notas |

Razón: cuando el usuario captura un gasto recién pagado, lo que tiene a la mano
es el monto y el ticket. Subirlos primero baja la fricción y permite cancelar
tarde menos información si interrumpen.

### 2. Botón de cámara

Junto al botón "Agregar archivo", segundo botón con icono de cámara:

```html
<label class="...">
  <input type="file" accept="image/*" capture="environment" @change="onFileSelect" hidden>
  Tomar foto
</label>
```

Reusa `onFileSelect()` existente: las imágenes capturadas entran al mismo
`newFiles[]`, pasan por la misma validación (mime + tamaño + slots restantes) y
se envían al mismo endpoint multipart. El servicio `ExpenseAttachmentService`
no requiere cambios.

### 3. Miniaturas de adjuntos

Reemplazar la lista lineal por una **grilla 3-4 columnas** de tarjetas 96×96:
- Imágenes nuevas (en queue): miniatura generada con `URL.createObjectURL(file)`.
  Liberar la URL en `removeNewFile()` con `URL.revokeObjectURL()`.
- Imágenes existentes (edit): `<img>` apuntando a la route de preview con
  `loading="lazy"`.
- PDFs: ícono de PDF + nombre truncado.
- Botón X en la esquina superior derecha de cada tarjeta para quitar.
- Click en la miniatura: en edit abre `AttachmentViewerModal`; en nuevos no hace
  nada (o un zoom local con la objectURL — opcional).
- Contador "3/5 archivos" debajo, en gris.

### 4. Fix del bug de estado persistente

El watcher actual solo reinicia al abrir:
```js
watch(() => props.show, (val) => {
    if (!val) return;
    reset();
    ...
});
```

Cambiar a:
```js
watch(() => props.show, (val) => {
    if (val) {
        reset();
        if (props.mode === 'edit') populateFromExpense();
        else setupCreate();
    } else {
        // limpieza preventiva al cerrar — evita que un siguiente open
        // herede valores si el padre no fuerza el cambio de show.
        reset();
    }
});
```

Y, defensivamente, agregar watch a `mode` y `expense?.id` para reiniciar cuando
el padre cambia el target sin cerrar el modal.

`reset()` también debe revocar objectURLs de miniaturas locales (memory hygiene).

## Lo que NO toco

- Validación PHP (`ExpenseAttachmentService::ALLOWED_MIMES`, `MAX_BYTES`, `MAX_PER_EXPENSE`).
- Controladores Sucursal/Empresa.
- Rutas, permisos.
- `AttachmentViewerModal`, `GastoDetailModal`.
- Pestaña "Categorías".

## Pruebas

- Tests de controlador existentes (`Sucursal/GastoControllerTest`, `Empresa/GastoControllerTest`) siguen pasando — no toco PHP.
- `npm run build` debe compilar limpio.
- Recorrido manual:
  1. Crear gasto, llenar todo, guardar → reabrir → form limpio (bug).
  2. En móvil (DevTools responsive + en dispositivo real si es posible): "Tomar foto" abre cámara.
  3. Adjuntar 2 imágenes + 1 PDF, ver miniaturas, eliminar una, guardar → ver en detalle.
  4. Editar un gasto con adjuntos existentes → miniaturas se cargan, agregar foto, eliminar existente.
  5. Cambiar de "edit" a "create" sin cerrar (vía botón en el padre) → form se reinicia.

## Orden de implementación

1. Reorden de campos + watcher de cierre (fix del bug).
2. Botón de cámara (un input adicional, mismo handler).
3. Miniaturas con grilla + objectURL.
4. Build y prueba.
