<script setup>
import { computed } from 'vue';

/**
 * Estado del tiempo real, visible para el cajero.
 *
 * Sin esto, un socket caído es indistinguible de "no ha entrado ninguna venta":
 * la lista se queda quieta y nadie sabe si es que no hay trabajo o es que la
 * pantalla dejó de enterarse. El mismo chip existe en la Mesa de Trabajo del
 * hub; aquí se replica su lenguaje para que ambas superficies se lean igual.
 */
const props = defineProps({
    live: { type: Boolean, default: false },
    recovering: { type: Boolean, default: false },
    /** Segundos del sondeo de respaldo, para poder decirlo en el tooltip. */
    fallbackSeconds: { type: Number, default: 4 },
});

const state = computed(() => {
    if (props.live) {
        return {
            label: 'En vivo',
            cls: 'bg-green-100 text-green-700',
            dot: 'bg-green-500',
            title: 'Las ventas y los cambios aparecen al instante.',
            spin: false,
        };
    }
    if (props.recovering) {
        return {
            label: 'Reconectando…',
            cls: 'bg-amber-100 text-amber-700',
            dot: 'bg-amber-500',
            title: `Recuperando el tiempo real. Mientras tanto la lista se actualiza sola cada ${props.fallbackSeconds} segundos.`,
            spin: true,
        };
    }
    return {
        label: `Actualizando cada ${props.fallbackSeconds} s`,
        cls: 'bg-gray-100 text-gray-600',
        dot: 'bg-gray-400',
        title: 'Sin conexión en vivo. La lista se actualiza sola por consulta periódica.',
        spin: true,
    };
});
</script>

<template>
    <span
        :class="['inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold', state.cls]"
        :title="state.title"
    >
        <span :class="['h-1.5 w-1.5 rounded-full', state.dot, state.spin ? 'animate-pulse' : '']" aria-hidden="true" />
        {{ state.label }}
    </span>
</template>
