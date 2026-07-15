<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import ComprasTabs from '@/Components/Compras/ComprasTabs.vue';
import FlashToast from '@/Components/FlashToast.vue';
import ProveedorFormModal from '@/Components/Proveedores/ProveedorFormModal.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    providers: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    types: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);

// --- Crear / editar (solo si la empresa habilitó el toggle de la sucursal) ---
const formOpen = ref(false);
const editingProvider = ref(null);
const openCreate = () => { editingProvider.value = null; formOpen.value = true; };
const openEdit = (p) => { editingProvider.value = p; formOpen.value = true; };

const search = ref(props.filters?.q || '');
const typeFilter = ref(props.filters?.type || '');

let debounceTimer;
const navigate = () => {
    router.get(route('sucursal.proveedores.index', slug.value), {
        q: search.value || undefined,
        type: typeFilter.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

watch(search, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(navigate, 300);
});

const setType = (key) => { typeFilter.value = key === typeFilter.value ? '' : key; navigate(); };

const typeBadgeColor = (type) => ({
    ganadero: 'bg-amber-100 text-amber-800',
    mayorista_carne: 'bg-rose-100 text-rose-800',
    insumos: 'bg-emerald-100 text-emerald-800',
    servicios: 'bg-sky-100 text-sky-800',
    otro: 'bg-gray-100 text-gray-700',
})[type] || 'bg-gray-100 text-gray-700';
</script>

<template>
    <Head title="Proveedores" />
    <SucursalLayout>
        <template #header>
            <h1 class="text-lg font-bold text-gray-900">Proveedores</h1>
        </template>

        <ComprasTabs active="proveedores" />

        <div class="space-y-5">
            <div v-if="!canManage" class="rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                El catálogo de proveedores lo administra el admin de empresa. Aquí solo puedes consultarlo para registrar tus compras.
            </div>
            <div v-else class="rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                Tu empresa habilitó la gestión de proveedores en tu sucursal. Los proveedores son compartidos con toda la empresa.
            </div>

            <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center">
                <input v-model="search" type="text" placeholder="Buscar proveedor…"
                    class="flex-1 rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500" />
                <button v-if="canManage" @click="openCreate"
                    class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-orange-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-orange-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Nuevo proveedor
                </button>
            </div>

            <div class="flex flex-wrap gap-2">
                <button v-for="t in types" :key="t.value"
                    @click="setType(t.value)"
                    :class="[
                        'rounded-full px-3 py-1.5 text-xs font-semibold transition',
                        typeFilter === t.value
                            ? typeBadgeColor(t.value) + ' ring-2 ring-offset-1 ring-gray-300'
                            : 'bg-white text-gray-600 border border-gray-200 hover:border-gray-400',
                    ]">
                    {{ t.label }}
                </button>
            </div>

            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Proveedor</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Tipo</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Teléfono</th>
                            <th v-if="canManage" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <tr v-for="p in providers" :key="p.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <Link :href="route('sucursal.proveedores.show', { tenant: slug, provider: p.id })"
                                    class="font-semibold text-gray-900 hover:text-orange-700 hover:underline">{{ p.name }}</Link>
                                <span v-if="p.status === 'inactive'" class="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-500">Inactivo</span>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', typeBadgeColor(p.type)]">{{ p.type_label }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700">{{ p.phone || '—' }}</td>
                            <td v-if="canManage" class="px-4 py-3 text-right">
                                <button @click="openEdit(p)" class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-200">Editar</button>
                            </td>
                        </tr>
                        <tr v-if="!providers.length">
                            <td :colspan="canManage ? 4 : 3" class="px-4 py-10 text-center text-sm italic text-gray-500">
                                <template v-if="canManage">Sin proveedores aún. Crea el primero con "Nuevo proveedor".</template>
                                <template v-else>Sin proveedores activos. Pídele a admin-empresa que los registre.</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ProveedorFormModal
            v-if="canManage"
            :open="formOpen"
            :provider="editingProvider"
            :types="types"
            route-prefix="sucursal"
            @close="formOpen = false" />

        <FlashToast />
    </SucursalLayout>
</template>
