<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);

const data = ref({ due_reminders: [], overdue: [], alerts: [], counts: { total: 0 } });
const open = ref(false);
const seenIds = ref(new Set());
const toast = ref(null);
let timer = null;

const poll = async () => {
    if (!slug.value) return;
    try {
        const res = await fetch(route('agenda.notificaciones', slug.value), { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const json = await res.json();
        data.value = json;
        // Toast para due reminders nuevos en esta sesión.
        const fresh = (json.due_reminders ?? []).find((d) => !seenIds.value.has(d.id));
        if (fresh && !open.value) {
            toast.value = fresh;
        }
    } catch (e) {
        /* silencioso: degradación elegante */
    }
};

const total = computed(() => data.value.counts?.total ?? 0);

const complete = (id) => router.patch(route('agenda.complete', [slug.value, id]), {}, { preserveScroll: true, onSuccess: poll });
const snooze = (id, minutes) => router.patch(route('agenda.snooze', [slug.value, id]), { minutes }, { preserveScroll: true, onSuccess: poll });
const markSeen = (id) => {
    seenIds.value.add(id);
    router.patch(route('agenda.visto', [slug.value, id]), {}, { preserveScroll: true, onSuccess: poll });
};
const dismissToast = () => {
    if (toast.value) {
        markSeen(toast.value.id);
        toast.value = null;
    }
};
const toggle = () => {
    open.value = !open.value;
    if (open.value) {
        (data.value.due_reminders ?? []).forEach((d) => seenIds.value.add(d.id));
    }
};

onMounted(() => {
    poll();
    timer = setInterval(poll, 60000);
});
onBeforeUnmount(() => clearInterval(timer));
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
            <p v-if="!total" class="px-2 py-4 text-center text-sm text-gray-400">Sin avisos.</p>
            <div class="max-h-80 overflow-y-auto">
                <div v-for="d in data.due_reminders" :key="'d' + d.id" class="flex items-center gap-2 rounded-lg px-2 py-2 hover:bg-gray-50">
                    <span class="h-2 w-2 shrink-0 rounded-full bg-violet-500"></span>
                    <span class="min-w-0 flex-1 truncate text-sm text-gray-800">{{ d.title }}</span>
                    <button @click="complete(d.id)" class="text-xs font-semibold text-green-600">Hecho</button>
                    <button @click="snooze(d.id, 30)" class="text-xs font-semibold text-gray-500">+30m</button>
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

        <!-- Toast -->
        <Teleport to="body">
            <div v-if="toast" class="fixed bottom-4 right-4 z-[60] w-72 rounded-2xl bg-gray-900 p-4 text-white shadow-2xl">
                <p class="text-xs font-bold uppercase tracking-wide text-violet-300">Recordatorio</p>
                <p class="mt-1 text-sm font-semibold">{{ toast.title }}</p>
                <div class="mt-3 flex gap-2">
                    <button @click="complete(toast.id); dismissToast()" class="rounded-lg bg-green-600 px-3 py-1 text-xs font-bold">Hecho</button>
                    <button @click="snooze(toast.id, 30); dismissToast()" class="rounded-lg bg-white/10 px-3 py-1 text-xs font-bold">+30m</button>
                    <button @click="dismissToast" class="ml-auto text-xs text-gray-400 hover:text-white">Cerrar</button>
                </div>
            </div>
        </Teleport>
    </div>
</template>
