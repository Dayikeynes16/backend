<script setup>
// Card explicativa con las fórmulas/definiciones que usa la página actual.
// Recibe items: [{ label, formula, description }]
defineProps({
    items: { type: Array, required: true },
    title: { type: String, default: 'Cómo se calculan estos números' },
});
</script>

<template>
    <div class="rounded-2xl border border-gray-100 bg-gray-50/50 p-5">
        <div class="flex items-center gap-2">
            <div class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-900 text-white">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg>
            </div>
            <h3 class="text-sm font-bold text-gray-900">{{ title }}</h3>
        </div>
        <dl class="mt-4 grid gap-3 sm:grid-cols-2">
            <div v-for="item in items" :key="item.label" class="rounded-xl bg-white px-4 py-3 ring-1 ring-gray-100">
                <dt class="flex items-center gap-2">
                    <span class="text-[10px] font-bold uppercase tracking-[0.15em] text-gray-500">{{ item.label }}</span>
                    <code v-if="item.formula" class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[10px] text-gray-700">{{ item.formula }}</code>
                </dt>
                <dd class="mt-1 text-xs leading-relaxed text-gray-600">{{ item.description }}</dd>
            </div>
        </dl>
    </div>
</template>
