<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({
    sucursales: Object,
    filters: Object,
    stats: Object,
    tenant: Object,
});

const search = ref(props.filters?.search || '');
const filter = ref(props.filters?.filter || 'all');

let debounceTimer;
const navigate = () => {
    router.get(route('empresa.sucursales.index', props.tenant.slug), {
        search: search.value || undefined,
        filter: (filter.value && filter.value !== 'all') ? filter.value : undefined,
    }, { preserveState: true, replace: true });
};

watch(search, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => navigate(), 300);
});

const setFilter = (key) => { filter.value = key; navigate(); };

const DAY_LABELS = { mon: 'Lun', tue: 'Mar', wed: 'Mié', thu: 'Jue', fri: 'Vie', sat: 'Sáb', sun: 'Dom' };
const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

const summarizeHours = (hours) => {
    if (!hours) return null;
    const perDay = {};
    for (const d of DAY_KEYS) {
        const v = hours[d];
        perDay[d] = (!v || !v.open || !v.close) ? 'cerrado' : `${v.open}-${v.close}`;
    }
    const groups = [];
    let startIdx = 0;
    for (let i = 1; i <= DAY_KEYS.length; i++) {
        const current = perDay[DAY_KEYS[startIdx]];
        const next = i < DAY_KEYS.length ? perDay[DAY_KEYS[i]] : null;
        if (current !== next) {
            const range = startIdx === i - 1 ? DAY_LABELS[DAY_KEYS[startIdx]] : `${DAY_LABELS[DAY_KEYS[startIdx]]}-${DAY_LABELS[DAY_KEYS[i - 1]]}`;
            groups.push(`${range} ${current}`);
            startIdx = i;
        }
    }
    return groups.join(', ');
};

const hasLocation = (s) => s.latitude != null && s.longitude != null;

const avatarColors = ['from-rose-500 to-red-600','from-orange-500 to-amber-600','from-amber-400 to-yellow-500','from-emerald-500 to-teal-600','from-sky-500 to-blue-600','from-indigo-500 to-violet-600','from-purple-500 to-fuchsia-600','from-pink-500 to-rose-500'];
const avatarFor = (name) => {
    let hash = 0;
    const s = String(name || '');
    for (let i = 0; i < s.length; i++) { hash = ((hash << 5) - hash) + s.charCodeAt(i); hash |= 0; }
    return avatarColors[Math.abs(hash) % avatarColors.length];
};
const initialFor = (name) => {
    const w = String(name || '?').trim().split(/\s+/).filter(Boolean);
    if (w.length === 0) return '?';
    if (w.length === 1) return w[0].slice(0, 2).toUpperCase();
    return (w[0][0] + w[1][0]).toUpperCase();
};

const filterChips = computed(() => [
    { key: 'all', label: 'Todas', count: props.stats?.total ?? 0 },
    { key: 'active', label: 'Activas', count: props.stats?.active ?? 0 },
    { key: 'online', label: 'Con menú online', count: props.stats?.online ?? 0 },
    { key: 'no_location', label: 'Sin ubicación', count: props.stats?.no_location ?? 0 },
]);

const isFilterActive = (key) => (filter.value || 'all') === key;
</script>

