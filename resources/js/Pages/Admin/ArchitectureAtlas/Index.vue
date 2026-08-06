<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ArchitectureExplorer from '@/Features/Architecture/components/ArchitectureExplorer.vue';

defineProps({
    manifest: {
        type: Object,
        required: true,
        validator: (value) => (
            Array.isArray(value.applications)
            && Array.isArray(value.modules)
            && Array.isArray(value.connections)
        ),
    },
});
</script>

<template>
    <Head title="Atlas del sistema" />

    <AdminLayout>
        <template #header>
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-red-600">Arquitectura</p>
                <h1 class="text-xl font-bold text-gray-900">Atlas del sistema</h1>
            </div>
        </template>

        <ArchitectureExplorer :manifest="manifest" />
    </AdminLayout>
</template>
