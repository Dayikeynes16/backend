<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import DatePicker from '@/Components/DatePicker.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({ shifts: Object, filters: Object, tenant: Object, isAdmin: Boolean });

const date = ref(props.filters?.date || '');
watch(date, (v) => {
    router.get(route('sucursal.cortes.index', props.tenant.slug), { date: v || undefined }, { preserveState: true, replace: true });
});

const formatDT = (iso) => iso ? new Date(iso).toLocaleString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
</script>

<template>
    <Head title="Historial de Cortes" />
    <SucursalLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Historial de Cortes</h1>
        </template>

        <div class="space-y-6">
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <DatePicker v-model="date" :allow-future="false" />
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead><tr class="bg-gray-50">
                            <th v-if="isAdmin" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Cajero</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Apertura</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Cierre</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Cobros</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Total</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Diferencia</th>
                            <th class="px-6 py-3"></th>
                        </tr></thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="s in shifts.data" :key="s.id" class="transition hover:bg-gray-50">
                                <td v-if="isAdmin" class="px-6 py-4 text-sm font-medium text-gray-900">{{ s.user?.name || '—' }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ formatDT(s.opened_at) }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ formatDT(s.closed_at) }}</td>
                                <td class="px-6 py-4 text-right text-sm text-gray-600">{{ s.sale_count }}</td>
                                <td class="px-6 py-4 text-right text-sm font-semibold text-gray-900">${{ Number(s.total_sales).toFixed(2) }}</td>
                                <td class="px-6 py-4 text-right text-sm font-semibold" :class="Number(s.difference) > 0 ? 'text-green-600' : Number(s.difference) < 0 ? 'text-red-600' : 'text-gray-400'">
                                    {{ Number(s.difference) > 0 ? '+' : '' }}${{ Number(s.difference).toFixed(2) }}
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <Link :href="route('sucursal.cortes.show', [tenant.slug, s.id])" class="text-sm font-semibold text-red-600 hover:text-red-700">Ver detalle</Link>
                                </td>
                            </tr>
                            <tr v-if="shifts.data.length === 0"><td :colspan="isAdmin ? 7 : 6" class="px-6 py-16 text-center text-sm text-gray-400">No hay cortes registrados.</td></tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="shifts.last_page > 1" class="flex justify-center border-t border-gray-100 px-6 py-4">
                    <div class="flex gap-1">
                        <Link v-for="link in shifts.links" :key="link.label" :href="link.url || '#'"
                            :class="['rounded-lg px-3.5 py-2 text-sm font-medium transition', link.active ? 'bg-red-600 text-white' : 'text-gray-600 hover:bg-gray-100', !link.url && 'pointer-events-none opacity-40']"
                            v-html="link.label" />
                    </div>
                </div>
            </div>
        </div>
    </SucursalLayout>
</template>
