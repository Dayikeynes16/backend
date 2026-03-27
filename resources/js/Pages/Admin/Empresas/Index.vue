<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({ empresas: Object, stats: Object, filters: Object });

const search = ref(props.filters?.search || '');
let debounce;
watch(search, (v) => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(route('admin.empresas.index'), { search: v || undefined }, { preserveState: true, replace: true });
    }, 300);
});

const usage = (current, max) => {
    const pct = max > 0 ? Math.min((current / max) * 100, 100) : 0;
    return { current, max, pct };
};
const barColor = (pct) => pct >= 90 ? 'bg-red-500' : pct >= 60 ? 'bg-orange-400' : 'bg-green-500';
</script>

<template>
    <Head title="Empresas" />
    <AdminLayout>
        <template #header>
            <h1 class="text-lg font-bold text-gray-900">Empresas</h1>
        </template>

        <div class="space-y-6">
            <!-- Stats -->
            <div class="grid grid-cols-3 gap-4">
                <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Total</p>
                    <p class="mt-1 text-3xl font-bold text-gray-900">{{ stats.total }}</p>
                </div>
                <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-green-600">Activas</p>
                    <p class="mt-1 text-3xl font-bold text-green-700">{{ stats.active }}</p>
                </div>
                <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-red-500">Inactivas</p>
                    <p class="mt-1 text-3xl font-bold text-red-600">{{ stats.inactive }}</p>
                </div>
            </div>

            <!-- Table card -->
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="flex flex-col gap-3 border-b border-gray-100 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                        <input v-model="search" type="text" placeholder="Buscar empresa..." class="w-full rounded-lg border-gray-200 py-2 pl-10 pr-4 text-sm text-gray-700 placeholder-gray-400 focus:border-red-300 focus:ring-red-200 sm:w-64" />
                    </div>
                    <Link :href="route('admin.empresas.create')" class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-red-500">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        Nueva Empresa
                    </Link>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-50">
                        <thead><tr class="bg-gray-50/60">
                            <th class="px-6 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-400">Empresa</th>
                            <th class="px-6 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-400">RFC</th>
                            <th class="px-6 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-400">Sucursales</th>
                            <th class="px-6 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-400">Usuarios</th>
                            <th class="px-6 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-400">Estado</th>
                            <th class="px-6 py-2.5"></th>
                        </tr></thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="e in empresas.data" :key="e.id" class="transition hover:bg-gray-50/50">
                                <td class="px-6 py-3.5">
                                    <p class="text-sm font-semibold text-gray-900">{{ e.name }}</p>
                                    <p class="text-[11px] text-gray-400">/{{ e.slug }}</p>
                                </td>
                                <td class="px-6 py-3.5 text-sm text-gray-500">{{ e.rfc || '—' }}</td>
                                <td class="px-6 py-3.5">
                                    <div class="w-28">
                                        <div class="flex items-center justify-between text-[11px]">
                                            <span class="font-medium text-gray-700">{{ usage(e.branches_count, e.max_branches).current }}/{{ usage(e.branches_count, e.max_branches).max }}</span>
                                            <span class="text-gray-400">{{ Math.round(usage(e.branches_count, e.max_branches).pct) }}%</span>
                                        </div>
                                        <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
                                            <div class="h-full rounded-full transition-all" :class="barColor(usage(e.branches_count, e.max_branches).pct)" :style="{ width: usage(e.branches_count, e.max_branches).pct + '%' }" />
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-3.5">
                                    <div class="w-28">
                                        <div class="flex items-center justify-between text-[11px]">
                                            <span class="font-medium text-gray-700">{{ usage(e.users_count, e.max_users).current }}/{{ usage(e.users_count, e.max_users).max }}</span>
                                            <span class="text-gray-400">{{ Math.round(usage(e.users_count, e.max_users).pct) }}%</span>
                                        </div>
                                        <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
                                            <div class="h-full rounded-full transition-all" :class="barColor(usage(e.users_count, e.max_users).pct)" :style="{ width: usage(e.users_count, e.max_users).pct + '%' }" />
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-3.5">
                                    <span :class="e.status === 'active' ? 'bg-green-50 text-green-700 ring-green-600/20' : 'bg-red-50 text-red-700 ring-red-600/20'" class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset">{{ e.status === 'active' ? 'Activa' : 'Inactiva' }}</span>
                                </td>
                                <td class="px-6 py-3.5 text-right"><Link :href="route('admin.empresas.edit', e.id)" class="text-xs font-semibold text-red-600 hover:text-red-500">Editar</Link></td>
                            </tr>
                            <tr v-if="empresas.data.length === 0"><td colspan="6" class="px-6 py-12 text-center text-sm text-gray-400">No se encontraron empresas.</td></tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="empresas.last_page > 1" class="flex justify-center border-t border-gray-100 px-6 py-3">
                    <div class="flex gap-1">
                        <Link v-for="link in empresas.links" :key="link.label" :href="link.url || '#'"
                            :class="['rounded-md px-3 py-1.5 text-xs font-medium transition', link.active ? 'bg-red-600 text-white' : 'text-gray-600 hover:bg-gray-100', !link.url && 'pointer-events-none opacity-40']"
                            v-html="link.label" />
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
