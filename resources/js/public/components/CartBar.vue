<script setup>
import { useCart } from '../composables/useCart.js';

const props = defineProps({
    branchId: { type: Number, required: true },
});

const emit = defineEmits(['open']);

const cart = useCart(props.branchId);
</script>

<template>
    <Transition name="slide-up">
        <div v-if="cart.count.value > 0" class="fixed bottom-4 left-4 right-4 z-40 sm:bottom-6 sm:left-auto sm:right-6 sm:w-96">
            <button @click="emit('open')"
                class="flex w-full items-center justify-between gap-4 rounded-full py-4 pl-5 pr-5 text-left shadow-xl transition hover:brightness-95 active:scale-[0.98]"
                style="background-color: var(--brand-primary); color: var(--brand-on-primary);">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-white/20 text-base font-extrabold tabular-nums">{{ cart.count.value }}</span>
                    <span class="text-base font-bold">Ver carrito</span>
                </div>
                <span class="text-base font-extrabold tabular-nums">${{ cart.subtotal.value.toFixed(2) }}</span>
            </button>
        </div>
    </Transition>
</template>

<style>
.slide-up-enter-active, .slide-up-leave-active { transition: all 0.25s ease; }
.slide-up-enter-from, .slide-up-leave-to { opacity: 0; transform: translateY(20px); }
</style>
