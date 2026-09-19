<script setup>
import { computed } from 'vue';

/**
 * Los dos porcentajes, de 5 en 5. Sin teclado y sin texto libre: esto se toca
 * en una tablet, de pie, detrás del mostrador.
 *
 * El urgente no puede alcanzar al de aviso, así que su tope es el valor del
 * otro menos un paso. La misma regla la vuelve a comprobar el servidor.
 */
const props = defineProps({
    warn: { type: Number, required: true },
    critical: { type: Number, required: true },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:warn', 'update:critical']);

const STEP = 5;
const WARN_MIN = 10;
const WARN_MAX = 95;
const CRITICAL_MIN = 5;

const criticalMax = computed(() => props.warn - STEP);
const warnMin = computed(() => Math.max(WARN_MIN, props.critical + STEP));

const setWarn = (value) => {
    const next = Math.min(WARN_MAX, Math.max(warnMin.value, value));
    emit('update:warn', next);
};

const setCritical = (value) => {
    const next = Math.min(criticalMax.value, Math.max(CRITICAL_MIN, value));
    emit('update:critical', next);
};
</script>

<template>
    <div class="space-y-3">
        <div class="flex items-center justify-between gap-4 rounded-xl p-4 ring-1 ring-gray-100">
            <div>
                <p class="text-sm font-bold text-gray-800">Avisar al bajar de</p>
                <p class="mt-0.5 text-xs text-gray-500">Franja ámbar en la caja y en el propio equipo.</p>
            </div>
            <div class="inline-flex shrink-0 items-center overflow-hidden rounded-xl ring-1 ring-gray-200">
                <button type="button" :disabled="warn <= warnMin" @click="setWarn(warn - STEP)"
                    class="px-3.5 py-2 text-lg font-bold text-gray-500 transition hover:bg-gray-100 disabled:opacity-30" aria-label="Bajar el aviso">−</button>
                <span class="min-w-[4.5rem] border-x border-gray-200 px-2 py-2 text-center text-sm font-bold tabular-nums text-gray-900">{{ warn }} %</span>
                <button type="button" :disabled="warn >= 95" @click="setWarn(warn + STEP)"
                    class="px-3.5 py-2 text-lg font-bold text-gray-500 transition hover:bg-gray-100 disabled:opacity-30" aria-label="Subir el aviso">+</button>
            </div>
        </div>

        <div class="flex items-center justify-between gap-4 rounded-xl p-4 ring-1 ring-gray-100">
            <div>
                <p class="text-sm font-bold text-gray-800">Urgente al bajar de</p>
                <p class="mt-0.5 text-xs text-gray-500">La franja se pone roja: el equipo está por apagarse.</p>
            </div>
            <div class="inline-flex shrink-0 items-center overflow-hidden rounded-xl ring-1 ring-gray-200">
                <button type="button" :disabled="critical <= 5" @click="setCritical(critical - STEP)"
                    class="px-3.5 py-2 text-lg font-bold text-gray-500 transition hover:bg-gray-100 disabled:opacity-30" aria-label="Bajar el aviso urgente">−</button>
                <span class="min-w-[4.5rem] border-x border-gray-200 px-2 py-2 text-center text-sm font-bold tabular-nums text-gray-900">{{ critical }} %</span>
                <button type="button" :disabled="critical >= criticalMax" @click="setCritical(critical + STEP)"
                    class="px-3.5 py-2 text-lg font-bold text-gray-500 transition hover:bg-gray-100 disabled:opacity-30" aria-label="Subir el aviso urgente">+</button>
            </div>
        </div>

        <p v-if="errors.battery_warn_threshold" class="text-sm font-medium text-red-600">{{ errors.battery_warn_threshold }}</p>
        <p v-if="errors.battery_critical_threshold" class="text-sm font-medium text-red-600">{{ errors.battery_critical_threshold }}</p>
    </div>
</template>
