<script setup>
import HistorialTimeline from '@/Components/Historial/HistorialTimeline.vue';
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    product: { type: Object, default: null },
    routePrefix: { type: String, default: 'empresa' },
});
const emit = defineEmits(['close']);

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);

const loading = ref(false);
const error = ref(null);
const history = ref([]);
let controller = null;

const close = () => emit('close');

watch(() => props.open, async (open) => {
    if (!open || !props.product) return;
    if (controller) controller.abort();
    controller = new AbortController();
    loading.value = true;
    error.value = null;
    history.value = [];
    try {
        const url = route(`${props.routePrefix}.productos-compra.historial`, { tenant: slug.value, producto_compra: props.product.id });
        const res = await fetch(url, {
            signal: controller.signal,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        history.value = data.history || [];
    } catch (e) {
        if (e.name !== 'AbortError') error.value = 'No se pudo cargar el historial.';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition duration-200" leave-active-class="transition duration-150"
            enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="open" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-sm" @click="close">
                <Transition enter-active-class="transition duration-200 ease-out" leave-active-class="transition duration-150 ease-in"
                    enter-from-class="translate-x-full" leave-to-class="translate-x-full">
                    <aside v-if="open" class="absolute right-0 top-0 flex h-full w-full max-w-md flex-col bg-white shadow-xl" @click.stop>
                        <header class="flex items-start justify-between border-b border-gray-200 px-5 py-4">
                            <div>
                                <h2 class="text-base font-bold text-gray-900">Historial</h2>
                                <p class="mt-0.5 text-sm text-gray-500">{{ product?.name }}</p>
                            </div>
                            <button @click="close" class="text-gray-400 hover:text-gray-700">✕</button>
                        </header>

                        <div class="flex-1 overflow-y-auto px-5 py-4">
                            <div v-if="loading" class="space-y-2">
                                <div v-for="i in 3" :key="i" class="h-14 animate-pulse rounded-lg bg-gray-100"></div>
                            </div>
                            <p v-else-if="error" class="rounded-lg bg-red-50 px-3 py-3 text-sm text-red-700">{{ error }}</p>
                            <HistorialTimeline v-else :history="history" />
                        </div>
                    </aside>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
