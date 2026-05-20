<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    providers: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    types: { type: Array, default: () => [] },
});

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);

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

        <div class="space-y-5">
            <div class="rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                El catálogo de proveedores lo administra el admin de empresa. Aquí solo puedes consultarlo para registrar tus compras.
            </div>

            <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center">
                <input v-model="search" type="text" placeholder="Buscar proveedor…"
                    class="flex-1 rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500" />
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
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <tr v-for="p in providers" :key="p.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-semibold text-gray-900">{{ p.name }}</td>
                            <td class="px-4 py-3 text-sm">
                                <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', typeBadgeColor(p.type)]">{{ p.type_label }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700">{{ p.phone || '—' }}</td>
                        </tr>
                        <tr v-if="!providers.length">
                            <td colspan="3" class="px-4 py-10 text-center text-sm italic text-gray-500">
                                Sin proveedores activos. Pídele a admin-empresa que los registre.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </SucursalLayout>
</template>
