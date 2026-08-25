<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useUserNotifications } from '@/composables/useUserNotifications';

/**
 * Campana de avisos personales. Convive con `AgendaBell`, que es otra cosa:
 * aquélla calcula alertas de agenda y finanzas al vuelo, ésta lee avisos que
 * alguien dejó guardados para este usuario.
 *
 * El nivel del aviso decide cuánto grita:
 *  - `action`    algo espera una decisión suya (una cancelación por resolver)
 *  - `important` ya ocurrió y le cambia el trabajo (su cancelación fue aprobada)
 *  - `info`      contexto, se lee de pasada
 */
const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);

const { items, unreadCount, loading, load, markRead, markAllRead } = useUserNotifications();

const open = ref(false);

const toggle = () => {
    open.value = !open.value;
    if (open.value) load();
};

const LEVELS = {
    action: { dot: 'bg-red-500', ring: 'ring-red-100' },
    important: { dot: 'bg-amber-500', ring: 'ring-amber-100' },
    info: { dot: 'bg-gray-400', ring: 'ring-gray-100' },
};
const levelOf = (n) => LEVELS[n.level] ?? LEVELS.info;

/** Adónde lleva el aviso. Hoy sólo hay cancelaciones. */
const linkFor = (n) => {
    if (!slug.value) return null;
    if (n.type === 'sale.cancellation.requested') return route('sucursal.cancelaciones.index', slug.value);
    return null;
};

const relative = (iso) => {
    if (!iso) return '';
    const mins = Math.floor((Date.now() - new Date(iso)) / 60000);
    if (mins < 1) return 'ahora';
    if (mins < 60) return `hace ${mins} min`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `hace ${hours} h`;
    return `hace ${Math.floor(hours / 24)} d`;
};
</script>

<template>
    <div class="relative">
        <button
            type="button"
            @click="toggle"
            class="relative rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700"
            title="Avisos"
        >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
            </svg>
            <span
                v-if="unreadCount"
                class="absolute -right-0.5 -top-0.5 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white"
            >
                {{ unreadCount > 9 ? '9+' : unreadCount }}
            </span>
        </button>

        <div v-if="open" class="absolute right-0 z-50 mt-2 w-80 rounded-2xl bg-white p-2 shadow-xl ring-1 ring-gray-100">
            <div class="flex items-center justify-between px-2 py-1.5">
                <span class="text-sm font-bold text-gray-900">Avisos</span>
                <button
                    v-if="unreadCount"
                    type="button"
                    class="text-xs font-semibold text-red-600 hover:underline"
                    @click="markAllRead"
                >
                    Marcar todo leído
                </button>
            </div>

            <p v-if="loading && !items.length" class="px-2 py-4 text-center text-sm text-gray-400">Cargando avisos…</p>
            <p v-else-if="!items.length" class="px-2 py-4 text-center text-sm text-gray-400">Sin avisos.</p>

            <div v-else class="max-h-80 overflow-y-auto">
                <component
                    :is="linkFor(n) ? Link : 'div'"
                    v-for="n in items"
                    :key="n.id"
                    :href="linkFor(n) || undefined"
                    class="flex w-full gap-2 rounded-lg px-2 py-2 text-left transition hover:bg-gray-50"
                    :class="!n.read_at ? 'bg-red-50/40' : ''"
                    @click="markRead(n.id); open = linkFor(n) ? false : open"
                >
                    <span :class="['mt-1.5 h-2 w-2 shrink-0 rounded-full ring-4', levelOf(n).dot, levelOf(n).ring]" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-gray-900">{{ n.title }}</p>
                        <p class="text-xs text-gray-600">{{ n.body }}</p>
                        <p v-if="n.reason" class="mt-0.5 truncate text-xs italic text-gray-400">“{{ n.reason }}”</p>
                        <p class="mt-0.5 text-[11px] text-gray-400">{{ relative(n.created_at) }}</p>
                    </div>
                </component>
            </div>
        </div>
    </div>
</template>
