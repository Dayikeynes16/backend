<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import ResetPasswordModal from '@/Components/ResetPasswordModal.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({ usuarios: Object, sucursales: Array, filters: Object, tenant: Object });

const search = ref(props.filters?.search || '');
let debounce;
watch(search, (v) => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(route('empresa.usuarios.index', props.tenant.slug), { search: v || undefined }, { preserveState: true, replace: true });
    }, 300);
});

const roleName = (user) => {
    const role = user.roles?.[0]?.name;
    return { 'admin-sucursal': 'Admin Sucursal', 'cajero': 'Cajero', 'admin-empresa': 'Admin Empresa' }[role] || role;
};

const resetModal = ref(false);
const resetUser = ref(null);
const openResetModal = (user) => { resetUser.value = user; resetModal.value = true; };
</script>

<template>
    <Head title="Usuarios" />
    <EmpresaLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Usuarios</h1>
        </template>

        <div class="space-y-6">
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="flex flex-col gap-3 border-b border-gray-100 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                        <input v-model="search" type="text" placeholder="Buscar usuario..." class="w-full rounded-lg border-gray-200 py-2.5 pl-10 pr-4 text-sm text-gray-700 placeholder-gray-400 focus:border-red-400 focus:ring-red-300 sm:w-72" />
                    </div>
                    <Link :href="route('empresa.usuarios.create', tenant.slug)" class="inline-flex items-center justify-center gap-2 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        Nuevo Usuario
                    </Link>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead><tr class="bg-gray-50">
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Nombre</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Rol</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Sucursal</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Acciones</th>
                        </tr></thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="u in usuarios.data" :key="u.id" class="transition hover:bg-gray-50">
                                <td class="px-6 py-4 text-sm font-semibold text-gray-900">{{ u.name }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ u.email }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ roleName(u) }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ u.branch?.name || '—' }}</td>
                                <td class="px-6 py-4 text-right text-sm space-x-3">
                                    <button @click="openResetModal(u)" class="font-semibold text-orange-600 transition hover:text-orange-700">Resetear</button>
                                    <Link :href="route('empresa.usuarios.edit', [tenant.slug, u.id])" class="font-semibold text-red-600 transition hover:text-red-700">Editar</Link>
                                </td>
                            </tr>
                            <tr v-if="usuarios.data.length === 0"><td colspan="5" class="px-6 py-16 text-center text-sm text-gray-400">No se encontraron usuarios.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <ResetPasswordModal :show="resetModal" :user="resetUser"
            :send-reset-route="resetUser ? route('empresa.usuarios.send-reset', [tenant.slug, resetUser.id]) : ''"
            :force-reset-route="resetUser ? route('empresa.usuarios.force-reset', [tenant.slug, resetUser.id]) : ''"
            @close="resetModal = false" />

        <FlashToast />
    </EmpresaLayout>
</template>
