<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({ categories: Object, filters: Object, tenant: Object });

const search = ref(props.filters?.search || '');
let debounce;
watch(search, (v) => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(route('sucursal.categorias.index', props.tenant.slug), { search: v || undefined }, { preserveState: true, replace: true });
    }, 300);
});

const showCreate = ref(false);
const createForm = useForm({ name: '' });
const submitCreate = () => {
    createForm.post(route('sucursal.categorias.store', props.tenant.slug), {
        onSuccess: () => { createForm.reset(); showCreate.value = false; },
    });
};

const editingId = ref(null);
const editForm = useForm({ name: '', status: 'active' });
const startEdit = (cat) => { editingId.value = cat.id; editForm.name = cat.name; editForm.status = cat.status; };
const cancelEdit = () => { editingId.value = null; };
const submitEdit = (id) => {
    editForm.put(route('sucursal.categorias.update', [props.tenant.slug, id]), {
        onSuccess: () => { editingId.value = null; },
    });
};
const deleteCategory = (id) => {
    if (confirm('¿Eliminar esta categoria?')) {
        router.delete(route('sucursal.categorias.destroy', [props.tenant.slug, id]));
    }
};
</script>

<template>
    <Head title="Categorias" />
    <SucursalLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Categorias</h1>
        </template>

        <div class="mx-auto max-w-3xl space-y-6">
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="flex flex-col gap-3 border-b border-gray-100 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                        <input v-model="search" type="text" placeholder="Buscar..." class="w-full rounded-lg border-gray-200 py-2.5 pl-10 pr-4 text-sm text-gray-700 placeholder-gray-400 focus:border-red-400 focus:ring-red-300 sm:w-64" />
                    </div>
                    <button @click="showCreate = !showCreate" class="inline-flex items-center justify-center gap-2 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        Nueva Categoria
                    </button>
                </div>

                <!-- Inline create -->
                <div v-if="showCreate" class="border-b border-gray-100 px-6 py-4">
                    <form @submit.prevent="submitCreate" class="flex items-end gap-3">
                        <div class="flex-1">
                            <label class="text-xs font-medium text-gray-500">Nombre</label>
                            <TextInput v-model="createForm.name" type="text" class="mt-1 block w-full" required autofocus placeholder="Ej: Res, Cerdo, Pollo..." />
                            <InputError :message="createForm.errors.name" class="mt-1" />
                        </div>
                        <button type="submit" :disabled="createForm.processing" class="rounded-lg bg-red-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-red-700 disabled:opacity-50">Crear</button>
                        <button type="button" @click="showCreate = false" class="rounded-lg px-3 py-2.5 text-sm text-gray-500 hover:bg-gray-100">Cancelar</button>
                    </form>
                </div>

                <!-- List -->
                <div class="divide-y divide-gray-50">
                    <div v-for="cat in categories.data" :key="cat.id" class="flex items-center gap-4 px-6 py-4 transition hover:bg-gray-50">
                        <template v-if="editingId === cat.id">
                            <form @submit.prevent="submitEdit(cat.id)" class="flex flex-1 items-end gap-3">
                                <div class="flex-1">
                                    <TextInput v-model="editForm.name" type="text" class="block w-full" required />
                                </div>
                                <select v-model="editForm.status" class="rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300">
                                    <option value="active">Activa</option>
                                    <option value="inactive">Inactiva</option>
                                </select>
                                <button type="submit" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700">Guardar</button>
                                <button type="button" @click="cancelEdit" class="text-sm text-gray-500 hover:text-gray-700">Cancelar</button>
                            </form>
                        </template>
                        <template v-else>
                            <div class="flex-1">
                                <p class="text-sm font-semibold text-gray-900">{{ cat.name }}</p>
                                <p class="text-xs text-gray-400">{{ cat.products_count }} producto{{ cat.products_count !== 1 ? 's' : '' }}</p>
                            </div>
                            <span :class="cat.status === 'active' ? 'bg-green-50 text-green-700 ring-green-600/20' : 'bg-red-50 text-red-700 ring-red-600/20'" class="rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset">{{ cat.status === 'active' ? 'Activa' : 'Inactiva' }}</span>
                            <button @click="startEdit(cat)" class="text-sm font-semibold text-red-600 hover:text-red-700">Editar</button>
                            <button @click="deleteCategory(cat.id)" class="text-sm text-gray-400 hover:text-red-600">Eliminar</button>
                        </template>
                    </div>
                    <div v-if="categories.data.length === 0" class="px-6 py-16 text-center text-sm text-gray-400">No hay categorias.</div>
                </div>
            </div>
        </div>

        <FlashToast />
    </SucursalLayout>
</template>
