<script setup>
// Editor de un borrador de alta de cliente. Muta el `form` compartido.
defineProps({
    form: { type: Object, required: true },
    options: { type: Object, default: () => ({ branches: [] }) },
    errors: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
    preview: { type: Object, default: () => ({}) },
});
</script>

<template>
    <div class="space-y-3">
        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">Nombre del cliente</label>
            <input v-model="form.name" :disabled="disabled" type="text" maxlength="160"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50" />
            <p v-if="errors.name" class="mt-1 text-xs text-red-600">{{ errors.name[0] }}</p>
        </div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-semibold text-gray-600">Teléfono</label>
                <input v-model="form.phone" :disabled="disabled" type="tel" maxlength="20"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50" />
                <p v-if="errors.phone" class="mt-1 text-xs text-red-600">{{ errors.phone[0] }}</p>
            </div>
            <div v-if="(options.branches || []).length > 1">
                <label class="mb-1 block text-xs font-semibold text-gray-600">Sucursal</label>
                <select v-model.number="form.branch_id" :disabled="disabled"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50">
                    <option :value="null">Selecciona…</option>
                    <option v-for="b in options.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
                <p v-if="errors.branch_id" class="mt-1 text-xs text-red-600">{{ errors.branch_id[0] }}</p>
            </div>
        </div>
        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">Notas</label>
            <textarea v-model="form.notes" :disabled="disabled" rows="2" maxlength="500"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50" />
        </div>
    </div>
</template>
