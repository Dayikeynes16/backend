<script setup>
import { usePage } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';

const page = usePage();

const flash = computed(() => page.props.flash);
const visible = ref(false);
const message = ref('');
const type = ref('success');

let timeout;
const show = () => {
    clearTimeout(timeout);
    visible.value = true;
    timeout = setTimeout(() => visible.value = false, 4000);
};

watch(flash, (val) => {
    if (val?.success) {
        message.value = val.success;
        type.value = 'success';
        show();
    } else if (val?.error) {
        message.value = val.error;
        type.value = 'error';
        show();
    }
}, { deep: true, immediate: true });
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-300 ease-out"
            leave-active-class="transition duration-200 ease-in"
            enter-from-class="translate-y-2 opacity-0"
            leave-to-class="translate-y-1 opacity-0"
        >
            <div v-if="visible" class="fixed bottom-6 right-6 z-[100] max-w-sm">
                <div
                    :class="type === 'success'
                        ? 'border-green-200 bg-green-50 text-green-800'
                        : 'border-red-200 bg-red-50 text-red-800'"
                    class="flex items-start gap-3 rounded-xl border px-5 py-4 shadow-lg"
                >
                    <!-- Success icon -->
                    <svg v-if="type === 'success'" class="mt-0.5 h-5 w-5 shrink-0 text-green-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    <!-- Error icon -->
                    <svg v-else class="mt-0.5 h-5 w-5 shrink-0 text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                    </svg>
                    <p class="text-sm font-medium">{{ message }}</p>
                    <button @click="visible = false" class="ml-auto shrink-0 rounded p-0.5 opacity-60 transition hover:opacity-100">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
