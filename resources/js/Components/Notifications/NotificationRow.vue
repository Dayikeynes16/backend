<script setup>
import { computed } from 'vue';
import IslandIcon from './IslandIcon.vue';
import { LEVEL_STYLE, levelOf, relativeTime } from '@/lib/notificationsCore.js';

/**
 * Una fila de la bandeja. Port de `carniceria-hub` (`NotificationRow.vue`) con
 * una diferencia: un recordatorio de agenda se resuelve desde aquí mismo
 * (Hecho / +30 min / Visto). Por eso la fila es un `div` con un botón dentro y
 * no un botón entero, como en el hub: un botón no puede contener otros.
 */
const props = defineProps({
    n: { type: Object, required: true },
    highlight: { type: Boolean, default: false },
    // Mostrar las acciones del recordatorio (sólo si hay tenant: sin slug no hay ruta).
    reminder: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
});
defineEmits(['select', 'act']);

const style = computed(() => LEVEL_STYLE[levelOf(props.n)]);
</script>

<template>
    <div class="relative rounded-2xl transition hover:bg-white/5" :class="highlight ? 'bg-white/10' : ''">
        <button
            type="button"
            class="flex w-full gap-3 rounded-2xl px-3 py-2.5 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/40"
            @click="$emit('select')"
        >
            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-xl" :class="style.chip">
                <IslandIcon :name="style.icon" :size="16" />
            </span>
            <span class="min-w-0 flex-1 pr-3">
                <span class="block truncate text-[13px]" :class="n.read_at ? 'font-medium text-gray-300' : 'font-semibold text-white'">{{ n.title }}</span>
                <span class="line-clamp-2 block text-xs text-gray-400">{{ n.body }}</span>
                <span v-if="n.reason" class="block truncate text-[11px] italic text-gray-500">“{{ n.reason }}”</span>
                <span class="mt-0.5 block text-[11px] text-gray-500">{{ relativeTime(n.created_at) }}</span>
            </span>
        </button>
        <!-- Sólo mientras siga sin leer: leído, el recordatorio ya se atendió (o se vio) y las acciones sobran. -->
        <div v-if="reminder && !n.read_at" class="-mt-1 flex gap-1.5 pb-2.5 pl-14 pr-3">
            <button type="button" :disabled="busy" class="rounded-full bg-white px-3 py-1 text-[11px] font-bold text-gray-900 transition hover:bg-gray-200 disabled:opacity-40" @click="$emit('act', 'complete')">Hecho</button>
            <button type="button" :disabled="busy" class="rounded-full bg-white/10 px-3 py-1 text-[11px] font-semibold text-gray-200 transition hover:bg-white/20 disabled:opacity-40" @click="$emit('act', 'snooze')">+30 min</button>
            <button type="button" :disabled="busy" class="rounded-full bg-white/10 px-3 py-1 text-[11px] font-semibold text-gray-200 transition hover:bg-white/20 disabled:opacity-40" @click="$emit('act', 'seen')">Visto</button>
        </div>
        <span v-if="!n.read_at" class="pointer-events-none absolute right-3 top-3.5 h-1.5 w-1.5 rounded-full bg-rose-400" aria-label="Sin leer" />
    </div>
</template>
