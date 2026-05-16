<script setup>
import { computed } from 'vue';

/**
 * Campo "motivo del cambio" reutilizable para los modales de items.
 *
 * Patrón:
 *  - chips de motivos preset + "Otro motivo" con textarea
 *  - v-model con string (el motivo final consolidado, "" si no hay)
 *  - mode: 'disabled' oculta el componente; 'optional' lo muestra opcional;
 *    'required' lo marca obligatorio (sólo afecta el sello visual; la
 *    validación final la hace el servidor con base en el modo de la sucursal).
 *
 * Calcado de CancelSaleDialog.vue pero extraído como input para que la
 * misma lógica sirva en agregar / editar / eliminar.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    mode: { type: String, default: 'optional' }, // 'disabled' | 'optional' | 'required'
    tone: { type: String, default: 'red' }, // 'red' | 'amber' | 'gray'
    presets: {
        type: Array,
        default: () => [
            'Error de captura',
            'Cliente cambió de opinión',
            'Producto agotado',
            'Ajuste de precio',
            'Devolución parcial',
        ],
    },
    error: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const isCustom = computed(() => !!props.modelValue && !props.presets.includes(props.modelValue));
const selectedPreset = computed(() => props.presets.includes(props.modelValue) ? props.modelValue : '');

const selectPreset = (val) => {
    emit('update:modelValue', val);
};

const enableCustom = () => {
    // Pasa a modo "otro motivo" con valor vacío que el textarea va a llenar.
    emit('update:modelValue', isCustom.value ? props.modelValue : ' ');
};

const onCustomInput = (e) => {
    emit('update:modelValue', e.target.value);
};

const palette = computed(() => {
    if (props.tone === 'amber') {
        return {
            active: 'bg-amber-100 font-semibold text-amber-900 ring-1 ring-amber-200',
            focusRing: 'focus:border-amber-400 focus:ring-amber-300',
        };
    }
    if (props.tone === 'gray') {
        return {
            active: 'bg-gray-200 font-semibold text-gray-900 ring-1 ring-gray-300',
            focusRing: 'focus:border-gray-400 focus:ring-gray-300',
        };
    }

    return {
        active: 'bg-red-100 font-semibold text-red-900 ring-1 ring-red-200',
        focusRing: 'focus:border-red-400 focus:ring-red-300',
    };
});
</script>

<template>
    <div v-if="mode !== 'disabled'" class="space-y-2">
        <div class="flex items-center justify-between">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">Motivo</p>
            <span v-if="mode === 'required'" class="text-[10px] font-bold uppercase tracking-wider text-red-600">Obligatorio</span>
            <span v-else class="text-[10px] uppercase tracking-wider text-gray-400">Opcional</span>
        </div>

        <div class="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
            <button v-for="preset in presets" :key="preset" type="button" @click="selectPreset(preset)"
                :class="['rounded-lg px-3 py-2 text-left text-xs font-medium transition',
                    selectedPreset === preset ? palette.active : 'bg-gray-50 text-gray-700 ring-1 ring-gray-200 hover:bg-gray-100']">
                {{ preset }}
            </button>
            <button type="button" @click="enableCustom"
                :class="['rounded-lg px-3 py-2 text-left text-xs font-medium transition',
                    isCustom ? palette.active : 'bg-gray-50 text-gray-700 ring-1 ring-gray-200 hover:bg-gray-100']">
                Otro motivo…
            </button>
        </div>

        <textarea v-if="isCustom" :value="modelValue"
            @input="onCustomInput"
            rows="2" maxlength="500" placeholder="Describe el motivo del cambio…"
            :class="['block w-full rounded-lg border-gray-300 text-sm shadow-sm', palette.focusRing]" />

        <p v-if="error" class="text-xs text-red-600">{{ error }}</p>
    </div>
</template>
