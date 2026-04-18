<script setup>
defineProps({
    title: String,
    subtitle: String,
});
</script>

<template>
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <div class="mb-4 flex items-start justify-between">
            <div>
                <h3 class="text-sm font-bold tracking-tight text-gray-900">{{ title }}</h3>
                <p v-if="subtitle" class="mt-0.5 text-xs text-gray-500">{{ subtitle }}</p>
            </div>
            <slot name="actions" />
        </div>
        <slot />
    </div>
</template>
