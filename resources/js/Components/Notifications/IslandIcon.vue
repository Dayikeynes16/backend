<script setup>
import { computed } from 'vue';

/**
 * Los pocos íconos de la isla (Heroicons v2 outline, los mismos del hub). La
 * web no tiene un componente de íconos común: cada vista pinta su SVG en
 * línea. Aquí se agrupan porque `LEVEL_STYLE` (compartido con el hub) nombra
 * el ícono por su nombre y la isla y la fila tienen que resolverlo igual.
 */
const PATHS = {
    bell: 'M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0',
    'exclamation-triangle':
        'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z',
    'information-circle':
        'm11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z',
    'x-mark': 'M6 18 18 6M6 6l12 12',
};

const props = defineProps({
    name: { type: String, required: true },
    size: { type: [Number, String], default: 20 },
});

const d = computed(() => PATHS[props.name] ?? '');
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path :d="d" />
    </svg>
</template>
