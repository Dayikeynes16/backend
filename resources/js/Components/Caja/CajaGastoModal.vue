<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    tenantSlug: { type: String, required: true },
    subcategories: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);

const form = useForm({
    concept: '',
    amount: '',
    expense_subcategory_id: '',
    description: '',
});

const canSubmit = computed(() =>
    form.concept.trim() !== '' && parseFloat(form.amount) > 0 && form.expense_subcategory_id !== ''
);

const submit = () => {
    form.post(route('caja.gastos.store', props.tenantSlug), {
        preserveScroll: true,
        onSuccess: () => { form.reset(); emit('close'); },
    });
};

const close = () => { form.clearErrors(); emit('close'); };
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition" leave-active-class="transition"
            enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 backdrop-blur-sm sm:items-center sm:p-4">
                <div class="w-full max-w-md overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl" @click.stop>
                    <header class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                        <h2 class="text-lg font-bold text-gray-900">Gasto en efectivo</h2>
                        <button @click="close" :disabled="form.processing" class="text-gray-400 hover:text-gray-700">✕</button>
                    </header>

                    <form @submit.prevent="submit" class="space-y-4 px-5 py-5">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Subcategoría <span class="text-red-600">*</span></label>
                            <select v-model="form.expense_subcategory_id"
                                class="w-full rounded-xl border-gray-300 text-sm focus:border-red-500 focus:ring-red-500">
                                <option value="" disabled>Selecciona…</option>
                                <option v-for="s in subcategories" :key="s.id" :value="s.id">{{ s.category }} · {{ s.name }}</option>
                            </select>
                            <p v-if="form.errors.expense_subcategory_id" class="mt-1 text-xs text-red-600">{{ form.errors.expense_subcategory_id }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Concepto <span class="text-red-600">*</span></label>
                            <input v-model="form.concept" type="text" maxlength="160"
                                class="w-full rounded-xl border-gray-300 text-sm focus:border-red-500 focus:ring-red-500"
                                placeholder="Ej. Bolsas, gas, propina" />
                            <p v-if="form.errors.concept" class="mt-1 text-xs text-red-600">{{ form.errors.concept }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Monto <span class="text-red-600">*</span></label>
                            <input v-model="form.amount" type="number" step="0.01" min="0.01"
                                class="w-full rounded-xl border-gray-300 text-sm focus:border-red-500 focus:ring-red-500"
                                placeholder="0.00" />
                            <p v-if="form.errors.amount" class="mt-1 text-xs text-red-600">{{ form.errors.amount }}</p>
                        </div>
                    </form>

                    <footer class="flex justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3">
                        <button type="button" @click="close" :disabled="form.processing"
                            class="rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">Cancelar</button>
                        <button @click="submit" :disabled="form.processing || !canSubmit"
                            class="rounded-xl bg-red-600 px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700 disabled:opacity-50">
                            {{ form.processing ? 'Guardando…' : 'Registrar gasto' }}
                        </button>
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
