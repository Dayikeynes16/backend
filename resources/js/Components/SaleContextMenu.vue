<script setup>
import { computed, ref } from 'vue';
import Dropdown from '@/Components/Dropdown.vue';

const props = defineProps({
    sale: { type: Object, required: true },
    canManageStatus: { type: Boolean, default: false },
    isLockedByOther: { type: Boolean, default: false },
    lockedByName: { type: String, default: null },
});

const emit = defineEmits(['pause', 'reactivate', 'reopen', 'cancel', 'request-cancel']);

const actions = computed(() => {
    const status = props.sale.status;
    const items = [];

    if (props.canManageStatus) {
        if (status === 'active') {
            items.push({ key: 'pause', label: 'Pausar venta', icon: 'pause', event: 'pause' });
        }
        if (status === 'pending') {
            items.push({ key: 'reactivate', label: 'Reactivar venta', icon: 'play', event: 'reactivate' });
        }
        if (status === 'completed') {
            items.push({ key: 'reopen', label: 'Reabrir venta', icon: 'reopen', event: 'reopen' });
        }
        if (status !== 'cancelled') {
            items.push({ key: 'divider' });
            items.push({ key: 'cancel', label: 'Cancelar venta', icon: 'cancel', event: 'cancel', danger: true });
        }
    } else {
        // Cajero: solo puede solicitar cancelacion
        if (status !== 'cancelled' && !props.sale.cancel_requested_at) {
            items.push({ key: 'request-cancel', label: 'Solicitar cancelacion', icon: 'cancel', event: 'request-cancel', warning: true });
        }
    }

    return items;
});

const hasActions = computed(() => actions.value.filter(a => a.key !== 'divider').length > 0);

const hasCancelRequest = computed(() => !!props.sale.cancel_requested_at);

const handleAction = (action) => {
    emit(action.event);
};
</script>

<template>
    <div v-if="hasActions || hasCancelRequest" class="relative" @click.stop>
        <!-- Cancel request badge (shown instead of menu when request is pending and user is cajero) -->
        <span v-if="hasCancelRequest && !canManageStatus"
            class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">
            Cancelacion solicitada
        </span>

        <Dropdown v-else align="right" width="48">
            <template #trigger>
                <button
                    type="button"
                    :disabled="isLockedByOther"
                    :title="isLockedByOther ? `Bloqueada por ${lockedByName || 'otro usuario'}` : 'Acciones'"
                    :class="['inline-flex items-center justify-center rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 focus:outline-none',
                        isLockedByOther ? 'cursor-not-allowed opacity-40' : '']">
                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" />
                    </svg>
                </button>
            </template>

            <template #content>
                <div class="py-1">
                    <!-- Cancel request badge inside menu for admins -->
                    <div v-if="hasCancelRequest && canManageStatus" class="px-3 py-2 border-b border-gray-100">
                        <span class="inline-flex items-center gap-1 text-xs font-semibold text-amber-700">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126Z" /></svg>
                            Cancelacion solicitada
                        </span>
                    </div>

                    <template v-for="action in actions" :key="action.key">
                        <div v-if="action.key === 'divider'" class="my-1 border-t border-gray-100" />
                        <button v-else type="button" @click="handleAction(action)"
                            :class="['flex w-full items-center gap-2 px-3 py-2 text-left text-sm transition',
                                action.danger ? 'text-red-600 hover:bg-red-50' :
                                action.warning ? 'text-amber-600 hover:bg-amber-50' :
                                'text-gray-700 hover:bg-gray-50']">
                            <!-- Pause icon -->
                            <svg v-if="action.icon === 'pause'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25v13.5m-7.5-13.5v13.5" />
                            </svg>
                            <!-- Play icon -->
                            <svg v-else-if="action.icon === 'play'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" />
                            </svg>
                            <!-- Reopen icon -->
                            <svg v-else-if="action.icon === 'reopen'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182" />
                            </svg>
                            <!-- Cancel icon -->
                            <svg v-else-if="action.icon === 'cancel'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                            {{ action.label }}
                        </button>
                    </template>
                </div>
            </template>
        </Dropdown>
    </div>
</template>
