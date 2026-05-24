<script setup>
import { Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

const props = defineProps({ tenantSlug: { type: String, required: true } });
const alerts = ref([]);
const loading = ref(true);

onMounted(async () => {
    try {
        const res = await fetch(route('agenda.alerts', props.tenantSlug), { headers: { Accept: 'application/json' } });
        const data = await res.json();
        alerts.value = (data.alerts ?? []).slice(0, 3);
    } catch (e) {
        alerts.value = [];
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-bold text-gray-900">Agenda — alertas</h3>
            <Link :href="route('agenda.index', tenantSlug)" class="text-xs font-semibold text-red-600 hover:underline">Ver todo →</Link>
        </div>
        <div v-if="loading" class="space-y-2">
            <div v-for="n in 2" :key="n" class="h-4 animate-pulse rounded bg-gray-100"></div>
        </div>
        <template v-else>
            <p v-if="!alerts.length" class="text-sm text-gray-400">Sin alertas. Todo al corriente.</p>
            <div v-for="a in alerts" :key="a.key" class="flex items-center gap-2 py-1.5">
                <span :class="['h-2 w-2 shrink-0 rounded-full', a.severity === 'high' ? 'bg-red-500' : 'bg-amber-400']"></span>
                <span class="truncate text-sm text-gray-700">{{ a.title }}</span>
            </div>
        </template>
    </div>
</template>
