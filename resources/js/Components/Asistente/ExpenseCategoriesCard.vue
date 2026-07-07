<script setup>
// Tarjeta de solo lectura: catálogo de categorías/subcategorías de gasto.
defineProps({
    data: { type: Object, required: true },
});
</script>

<template>
    <div class="rounded-xl border border-gray-200/80 bg-white px-4 py-3 text-sm">
        <div class="mb-3 flex items-center justify-between">
            <div class="font-semibold text-gray-800">Categorías de gasto</div>
            <div class="text-xs text-gray-500">
                {{ data.total }} categorías · {{ data.active }} activas · {{ data.inactive }} inactivas
            </div>
        </div>

        <p v-if="!data.categories.length" class="text-sm italic text-gray-500">Sin categorías en el catálogo.</p>

        <div v-else class="max-h-96 space-y-3 overflow-y-auto pr-1">
            <div v-for="cat in data.categories" :key="cat.id" class="rounded-lg border border-gray-100 bg-gray-50/60 p-3">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="truncate font-semibold text-gray-800">{{ cat.name }}</span>
                        <span
                            v-if="cat.status !== 'active'"
                            class="shrink-0 rounded bg-gray-200 px-1.5 py-0.5 text-[11px] text-gray-600"
                        >inactiva</span>
                    </div>
                    <span class="shrink-0 text-xs text-gray-500">{{ cat.expense_count }} gasto(s)</span>
                </div>
                <p v-if="cat.description" class="mt-0.5 text-xs text-gray-500">{{ cat.description }}</p>

                <ul v-if="cat.subcategories.length" class="mt-2 space-y-1">
                    <li
                        v-for="sub in cat.subcategories"
                        :key="sub.id"
                        class="flex items-center justify-between gap-2 border-t border-gray-100 pt-1 text-xs"
                    >
                        <span class="flex min-w-0 items-center gap-1.5 text-gray-700">
                            <span class="text-gray-300">•</span>
                            <span class="truncate">{{ sub.name }}</span>
                            <span
                                v-if="sub.status !== 'active'"
                                class="shrink-0 rounded bg-gray-200 px-1 py-0.5 text-[10px] text-gray-500"
                            >inactiva</span>
                        </span>
                        <span class="shrink-0 text-gray-400">{{ sub.expense_count }}</span>
                    </li>
                </ul>
                <p v-else class="mt-1 text-xs italic text-gray-400">Sin subcategorías.</p>
            </div>
        </div>
    </div>
</template>