<template>
    <Head title="Sucursales" />
    <EmpresaLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Sucursales</h1>
        </template>

        <div class="space-y-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="relative">
                    <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                    <input v-model="search" type="text" placeholder="Buscar sucursal..." class="w-full rounded-xl border-gray-200 bg-white py-2.5 pl-10 pr-4 text-sm text-gray-700 placeholder-gray-400 focus:border-red-400 focus:ring-red-300 sm:w-80" />
                </div>
                <Link :href="route('empresa.sucursales.create', tenant.slug)"
                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700 active:scale-95">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Nueva sucursal
                </Link>
            </div>

            <div class="-mx-1 flex items-center gap-2 overflow-x-auto px-1 pb-1">
                <button v-for="c in filterChips" :key="c.key" type="button" @click="setFilter(c.key)"
                    :class="['inline-flex shrink-0 items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-semibold transition active:scale-95',
                        isFilterActive(c.key) ? 'bg-gray-900 text-white shadow-sm' : 'bg-white text-gray-600 ring-1 ring-gray-200 hover:bg-gray-50']">
                    {{ c.label }}
                    <span :class="['rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums', isFilterActive(c.key) ? 'bg-white/20' : 'bg-gray-100 text-gray-500']">
                        {{ c.count }}
                    </span>
                </button>
            </div>

            <div v-if="sucursales.data.length > 0" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <Link v-for="s in sucursales.data" :key="s.id"
                    :href="route('empresa.sucursales.show', [tenant.slug, s.id])"
                    class="group relative flex flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100 transition hover:shadow-md hover:ring-gray-200">
                    <div class="flex items-start gap-3 border-b border-gray-100 p-5">
                        <div :class="['flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br text-base font-bold text-white shadow-sm ring-2 ring-white', avatarFor(s.name)]">
                            {{ initialFor(s.name) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <h2 class="truncate text-base font-bold text-gray-900">{{ s.name }}</h2>
                                <span :class="['inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider ring-1 ring-inset',
                                    s.status === 'active' ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-gray-100 text-gray-500 ring-gray-200']">
                                    <span :class="['h-1 w-1 rounded-full', s.status === 'active' ? 'bg-emerald-500' : 'bg-gray-400']" />
                                    {{ s.status === 'active' ? 'Activa' : 'Inactiva' }}
                                </span>
                            </div>
                            <p v-if="s.address" class="mt-0.5 truncate text-xs text-gray-500">{{ s.address }}</p>
                            <p v-else class="mt-0.5 truncate text-xs italic text-gray-400">Sin dirección</p>
                        </div>
                    </div>

                    <div class="flex flex-1 flex-col gap-3 p-5">
                        <div :class="['rounded-xl px-3 py-2 ring-1', s.online_ordering_enabled ? 'bg-blue-50 ring-blue-200' : 'bg-gray-50 ring-gray-200']">
                            <div class="flex items-center gap-2">
                                <svg :class="['h-4 w-4', s.online_ordering_enabled ? 'text-blue-600' : 'text-gray-400']" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3" /></svg>
                                <span :class="['text-xs font-bold', s.online_ordering_enabled ? 'text-blue-900' : 'text-gray-500']">
                                    {{ s.online_ordering_enabled ? 'Menú online activo' : 'Menú online apagado' }}
                                </span>
                            </div>
                            <div v-if="s.online_ordering_enabled" class="mt-1 flex flex-wrap items-center gap-2 text-[11px] font-medium">
                                <span v-if="s.delivery_enabled" class="text-blue-700">🚚 Envío</span>
                                <span v-if="s.pickup_enabled" class="text-emerald-700">🏪 Recolección</span>
                                <span v-if="!s.delivery_enabled && !s.pickup_enabled" class="italic text-amber-600">⚠ Sin modos</span>
                            </div>
                        </div>

                        <div class="flex items-start gap-2">
                            <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                            <p class="text-xs text-gray-600">
                                <template v-if="s.hours && summarizeHours(s.hours)">{{ summarizeHours(s.hours) }}</template>
                                <span v-else class="italic text-amber-600">Sin horarios configurados</span>
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-600">
                            <span v-if="s.phone" class="inline-flex items-center gap-1 tabular-nums">
                                <svg class="h-3 w-3 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                                {{ s.phone }}
                            </span>
                            <span v-if="s.public_phone" class="inline-flex items-center gap-1 font-mono tabular-nums text-[#25D366]">
                                <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347" /></svg>
                                {{ s.public_phone }}
                            </span>
                        </div>

                        <div class="mt-auto flex items-center justify-between border-t border-gray-50 pt-3 text-[11px]">
                            <span :class="['inline-flex items-center gap-1', hasLocation(s) ? 'text-gray-500' : 'text-amber-600']">
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" /></svg>
                                {{ hasLocation(s) ? 'Ubicación OK' : 'Sin ubicación' }}
                            </span>
                            <span class="inline-flex items-center gap-1 text-gray-500">
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                                {{ s.users_count }} {{ s.users_count === 1 ? 'usuario' : 'usuarios' }}
                            </span>
                        </div>
                    </div>

                    <span class="absolute right-4 top-4 text-gray-300 opacity-0 transition group-hover:opacity-100">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </span>
                </Link>
            </div>

            <div v-else class="rounded-2xl bg-white px-6 py-16 text-center shadow-sm ring-1 ring-gray-100">
                <p class="text-sm font-medium text-gray-500">
                    <template v-if="filters.search">Sin sucursales que coincidan con "{{ filters.search }}".</template>
                    <template v-else-if="filter !== 'all'">Sin sucursales en este filtro.</template>
                    <template v-else>Aún no tienes sucursales.</template>
                </p>
                <Link :href="route('empresa.sucursales.create', tenant.slug)"
                    class="mt-4 inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-red-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Crear primera sucursal
                </Link>
            </div>

            <div v-if="sucursales.last_page > 1" class="flex justify-center pt-2">
                <div class="flex gap-1">
                    <Link v-for="link in sucursales.links" :key="link.label" :href="link.url || '#'"
                        :class="['rounded-lg px-3.5 py-2 text-sm font-medium transition',
                            link.active ? 'bg-red-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100',
                            !link.url && 'pointer-events-none opacity-40']"
                        v-html="link.label" />
                </div>
            </div>
        </div>
    </EmpresaLayout>
</template>
