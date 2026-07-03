<script setup>
// Editor de un borrador de categoría/subcategoría de gasto. Muta el objeto
// `form` compartido.
defineProps({
    form: { type: Object, required: true },
    options: { type: Object, default: () => ({ categories: [] }) },
    errors: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
});
</script>

<template>
    <div class="space-y-3">
        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">Tipo</label>
            <div class="flex gap-4 text-sm">
                <label class="flex items-center gap-1.5">
                    <input v-model="form.tipo" :disabled="disabled" type="radio" value="categoria" class="text-orange-600 focus:ring-orange-500" />
                    Categoría
                </label>
                <label class="flex items-center gap-1.5">
                    <input v-model="form.tipo" :disabled="disabled" type="radio" value="subcategoria" class="text-orange-600 focus:ring-orange-500" />
                    Subcategoría
                </label>
            </div>
        </div>

        <div v-if="form.tipo === 'subcategoria'">
            <label class="mb-1 block text-xs font-semibold text-gray-600">Categoría padre</label>
            <select
                v-model.number="form.existing_category_id"
                :disabled="disabled"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            >
                <option :value="null">Selecciona…</option>
                <option v-for="c in options.categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <p v-if="errors.existing_category_id" class="mt-1 text-xs text-red-600">{{ errors.existing_category_id[0] }}</p>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">Nombre</label>
            <input
                v-model="form.nombre"
                :disabled="disabled"
                type="text"
                maxlength="120"
                :placeholder="form.tipo === 'subcategoria' ? 'p. ej. Gasolina' : 'p. ej. Mantenimiento'"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            />
            <p v-if="errors.nombre" class="mt-1 text-xs text-red-600">{{ errors.nombre[0] }}</p>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">Descripción</label>
            <textarea
                v-model="form.descripcion"
                :disabled="disabled"
                rows="2"
                maxlength="500"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            />
        </div>
    </div>
</template>
