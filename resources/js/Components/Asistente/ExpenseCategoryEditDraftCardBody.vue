<script setup>
// Editor de un borrador de EDICIÓN de categoría/subcategoría de gasto. Muestra
// el objetivo (fijo, leído del form) y permite ajustar nombre, descripción y
// estado. El form ya trae target_type/target_id ocultos para la confirmación.
defineProps({
    form: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
});
</script>

<template>
    <div class="space-y-3">
        <div class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">
            Editando {{ form.target_type === 'subcategoria' ? 'subcategoría' : 'categoría' }}:
            <span class="font-semibold text-gray-800">{{ form.current_name }}</span>
            <span v-if="form.current_status === 'inactive'" class="ml-1 rounded bg-gray-200 px-1.5 py-0.5 text-[11px]">inactiva</span>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">Nombre</label>
            <input
                v-model="form.name"
                :disabled="disabled"
                type="text"
                maxlength="120"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            />
            <p v-if="errors.name" class="mt-1 text-xs text-red-600">{{ errors.name[0] }}</p>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">Descripción</label>
            <textarea
                v-model="form.description"
                :disabled="disabled"
                rows="2"
                maxlength="500"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            />
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">Estado</label>
            <select
                v-model="form.status"
                :disabled="disabled"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            >
                <option value="active">Activa</option>
                <option value="inactive">Inactiva</option>
            </select>
            <p v-if="errors.status" class="mt-1 text-xs text-red-600">{{ errors.status[0] }}</p>
        </div>
    </div>
</template>
