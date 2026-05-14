<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import FlashToast from '@/Components/FlashToast.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({ usuarios: Object, filters: Object, tenant: Object });

const search = ref(props.filters?.search || '');

let debounceTimer;
watch(search, (value) => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        router.get(
            route('sucursal.usuarios.index', props.tenant.slug),
            { search: value || undefined },
            { preserveState: true, replace: true, only: ['usuarios', 'filters'] }
        );
    }, 250);
});

const formatRelative = (iso) => {
    if (!iso) return '—';
    const d = new Date(iso);
    return d.toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' });
};

const initialOf = (name) => (name || '?').trim().charAt(0).toUpperCase();

const hasResults = computed(() => (props.usuarios?.data?.length ?? 0) > 0);
const totalLabel = computed(() => {
    const total = props.usuarios?.total ?? 0;
    return total === 1 ? '1 cajero' : `${total} cajeros`;
});

// Eliminación con confirmación
const confirmDeleteId = ref(null);
const confirmDeleteName = ref('');
const askDelete = (u) => { confirmDeleteId.value = u.id; confirmDeleteName.value = u.name; };
const doDelete = () => {
    router.delete(route('sucursal.usuarios.destroy', [props.tenant.slug, confirmDeleteId.value]), {
        preserveScroll: true,
        onFinish: () => { confirmDeleteId.value = null; confirmDeleteName.value = ''; },
    });
};
</script>

<template>
    <Head title="Cajeros" />
    <SucursalLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-gray-900">Cajeros</h1>
                    <p class="mt-0.5 text-xs text-gray-500">Equipo que opera caja en esta sucursal.</p>
                </div>
                <Link :href="route('sucursal.usuarios.create', tenant.slug)"
                    class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 active:scale-[.98]">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Nuevo cajero
                </Link>
            </div>
        </template>

        <div class="space-y-5">
            <!-- Card principal: buscador + tabla -->
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <!-- Toolbar -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
                    <div class="relative w-full sm:w-80">
                        <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                        </svg>
                        <input v-model="search" type="text" placeholder="Buscar por nombre..."
                            class="block w-full rounded-xl border-gray-200 bg-white py-2.5 pl-10 pr-3 text-sm text-gray-700 placeholder-gray-400 shadow-sm focus:border-red-400 focus:ring-red-300" />
                    </div>
                    <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">{{ totalLabel }}</span>
                </div>

                <!-- Tabla -->
                <div v-if="hasResults" class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50/50">
                            <tr>
                                <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Cajero</th>
                                <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Email</th>
                                <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Alta</th>
                                <th class="px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-gray-500">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="u in usuarios.data" :key="u.id" class="transition hover:bg-gray-50/60">
                                <td class="whitespace-nowrap px-5 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-orange-400 to-red-500 text-sm font-bold text-white shadow-sm">
                                            {{ initialOf(u.name) }}
                                        </div>
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-gray-900">{{ u.name }}</p>
                                            <span class="mt-0.5 inline-flex rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
                                                Cajero
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3.5">
                                    <span class="text-sm text-gray-600">{{ u.email }}</span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3.5">
                                    <span class="text-sm text-gray-500">{{ formatRelative(u.created_at) }}</span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right">
                                    <div class="inline-flex items-center gap-1">
                                        <Link :href="route('sucursal.usuarios.edit', [tenant.slug, u.id])"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-orange-50 hover:text-orange-600"
                                            title="Editar">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                            </svg>
                                        </Link>
                                        <button type="button" @click="askDelete(u)"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-red-50 hover:text-red-600"
                                            title="Eliminar">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Empty state -->
                <div v-else class="flex flex-col items-center justify-center px-6 py-16 text-center">
                    <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-100 to-red-100 text-red-600">
                        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                        </svg>
                    </div>
                    <p class="mt-4 text-sm font-semibold text-gray-700">
                        {{ search ? 'No encontramos cajeros con ese nombre.' : 'Aún no hay cajeros en esta sucursal.' }}
                    </p>
                    <p class="mt-1 text-xs text-gray-400">
                        {{ search ? 'Prueba con otra búsqueda o limpia el filtro.' : 'Crea el primero para empezar a operar caja.' }}
                    </p>
                    <Link v-if="!search" :href="route('sucursal.usuarios.create', tenant.slug)"
                        class="mt-4 inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-red-700">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        Crear el primero
                    </Link>
                </div>

                <!-- Paginación: solo si hay más de una página -->
                <div v-if="hasResults && (usuarios.last_page ?? 1) > 1"
                    class="flex items-center justify-between border-t border-gray-100 bg-gray-50/40 px-5 py-3 text-xs text-gray-600">
                    <span>
                        Mostrando <b>{{ usuarios.from }}</b>–<b>{{ usuarios.to }}</b> de <b>{{ usuarios.total }}</b>
                    </span>
                    <div class="flex gap-1.5">
                        <Link v-if="usuarios.prev_page_url" :href="usuarios.prev_page_url" preserve-scroll
                            class="rounded-lg bg-white px-3 py-1.5 font-semibold text-gray-700 ring-1 ring-gray-200 transition hover:bg-gray-100">← Anterior</Link>
                        <span v-else class="rounded-lg bg-white px-3 py-1.5 font-semibold text-gray-300 ring-1 ring-gray-200">← Anterior</span>
                        <Link v-if="usuarios.next_page_url" :href="usuarios.next_page_url" preserve-scroll
                            class="rounded-lg bg-white px-3 py-1.5 font-semibold text-gray-700 ring-1 ring-gray-200 transition hover:bg-gray-100">Siguiente →</Link>
                        <span v-else class="rounded-lg bg-white px-3 py-1.5 font-semibold text-gray-300 ring-1 ring-gray-200">Siguiente →</span>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmDialog v-if="confirmDeleteId"
            title="Eliminar cajero"
            :message="`Vas a eliminar a ${confirmDeleteName}. Esta acción no se puede deshacer.`"
            confirm-label="Eliminar"
            variant="danger"
            @confirm="doDelete"
            @cancel="confirmDeleteId = null" />

        <FlashToast />
    </SucursalLayout>
</template>
