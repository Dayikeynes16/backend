/**
 * Una venta puede llevar dos nombres a la vez y hay que decidir cuál se ve.
 *
 * - `contact_name` es el nombre dictado en la báscula ("a nombre de Juan"). No
 *   es un cliente: es la etiqueta con la que se encuentra la bolsa en el
 *   mostrador.
 * - `customer` es con quién es la cuenta: crédito, fiado, precios
 *   preferenciales, historial.
 *
 * La regla: **el cliente manda cuando tiene un nombre de verdad; el dictado
 * sobrevive solo cuando aporta algo que el cliente no dice.** Y un cliente
 * creado automáticamente desde una venta (`name_pending`) se llama
 * "Cliente 55 1234 5678", que no es un nombre: ahí el dictado ocupa su lugar.
 *
 * Esto es solo presentación. El dictado nunca se borra del dato: sigue en el
 * detalle y en el ticket, solo se calla en pantalla cuando es redundante.
 *
 * Spec: docs/superpowers/specs/2026-08-19-nombre-venta-vs-cliente-design.md
 */

/** Recorta, pasa a minúsculas, quita diacríticos y colapsa espacios. */
function normalizar(valor) {
    return (valor ?? '')
        .toString()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .trim()
        .replace(/\s+/g, ' ');
}

function palabras(valor) {
    const limpio = normalizar(valor);
    return limpio ? limpio.split(' ') : [];
}

/**
 * ¿Los dos nombres se refieren a lo mismo?
 *
 * Se comparan **por palabras completas**: coinciden si la lista de palabras del
 * más corto es prefijo de la del más largo. Así "Juan" ≡ "Juan Pérez" —el caso
 * común, dictan el nombre de pila— pero "Ana" ≢ "Anabel Ruiz", que un prefijo
 * de texto se comería, y "Pérez" ≢ "Juan Pérez", porque dictar el apellido es
 * una etiqueta distinta y vale la pena conservarla.
 *
 * @returns {boolean}
 */
export function mismoNombre(a, b) {
    const pa = palabras(a);
    const pb = palabras(b);
    if (!pa.length || !pb.length) {
        return false;
    }
    const [corto, largo] = pa.length <= pb.length ? [pa, pb] : [pb, pa];
    return corto.every((palabra, i) => palabra === largo[i]);
}

/**
 * Qué nombres pintar en una venta.
 *
 * Ante la duda se devuelven los dos: mostrar de más es un ruido, mostrar de
 * menos es perder la referencia del paquete.
 *
 * @param {object|null} sale Venta con `contact_name` y, si la trae, `customer`.
 * @returns {{ customerName: string|null, contactName: string|null }}
 *          `customerName` se pinta con icono de persona y sin rótulo;
 *          `contactName` con icono de etiqueta y el rótulo "A nombre de".
 */
export function saleNames(sale) {
    const dictado = (sale?.contact_name ?? '').toString().trim();
    const cliente = sale?.customer ?? null;
    const nombreCliente = (cliente?.name ?? '').toString().trim();

    if (!cliente) {
        return { customerName: null, contactName: dictado || null };
    }

    // Un placeholder no es un nombre: con `name_pending`, el dictado es el mejor
    // nombre disponible y ocupa su sitio — con icono de cliente, porque cliente
    // sí hay.
    if (cliente.name_pending) {
        return { customerName: dictado || nombreCliente || null, contactName: null };
    }

    // Se colapsa la redundancia, no la información.
    if (dictado && mismoNombre(dictado, nombreCliente)) {
        return { customerName: nombreCliente || null, contactName: null };
    }

    return { customerName: nombreCliente || null, contactName: dictado || null };
}
