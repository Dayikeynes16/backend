<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);

const data = ref({ due_reminders: [], overdue: [], alerts: [], counts: { total: 0 } });
const open = ref(false);
const loading = ref(false);

/**
 * Una carga al montar y otra por cada apertura o acción del dropdown. No hay
 * polling — `agenda.notificaciones` recalcula alertas financieras caras
 * (cuentas por pagar + fiados vía CollectionMetrics) y un temporizador global
 * multiplicaba ese costo por cada pestaña abierta.
 *
 * El fetch al montar se conserva a propósito: sin él el badge arranca en 0 y
 * quien no abre la campana no se entera de que tiene avisos. Como el layout
 * sobrevive a la navegación de Inertia, es un request por carga de la app, no
 * uno por visita.
 */
const load = async () => {
    if (!slug.value) return;
    loading.value = true;
    try {
        const res = await fetch(route('agenda.notificaciones', slug.value), { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        data.value = await res.json();
    } catch (e) {
        /* silencioso: degradación elegante */
    } finally {
        loading.value = false;
    }
};

const total = computed(() => data.value.counts?.total ?? 0);

const complete = (id) => router.patch(route('agenda.complete', [slug.value, id]), {}, { preserveScroll: true, onSuccess: load });
const snooze = (id, minutes) => router.patch(route('agenda.snooze', [slug.value, id]), { minutes }, { preserveScroll: true, onSuccess: load });
const markSeen = (id) => router.patch(route('agenda.visto', [slug.value, id]), {}, { preserveScroll: true, onSuccess: load });

const toggle = () => {
    open.value = !open.value;
    if (open.value) {
        load();
    }
};

onMounted(load);
</script>

<template>
    <div class="relative">
        <button type="button" @click="toggle" class="relative rounded-lg p-2 text-gray-500 hover:bg-gray-100" title="Avisos de agenda">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
            <span v-if="total" class="absolute -right-0.5 -top-0.5 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white">{{ total > 9 ? '9+' : total }}</span>
        </button>

        <!-- Dropdown -->
        <div v-if="open" class="absolute right-0 z-50 mt-2 w-80 rounded-2xl bg-white p-2 shadow-xl ring-1 ring-gray-100">
            <div class="flex items-center justify-between px-2 py-1.5">
                <span class="text-sm font-bold text-gray-900">Avisos</span>
                <Link :href="route('agenda.index', slug)" class="text-xs font-semibold text-red-600 hover:underline" @click="open = false">Ver agenda →</Link>
            </div>
            <p v-if="loading" class="px-2 py-4 text-center text-sm text-gray-400">Cargando avisos…</p>
            <p v-else-if="!total" class="px-2 py-4 text-center text-sm text-gray-400">Sin avisos.</p>
            <div class="max-h-80 overflow-y-auto">
                <div v-for="d in data.due_reminders" :key="'d' + d.id" class="flex items-center gap-2 rounded-lg px-2 py-2 hover:bg-gray-50">
                    <span class="h-2 w-2 shrink-0 rounded-full bg-violet-500"></span>
                    <span class="min-w-0 flex-1 truncate text-sm text-gray-800">{{ d.title }}</span>
                    <button @click="complete(d.id)" class="text-xs font-semibold text-green-600">Hecho</button>
                    <button @click="snooze(d.id, 30)" class="text-xs font-semibold text-gray-500">+30m</button>
                    <button @click="markSeen(d.id)" class="text-xs font-semibold text-gray-400 hover:text-gray-600" title="Quitar el aviso sin completar la tarea">Visto</button>
                </div>
                <div v-for="o in data.overdue" :key="'o' + o.id" class="flex items-center gap-2 rounded-lg px-2 py-2 hover:bg-gray-50">
                    <span class="h-2 w-2 shrink-0 rounded-full bg-red-500"></span>
                    <span class="min-w-0 flex-1 truncate text-sm text-gray-800">{{ o.title }} <em class="text-[10px] text-red-500">atrasada</em></span>
                    <button @click="complete(o.id)" class="text-xs font-semibold text-green-600">Hecho</button>
                </div>
                <div v-for="a in data.alerts" :key="a.key" class="flex items-center gap-2 rounded-lg px-2 py-2 hover:bg-gray-50">
                    <span :class="['h-2 w-2 shrink-0 rounded-full', a.severity === 'high' ? 'bg-red-500' : 'bg-amber-400']"></span>
                    <span class="min-w-0 flex-1 truncate text-sm text-gray-700">{{ a.title }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
