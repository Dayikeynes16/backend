<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    product: { type: Object, default: null },
    categories: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);
const isEdit = computed(() => !!props.product?.id);

const units = ['kg', 'g', 'l', 'ml', 'pieza', 'caja', 'bulto', 'cabeza'];

const form = useForm({ name: '', unit: 'kg', category: '', status: 'active' });

watch(() => props.open, (open) => {
    if (!open) return;
    if (props.product) {
        form.name = props.product.name ?? '';
        form.unit = props.product.unit ?? 'kg';
        form.category = props.product.category ?? '';
        form.status = props.product.status ?? 'active';
    } else {
        form.reset();
        form.clearErrors();
    }
});

const close = () => { form.clearErrors(); emit('close'); };

const submit = () => {
    if (isEdit.value) {
        form.put(route('empresa.productos-compra.update', { tenant: slug.value, producto_compra: props.product.id }), {
            preserveScroll: true, onSuccess: () => close(),
        });
    } else {
        form.post(route('empresa.productos-compra.store', slug.value), {
            preserveScroll: true, onSuccess: () => { close(); form.reset(); },
        });
    }
};
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition" leave-active-class="transition"
            enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 backdrop-blur-sm sm:items-center sm:p-4">
                <div class="w-full max-w-lg overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl" @click.stop>
                    <header class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                        <h2 class="text-lg font-bold text-gray-900">{{ isEdit ? 'Editar producto' : 'Nuevo producto de compra' }}</h2>
                        <button @click="close" class="text-gray-400 hover:text-gray-700">✕</button>
                    </header>

                    <form @submit.prevent="submit" class="space-y-4 px-5 py-5">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Nombre <span class="text-red-600">*</span></label>
                            <input v-model="form.name" type="text"
                                class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500"
                                placeholder="Ej. Media canal de res" />
                            <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">Unidad <span class="text-red-600">*</span></label>
                                <select v-model="form.unit" class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500">
                                    <option v-for="u in units" :key="u" :value="u">{{ u }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">Categoría</label>
                                <select v-model="form.category" class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500">
                                    <option value="">— sin categoría —</option>
                                    <option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option>
                                </select>
                            </div>
                        </div>
                        <div v-if="isEdit">
                            <label class="mb-1 block text-sm font-medium text-gray-700">Estado</label>
                            <div class="flex gap-2">
                                <button type="button" @click="form.status = 'active'"
                                    :class="['rounded-lg px-3 py-1.5 text-xs font-semibold', form.status === 'active' ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-700']">Activo</button>
                                <button type="button" @click="form.status = 'inactive'"
                                    :class="['rounded-lg px-3 py-1.5 text-xs font-semibold', form.status === 'inactive' ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700']">Inactivo</button>
                            </div>
                        </div>
                    </form>

                    <footer class="flex justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3">
                        <button type="button" @click="close" class="rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">Cancelar</button>
                        <button @click="submit" :disabled="form.processing"
                            class="rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:from-orange-600 hover:to-red-700 disabled:opacity-50">
                            {{ form.processing ? 'Guardando…' : (isEdit ? 'Actualizar' : 'Crear') }}
                        </button>
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
