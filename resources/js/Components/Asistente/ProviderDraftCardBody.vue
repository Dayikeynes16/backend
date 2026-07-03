<script setup>
// Campos editables de un borrador de proveedor dentro del chat. Muta el objeto
// `form` compartido (mismo reference que el wrapper).
defineProps({
    form: { type: Object, required: true },
    options: { type: Object, default: () => ({ types: [] }) },
    errors: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
});
</script>

<template>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <label class="mb-1 block text-xs font-semibold text-gray-600">Nombre</label>
            <input
                v-model="form.name"
                :disabled="disabled"
                type="text"
                maxlength="160"
                placeholder="p. ej. Distribuidora La Unión"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            />
            <p v-if="errors.name" class="mt-1 text-xs text-red-600">{{ errors.name[0] }}</p>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">Tipo</label>
            <select
                v-model="form.type"
                :disabled="disabled"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            >
                <option :value="null">Selecciona…</option>
                <option v-for="t in options.types" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
            <p v-if="errors.type" class="mt-1 text-xs text-red-600">{{ errors.type[0] }}</p>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">Teléfono</label>
            <input
                v-model="form.phone"
                :disabled="disabled"
                type="text"
                maxlength="40"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            />
            <p v-if="errors.phone" class="mt-1 text-xs text-red-600">{{ errors.phone[0] }}</p>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">RFC</label>
            <input
                v-model="form.rfc"
                :disabled="disabled"
                type="text"
                maxlength="20"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            />
            <p v-if="errors.rfc" class="mt-1 text-xs text-red-600">{{ errors.rfc[0] }}</p>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">Correo</label>
            <input
                v-model="form.email"
                :disabled="disabled"
                type="email"
                maxlength="160"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            />
            <p v-if="errors.email" class="mt-1 text-xs text-red-600">{{ errors.email[0] }}</p>
        </div>

        <div class="sm:col-span-2">
            <label class="mb-1 block text-xs font-semibold text-gray-600">Dirección</label>
            <input
                v-model="form.address"
                :disabled="disabled"
                type="text"
                maxlength="500"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            />
        </div>

        <div class="sm:col-span-2">
            <label class="mb-1 block text-xs font-semibold text-gray-600">Notas</label>
            <textarea
                v-model="form.notes"
                :disabled="disabled"
                rows="2"
                maxlength="1000"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            />
        </div>
    </div>
</template>
