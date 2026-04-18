<script setup>
defineProps({
    title: { type: String, default: 'Sin datos en este rango' },
    hint: { type: String, default: 'Cambia el rango o vuelve más tarde.' },
});
</script>

<template>
    <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-200 bg-gray-50 px-6 py-10 text-center">
        <svg class="h-10 w-10 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h12a2.25 2.25 0 0 0 2.25-2.25V3M3.75 21h16.5M16.5 3.75V12m-6.75 3v-1.5m-3-3v-1.5m-3-3h.008v.008H3.75V8.25Zm3-3h.008v.008h-.008V5.25Z" />
        </svg>
        <p class="mt-3 text-sm font-semibold text-gray-700">{{ title }}</p>
        <p class="mt-1 text-xs text-gray-400">{{ hint }}</p>
    </div>
</template>
