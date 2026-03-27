<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    empresas: Object,
    stats: Object,
    filters: Object,
});

const search = ref(props.filters?.search || '');

let debounce;
watch(search, (value) => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(route('admin.empresas.index'), { search: value || undefined }, {
            preserveState: true,
            replace: true,
        });
    }, 300);
});

const branchUsage = (empresa) => {
    const current = empresa.branches_count || 0;
    const max = empresa.max_branches || 1;
    const pct = Math.min((current / max) * 100, 100);
    return { current, max, pct };
};
</script>

<template>
    <Head title="Empresas" />
    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Empresas</h1>
        </template>

        <div class="space-y-6">
            <!-- Stats -->
            <div class="grid grid-cols-3 gap-4">
                <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                    <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Total</p>
                    <p class="mt-1 text-3xl font-bold text-gray-900">{{ stats.total }}</p>
                </div>
                <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                    <p class="text-xs font-medium uppercase tracking-wider text-green-600">Activas</p>
                    <p class="mt-1 text-3xl font-bold text-green-700">{{ stats.active }}</p>
                </div>
                <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                    <p class="text-xs font-medium uppercase tracking-wider text-red-500">Inactivas</p>
                    <p class="mt-1 text-3xl font-bold text-red-600">{{ stats.inactive }}</p>
                </div>
            </div>

            <!-- Table -->
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <!-- Toolbar -->
                <div class="flex flex-col gap-3 border-b border-gray-100 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                        </svg>
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Buscar empresa..."
                            class="w-full rounded-lg border-gray-200 py-2 pl-10 pr-4 text-sm text-gray-700 placeholder-gray-400 focus:border-red-300 focus:ring-red-200 sm:w-72"
                        />
                    </div>
                    <Link :href="route('admin.empresas.create')" class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-red-500 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Nueva Empresa
                    </Link>
                </div>

                <!-- Table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead>
                            <tr class="bg-gray-50/50">
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Empresa</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">RFC</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Sucursales</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Usuarios</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Estado</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="empresa in empresas.data" :key="empresa.id" class="transition hover:bg-gray-50/50">
                                <td class="px-6 py-4">
                                    <div>
                                        <p class="text-sm font-semibold text-gray-900">{{ empresa.name }}</p>
                                        <p class="text-xs text-gray-400">/{{ empresa.slug }}</p>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ empresa.rfc || '—' }}</td>
                                <td class="px-6 py-4">
                                    <div class="w-32">
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="font-medium text-gray-700">{{ branchUsage(empresa).current }} / {{ branchUsage(empresa).max }}</span>
                                            <span class="text-gray-400">{{ Math.round(branchUsage(empresa).pct) }}%</span>
                                        </div>
                                        <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
                                            <div
                                                class="h-full rounded-full transition-all duration-300"
                                                :class="branchUsage(empresa).pct >= 90 ? 'bg-red-500' : branchUsage(empresa).pct >= 60 ? 'bg-orange-400' : 'bg-green-500'"
                                                :style="{ width: branchUsage(empresa).pct + '%' }"
                                            />
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-right text-sm text-gray-600">{{ empresa.users_count || 0 }}</td>
                                <td class="px-6 py-4">
                                    <span :class="empresa.status === 'active' ? 'bg-green-50 text-green-700 ring-green-600/20' : 'bg-red-50 text-red-700 ring-red-600/20'"
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset">
                                        {{ empresa.status === 'active' ? 'Activa' : 'Inactiva' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <Link :href="route('admin.empresas.edit', empresa.id)" class="text-sm font-medium text-red-600 hover:text-red-500">
                                        Editar
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="empresas.data.length === 0">
                                <td colspan="6" class="px-6 py-12 text-center text-sm text-gray-400">
                                    No se encontraron empresas.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="empresas.last_page > 1" class="flex justify-center border-t border-gray-100 px-6 py-4">
                    <div class="flex gap-1">
                        <Link
                            v-for="link in empresas.links"
                            :key="link.label"
                            :href="link.url || '#'"
                            :class="[
                                'rounded-md px-3 py-1.5 text-sm font-medium transition',
                                link.active
                                    ? 'bg-red-600 text-white'
                                    : 'text-gray-600 hover:bg-gray-100',
                                !link.url && 'pointer-events-none opacity-40',
                            ]"
                            v-html="link.label"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
