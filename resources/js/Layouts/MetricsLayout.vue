<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import MetricsSubSidebar from '@/Components/Metrics/MetricsSubSidebar.vue';
import MetricsBreadcrumb from '@/Components/Metrics/MetricsBreadcrumb.vue';

defineProps({
    activeAxis: { type: String, default: null },
    axisLabel: { type: String, default: null },
    headerTitle: { type: String, default: 'Métricas' },
});
</script>

<template>
    <SucursalLayout>
        <template #header>
            <h2 class="text-lg font-bold text-gray-900">{{ headerTitle }}</h2>
        </template>

        <div class="flex flex-col gap-6 lg:flex-row lg:items-start">
            <MetricsSubSidebar :active-axis="activeAxis" />

            <div class="min-w-0 flex-1">
                <MetricsBreadcrumb :axis-label="axisLabel" />
                <slot />
            </div>
        </div>
    </SucursalLayout>
</template>
