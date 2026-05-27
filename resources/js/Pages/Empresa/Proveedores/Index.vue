<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import ProveedorFormModal from '@/Components/Proveedores/ProveedorFormModal.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    providers: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    types: { type: Array, default: () => [] },
    kpis: { type: Object, default: () => ({ total_active: 0, total_inactive: 0, with_pending_debt: 0 }) },
});

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);

const search = ref(props.filters?.q || '');
const typeFilter = ref(props.filters?.type || '');
const statusFilter = ref(props.filters?.status || 'active');

const fmt = (n) => '$' + Number(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

let debounceTimer;
const navigate = () => {
    router.get(route('empresa.proveedores.index', slug.value), {
        q: search.value || undefined,
        type: typeFilter.value || undefined,
        status: statusFilter.value !== 'active' ? statusFilter.value : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

watch(search, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(navigate, 300);
});

const setStatus = (key) => { statusFilter.value = key; navigate(); };
const setType = (key) => { typeFilter.value = key === typeFilter.value ? '' : key; navigate(); };

const typeBadgeColor = (type) => ({
    ganadero: 'bg-amber-100 text-amber-800',
    mayorista_carne: 'bg-rose-100 text-rose-800',
    insumos: 'bg-emerald-100 text-emerald-800',
    servicios: 'bg-sky-100 text-sky-800',
    otro: 'bg-gray-100 text-gray-700',
})[type] || 'bg-gray-100 text-gray-700';

const formOpen = ref(false);
const editing = ref(null);

const openCreate = () => { editing.value = null; formOpen.value = true; };
const openEdit = (provider) => { editing.value = { ...provider }; formOpen.value = true; };

const remove = (provider) => {
    if (!confirm(`¿Eliminar proveedor "${provider.name}"?`)) return;
    router.delete(route('empresa.proveedores.destroy', { tenant: slug.value, provider: provider.id }), {
        preserveScroll: true,
    });
};

const flash = computed(() => page.props.flash || {});
</script>

<template>
    <Head title="Proveedores" />
    <EmpresaLayout>
        <template #header>
            <h1 class="text-lg font-bold text-gray-900">Proveedores</h1>
        </template>

        <div class="space-y-5">
            <!-- KPIs -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="rounded-2xl border border-emerald-100 bg-white p-4 shadow-sm">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Activos</div>
                    <div class="text-2xl font-bold text-gray-900">{{ kpis.total_active }}</div>
                </div>
                <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Inactivos</div>
                    <div class="text-2xl font-bold text-gray-900">{{ kpis.total_inactive }}</div>
                </div>
                <div class="rounded-2xl border border-amber-100 bg-white p-4 shadow-sm">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Con saldo pendiente</div>
                    <div class="text-2xl font-bold text-gray-900">{{ kpis.with_pending_debt }}</div>
                </div>
            </div>

            <!-- Toolbar -->
            <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Buscar nombre o RFC…"
                        class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 sm:max-w-sm"
                    />
                    <div class="flex gap-1">
                        <button v-for="key in ['active', 'inactive', 'all']" :key="key"
                            @click="setStatus(key)"
                            :class="[
                                'rounded-lg px-3 py-2 text-xs font-semibold transition',
                                statusFilter === key
                                    ? 'bg-gray-900 text-white'
                                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200',
                            ]">
                            {{ key === 'active' ? 'Activos' : key === 'inactive' ? 'Inactivos' : 'Todos' }}
                        </button>
                    </div>
                </div>
                <button
                    @click="openCreate"
                    class="rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:from-orange-600 hover:to-red-700"
                >
                    + Nuevo proveedor
                </button>
            </div>

            <!-- Chips de tipo -->
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

            <!-- Flash messages -->
            <div v-if="flash.success" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ flash.success }}</div>
            <div v-if="flash.error" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800">{{ flash.error }}</div>

            <!-- Tabla -->
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Proveedor</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Tipo</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Teléfono</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Compras</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Saldo</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <tr v-for="p in providers" :key="p.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div>
                                        <Link :href="route('empresa.proveedores.show', { tenant: slug, provider: p.id })"
                                            class="font-semibold text-gray-900 hover:text-orange-700 hover:underline">{{ p.name }}</Link>
                                        <div v-if="p.rfc" class="text-xs text-gray-500">RFC: {{ p.rfc }}</div>
                                    </div>
                                    <span v-if="p.status === 'inactive'" class="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-medium text-gray-700">Inactivo</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', typeBadgeColor(p.type)]">{{ p.type_label }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700">{{ p.phone || '—' }}</td>
                            <td class="px-4 py-3 text-right text-sm font-medium text-gray-900">{{ p.purchases_count }}</td>
                            <td class="px-4 py-3 text-right text-sm font-medium" :class="p.pending_total > 0 ? 'text-amber-700' : 'text-gray-400'">
                                {{ p.pending_total > 0 ? fmt(p.pending_total) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Link :href="route('empresa.proveedores.show', { tenant: slug, provider: p.id })" class="text-sm font-medium text-gray-700 hover:text-gray-900">Ver</Link>
                                <button @click="openEdit(p)" class="ml-3 text-sm font-medium text-orange-700 hover:text-orange-900">Editar</button>
                                <button @click="remove(p)" class="ml-3 text-sm font-medium text-red-600 hover:text-red-800">Eliminar</button>
                            </td>
                        </tr>
                        <tr v-if="!providers.length">
                            <td colspan="6" class="px-4 py-10 text-center text-sm text-gray-500">
                                Sin proveedores. <button @click="openCreate" class="font-semibold text-orange-700 hover:underline">Agregar el primero</button>.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ProveedorFormModal
            :open="formOpen"
            :provider="editing"
            :types="types"
            @close="formOpen = false"
        />
    </EmpresaLayout>
</template>
