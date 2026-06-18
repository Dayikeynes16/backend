<script setup>
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    categories: { type: Array, default: () => [] }, // [{id,name,status,products_count}]
    routePrefix: { type: String, default: 'empresa' },
    canDelete: { type: Boolean, default: false },
});

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);

const formOpen = ref(false);
const editing = ref(null);
const form = useForm({ name: '', status: 'active' });

const openCreate = () => { editing.value = null; form.reset(); form.clearErrors(); formOpen.value = true; };
const openEdit = (c) => { editing.value = c; form.name = c.name; form.status = c.status; form.clearErrors(); formOpen.value = true; };
const close = () => { form.clearErrors(); formOpen.value = false; };

const submit = () => {
    if (editing.value) {
        form.put(route(`${props.routePrefix}.productos-compra.categorias.update`, { tenant: slug.value, categoria: editing.value.id }), {
            preserveScroll: true, onSuccess: close,
        });
    } else {
        form.post(route(`${props.routePrefix}.productos-compra.categorias.store`, slug.value), {
            preserveScroll: true, onSuccess: () => { close(); form.reset(); },
        });
    }
};

const confirmDelete = ref(null);
const deleting = ref(false);
const doDelete = () => {
    deleting.value = true;
    router.delete(route(`${props.routePrefix}.productos-compra.categorias.destroy`, { tenant: slug.value, categoria: confirmDelete.value.id }), {
        preserveScroll: true,
        onFinish: () => { deleting.value = false; confirmDelete.value = null; },
    });
};
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-base font-bold text-gray-900">Categorías</h2>
                <p class="text-sm text-gray-500">Catálogo de categorías para clasificar tus productos de compra.</p>
            </div>
            <button @click="openCreate" class="rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:from-orange-600 hover:to-red-700">+ Nueva categoría</button>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Categoría</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Productos</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Estado</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    <tr v-for="c in categories" :key="c.id" class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-semibold text-gray-900">{{ c.name }}</td>
                        <td class="px-4 py-3 text-right">
                            <span class="inline-block min-w-[28px] rounded-lg bg-gray-100 px-2 py-0.5 text-center text-xs font-bold text-gray-700">{{ c.products_count }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span v-if="c.status === 'active'" class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-bold text-emerald-700"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Activa</span>
                            <span v-else class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-bold text-gray-500"><span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span>Inactiva</span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button @click="openEdit(c)" class="text-sm font-medium text-orange-700 hover:text-orange-900">Editar</button>
                            <button v-if="canDelete" @click="confirmDelete = c" class="ml-3 text-sm font-medium text-red-600 hover:text-red-800">Eliminar</button>
                        </td>
                    </tr>
                    <tr v-if="!categories.length">
                        <td colspan="4" class="px-4 py-12 text-center text-sm text-gray-500">
                            Sin categorías.
                            <button @click="openCreate" class="font-semibold text-orange-700 hover:underline">Crear la primera</button>.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Modal crear/editar -->
        <Teleport to="body">
            <Transition enter-active-class="transition" leave-active-class="transition" enter-from-class="opacity-0" leave-to-class="opacity-0">
                <div v-if="formOpen" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 backdrop-blur-sm sm:items-center sm:p-4" @click="close">
                    <div class="w-full max-w-md overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl" @click.stop>
                        <header class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                            <h3 class="text-lg font-bold text-gray-900">{{ editing ? 'Editar categoría' : 'Nueva categoría' }}</h3>
                            <button @click="close" class="text-gray-400 hover:text-gray-700">✕</button>
                        </header>
                        <form @submit.prevent="submit" class="space-y-4 px-5 py-5">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">Nombre <span class="text-red-600">*</span></label>
                                <input v-model="form.name" type="text" class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500" placeholder="Ej. Embutidos" />
                                <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                            </div>
                            <div v-if="editing">
                                <label class="mb-1 block text-sm font-medium text-gray-700">Estado</label>
                                <div class="flex gap-2">
                                    <button type="button" @click="form.status = 'active'" :class="['rounded-lg px-3 py-1.5 text-xs font-semibold', form.status === 'active' ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-700']">Activa</button>
                                    <button type="button" @click="form.status = 'inactive'" :class="['rounded-lg px-3 py-1.5 text-xs font-semibold', form.status === 'inactive' ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700']">Inactiva</button>
                                </div>
                            </div>
                        </form>
                        <footer class="flex justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3">
                            <button type="button" @click="close" class="rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">Cancelar</button>
                            <button @click="submit" :disabled="form.processing" class="rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:from-orange-600 hover:to-red-700 disabled:opacity-50">{{ form.processing ? 'Guardando…' : (editing ? 'Actualizar' : 'Crear') }}</button>
                        </footer>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Confirmación de borrado -->
        <Teleport to="body">
            <Transition enter-active-class="transition" leave-active-class="transition" enter-from-class="opacity-0" leave-to-class="opacity-0">
                <div v-if="confirmDelete" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm" @click="confirmDelete = null">
                    <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-xl" @click.stop>
                        <h3 class="text-base font-bold text-gray-900">Eliminar categoría</h3>
                        <p class="mt-2 text-sm text-gray-600">¿Eliminar <span class="font-semibold">{{ confirmDelete.name }}</span>? Los productos que la usan quedarán <span class="font-semibold">sin categoría</span>.</p>
                        <div class="mt-5 flex justify-end gap-2">
                            <button @click="confirmDelete = null" class="rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">Cancelar</button>
                            <button @click="doDelete" :disabled="deleting" class="rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50">{{ deleting ? 'Eliminando…' : 'Eliminar' }}</button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
