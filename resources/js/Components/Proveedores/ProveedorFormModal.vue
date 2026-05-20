<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    provider: { type: Object, default: null },
    types: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);
const isEdit = computed(() => !!props.provider?.id);

const form = useForm({
    name: '',
    type: 'mayorista_carne',
    contact_name: '',
    phone: '',
    email: '',
    rfc: '',
    address: '',
    payment_terms_days: null,
    notes: '',
    status: 'active',
});

watch(() => props.open, (open) => {
    if (!open) return;
    if (props.provider) {
        form.name = props.provider.name ?? '';
        form.type = props.provider.type ?? 'mayorista_carne';
        form.contact_name = props.provider.contact_name ?? '';
        form.phone = props.provider.phone ?? '';
        form.email = props.provider.email ?? '';
        form.rfc = props.provider.rfc ?? '';
        form.address = props.provider.address ?? '';
        form.payment_terms_days = props.provider.payment_terms_days ?? null;
        form.notes = props.provider.notes ?? '';
        form.status = props.provider.status ?? 'active';
    } else {
        form.reset();
        form.clearErrors();
    }
});

const close = () => { form.clearErrors(); emit('close'); };

const submit = () => {
    if (isEdit.value) {
        form.put(route('empresa.proveedores.update', { tenant: slug.value, provider: props.provider.id }), {
            preserveScroll: true,
            onSuccess: () => close(),
        });
    } else {
        form.post(route('empresa.proveedores.store', slug.value), {
            preserveScroll: true,
            onSuccess: () => { close(); form.reset(); },
        });
    }
};
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition" leave-active-class="transition"
            enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 backdrop-blur-sm sm:items-center sm:p-4">
                <div class="w-full max-w-2xl overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl" @click.stop>
                    <header class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                        <h2 class="text-lg font-bold text-gray-900">{{ isEdit ? 'Editar proveedor' : 'Nuevo proveedor' }}</h2>
                        <button @click="close" class="text-gray-400 hover:text-gray-700">✕</button>
                    </header>

                    <form @submit.prevent="submit" class="max-h-[80vh] space-y-4 overflow-y-auto px-5 py-5">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Nombre <span class="text-red-600">*</span></label>
                            <input v-model="form.name" type="text" autocomplete="off"
                                class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500"
                                placeholder="Ej. Carnes Don Pedro" />
                            <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">Tipo <span class="text-red-600">*</span></label>
                                <select v-model="form.type" class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500">
                                    <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                                </select>
                                <p v-if="form.errors.type" class="mt-1 text-xs text-red-600">{{ form.errors.type }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">Días de crédito</label>
                                <input v-model.number="form.payment_terms_days" type="number" min="0" max="365"
                                    class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500"
                                    placeholder="0 = pago inmediato" />
                                <p v-if="form.errors.payment_terms_days" class="mt-1 text-xs text-red-600">{{ form.errors.payment_terms_days }}</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">Contacto</label>
                                <input v-model="form.contact_name" type="text"
                                    class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">Teléfono</label>
                                <input v-model="form.phone" type="tel"
                                    class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">Email</label>
                                <input v-model="form.email" type="email"
                                    class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500" />
                                <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">RFC</label>
                                <input v-model="form.rfc" type="text"
                                    class="w-full rounded-xl border-gray-300 text-sm uppercase focus:border-orange-500 focus:ring-orange-500" />
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Dirección</label>
                            <textarea v-model="form.address" rows="2"
                                class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500"></textarea>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Notas</label>
                            <textarea v-model="form.notes" rows="2"
                                class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500"
                                placeholder="Datos extra que quieras recordar"></textarea>
                        </div>

                        <div v-if="isEdit">
                            <label class="mb-1 block text-sm font-medium text-gray-700">Estado</label>
                            <div class="flex gap-2">
                                <button type="button" @click="form.status = 'active'"
                                    :class="['rounded-lg px-3 py-1.5 text-xs font-semibold',
                                        form.status === 'active' ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-700']">Activo</button>
                                <button type="button" @click="form.status = 'inactive'"
                                    :class="['rounded-lg px-3 py-1.5 text-xs font-semibold',
                                        form.status === 'inactive' ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700']">Inactivo</button>
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
