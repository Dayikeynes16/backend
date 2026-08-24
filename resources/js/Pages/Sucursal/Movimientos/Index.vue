<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import MovimientosPanel from '@/Components/Movimientos/MovimientosPanel.vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    sales: Object, summary: Object, branches: Array, users: Array,
    filters: Object, eventLabels: Object, tenant: Object, scope: String,
});
</script>

<template>
    <Head title="Movimientos" />
    <SucursalLayout>
        <template #header>
            <div>
                <h1 class="text-xl font-bold text-gray-900">Movimientos</h1>
                <p class="mt-0.5 text-sm text-gray-500">Qué se le hizo a las ventas después de cobrarlas</p>
            </div>
        </template>

        <MovimientosPanel
            :sales="props.sales"
            :summary="props.summary"
            :branches="props.branches"
            :users="props.users"
            :filters="props.filters"
            :event-labels="props.eventLabels"
            scope="sucursal"
            route-name="sucursal.movimientos.index"
            :tenant-slug="props.tenant.slug" />
    </SucursalLayout>
</template>
