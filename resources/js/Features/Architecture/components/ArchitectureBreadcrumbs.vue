<script setup>
defineProps({
    level: {
        type: String,
        required: true,
        validator: (value) => ['ecosystem', 'application', 'module'].includes(value),
    },
    application: {
        type: Object,
        default: null,
        validator: (value) => value === null || typeof value.id === 'string',
    },
    module: {
        type: Object,
        default: null,
        validator: (value) => value === null || typeof value.id === 'string',
    },
});

const emit = defineEmits({
    'go-ecosystem': () => true,
    'go-application': () => true,
});
</script>

<template>
    <nav aria-label="Ruta del Atlas" class="border-b border-slate-200 bg-white px-4 sm:px-6">
        <ol class="flex min-h-12 flex-wrap items-center gap-1 text-sm">
            <li>
                <button
                    type="button"
                    class="inline-flex min-h-11 items-center rounded-lg px-2 font-semibold text-slate-600 outline-none transition hover:bg-slate-100 hover:text-slate-950 focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2"
                    :aria-current="level === 'ecosystem' ? 'page' : undefined"
                    @click="emit('go-ecosystem')"
                >
                    Ecosistema
                </button>
            </li>

            <template v-if="application">
                <li aria-hidden="true" class="px-1 font-mono text-slate-300">/</li>
                <li>
                    <button
                        type="button"
                        class="inline-flex min-h-11 items-center rounded-lg px-2 font-semibold text-slate-600 outline-none transition hover:bg-slate-100 hover:text-slate-950 focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2"
                        :aria-current="level === 'application' ? 'page' : undefined"
                        @click="emit('go-application')"
                    >
                        {{ application.name }}
                    </button>
                </li>
            </template>

            <template v-if="module">
                <li aria-hidden="true" class="px-1 font-mono text-slate-300">/</li>
                <li class="min-w-0">
                    <span aria-current="page" class="block truncate px-2 font-bold text-slate-950">
                        {{ module.name }}
                    </span>
                </li>
            </template>
        </ol>
    </nav>
</template>
