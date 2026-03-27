<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({ sucursales: Object, filters: Object, tenant: Object });

const search = ref(props.filters?.search || '');
let debounce;
watch(search, (v) => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(route('empresa.sucursales.index', props.tenant.slug), { search: v || undefined }, { preserveState: true, replace: true });
    }, 300);
});
</script>

<template>
    <Head title="Sucursales" />
    <EmpresaLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Sucursales</h1>
        </template>

        <div class="space-y-6">
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="flex flex-col gap-3 border-b border-gray-100 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                        <input v-model="search" type="text" placeholder="Buscar sucursal..." class="w-full rounded-lg border-gray-200 py-2.5 pl-10 pr-4 text-sm text-gray-700 placeholder-gray-400 focus:border-red-400 focus:ring-red-300 sm:w-72" />
                    </div>
                    <Link :href="route('empresa.sucursales.create', tenant.slug)" class="inline-flex items-center justify-center gap-2 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        Nueva Sucursal
                    </Link>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead><tr class="bg-gray-50">
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Nombre</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Direccion</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Telefono</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Horario</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Usuarios</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Estado</th>
                            <th class="px-6 py-3"></th>
                        </tr></thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="s in sucursales.data" :key="s.id" class="transition hover:bg-gray-50">
                                <td class="px-6 py-4 text-sm font-semibold text-gray-900">{{ s.name }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ s.address || '—' }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ s.phone || '—' }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ s.schedule || '—' }}</td>
                                <td class="px-6 py-4 text-right text-sm text-gray-600">{{ s.users_count }}</td>
                                <td class="px-6 py-4">
                                    <span :class="s.status === 'active' ? 'bg-green-50 text-green-700 ring-green-600/20' : 'bg-red-50 text-red-700 ring-red-600/20'" class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset">{{ s.status === 'active' ? 'Activa' : 'Inactiva' }}</span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <Link :href="route('empresa.sucursales.edit', [tenant.slug, s.id])" class="text-sm font-semibold text-red-600 transition hover:text-red-700">Editar</Link>
                                </td>
                            </tr>
                            <tr v-if="sucursales.data.length === 0"><td colspan="7" class="px-6 py-16 text-center text-sm text-gray-400">No se encontraron sucursales.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <FlashToast />
    </EmpresaLayout>
</template>
