<script setup>
import { useRoute, useRouter } from 'vue-router';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useMenu } from '../composables/useMenu.js';
import { useCart } from '../composables/useCart.js';
import ProductModal from '../components/ProductModal.vue';
import CartBar from '../components/CartBar.vue';

const route = useRoute();
const router = useRouter();
const tenantSlug = computed(() => route.params.tenantSlug);
const branchId = computed(() => Number(route.params.branchId));

const { branch, categories, products, loading, error, fetch } = useMenu(tenantSlug.value, branchId.value);
const cart = useCart(branchId.value);
const search = ref('');
const activeCategoryId = ref(null);
const selectedProduct = ref(null);

const productsByCategory = computed(() => {
    const term = search.value.trim().toLowerCase();
    const filtered = term
        ? products.value.filter((p) => p.name.toLowerCase().includes(term))
        : products.value;

    const grouped = new Map();
    // Uncategorized goes first
    const uncat = filtered.filter((p) => !p.category_id);
    if (uncat.length) grouped.set('sin-categoria', { id: null, name: 'Productos', items: uncat });

    for (const cat of categories.value) {
        const items = filtered.filter((p) => p.category_id === cat.id);
        if (items.length) grouped.set(cat.id, { id: cat.id, name: cat.name, items });
    }
    return Array.from(grouped.values());
});

let observer = null;
onMounted(async () => {
    await fetch();
    await nextTick();

    observer = new IntersectionObserver(
        (entries) => {
            const visible = entries.filter((e) => e.isIntersecting).sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
            if (visible) activeCategoryId.value = visible.target.dataset.categoryId || null;
        },
        { rootMargin: '-30% 0px -60% 0px', threshold: [0, 0.25, 0.5, 1] },
    );
    document.querySelectorAll('[data-category-id]').forEach((el) => observer.observe(el));
});

onUnmounted(() => observer?.disconnect());

watch(productsByCategory, async () => {
    await nextTick();
    observer?.disconnect();
    observer = new IntersectionObserver(
        (entries) => {
            const visible = entries.filter((e) => e.isIntersecting).sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
            if (visible) activeCategoryId.value = visible.target.dataset.categoryId || null;
        },
        { rootMargin: '-30% 0px -60% 0px', threshold: [0, 0.25, 0.5, 1] },
    );
    document.querySelectorAll('[data-category-id]').forEach((el) => observer.observe(el));
});

const scrollToCategory = (catId) => {
    const el = document.querySelector(`[data-category-id="${catId ?? ''}"]`);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

const goToCart = () => {
    router.push({ name: 'cart', params: { tenantSlug: tenantSlug.value, branchId: branchId.value } });
};

const goToBranches = () => {
    router.push({ name: 'branches', params: { tenantSlug: tenantSlug.value } });
};

const openProduct = (product) => {
    if (!branch.value?.is_open) return;
    selectedProduct.value = product;
};

const unitTypeLabel = (type) => ({ kg: 'kg', piece: 'pz', cut: 'pz' }[type] || '');
</script>

<template>
    <div class="min-h-screen bg-gray-50 pb-24">
        <!-- Loading: skeleton cards -->
        <div v-if="loading" class="animate-pulse">
            <div class="bg-white px-4 py-3 shadow-sm">
                <div class="flex items-center gap-3">
                    <div class="h-11 w-11 rounded-full bg-gray-100"></div>
                    <div class="flex-1 space-y-2">
                        <div class="h-4 w-40 rounded bg-gray-200"></div>
                        <div class="h-3 w-56 rounded bg-gray-100"></div>
                    </div>
                </div>
                <div class="mt-3 h-11 w-full rounded-full bg-gray-100"></div>
            </div>
            <div class="space-y-3 px-4 pt-6">
                <div v-for="n in 5" :key="n" class="flex items-center gap-3 rounded-2xl bg-white p-3 ring-1 ring-gray-100">
                    <div class="h-24 w-24 shrink-0 rounded-xl bg-gray-100"></div>
                    <div class="flex-1 space-y-2">
                        <div class="h-4 w-3/4 rounded bg-gray-200"></div>
                        <div class="h-3 w-full rounded bg-gray-100"></div>
                        <div class="h-5 w-20 rounded bg-gray-200"></div>
                    </div>
                    <div class="h-11 w-11 shrink-0 rounded-full bg-gray-100"></div>
                </div>
            </div>
        </div>

        <div v-else-if="error === 'not_found'" class="flex min-h-screen items-center justify-center p-6 text-center">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Sucursal no disponible</h1>
                <button @click="goToBranches" class="mt-4 rounded-full bg-red-600 px-6 py-3 text-base font-semibold text-white">Ver otras sucursales</button>
            </div>
        </div>

        <div v-else-if="branch" :class="!branch.is_open ? 'opacity-60 grayscale' : ''">
            <!-- Header -->
            <header class="sticky top-0 z-30 bg-white/95 backdrop-blur shadow-sm">
                <div class="flex items-center gap-3 px-4 py-3">
                    <button @click="goToBranches" aria-label="Volver a sucursales"
                        class="flex h-11 w-11 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 hover:text-gray-800">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                    </button>
                    <div class="min-w-0 flex-1">
                        <h1 class="truncate text-lg font-bold text-gray-900">{{ branch.name }}</h1>
                        <p v-if="branch.address" class="truncate text-sm text-gray-500">{{ branch.address }}</p>
                    </div>
                </div>

                <!-- Search -->
                <div class="px-4 pb-3">
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                        <input v-model="search" type="text" placeholder="Buscar producto..."
                            class="w-full rounded-full border-0 bg-gray-100 py-3 pl-11 pr-11 text-base text-gray-800 placeholder-gray-400 focus:bg-white focus:ring-2 focus:ring-red-400" />
                        <button v-if="search" @click="search = ''" type="button" aria-label="Limpiar búsqueda"
                            class="absolute right-2 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full text-gray-400 hover:bg-gray-200 hover:text-gray-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                </div>

                <!-- Category nav -->
                <div v-if="productsByCategory.length > 1" class="no-scrollbar overflow-x-auto border-t border-gray-100">
                    <div class="flex gap-2 px-4 py-2.5">
                        <button v-for="cat in productsByCategory" :key="cat.id || 'null'"
                            @click="scrollToCategory(cat.id)"
                            :class="['whitespace-nowrap rounded-full px-4 py-2 text-sm font-semibold transition',
                                String(activeCategoryId) === String(cat.id || '')
                                    ? 'bg-red-600 text-white shadow-sm'
                                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
                            {{ cat.name }}
                        </button>
                    </div>
                </div>

                <!-- Closed banner -->
                <div v-if="!branch.is_open" class="flex items-center justify-center gap-2 bg-amber-100 px-4 py-3 text-center text-base font-semibold text-amber-900">
                    <span class="text-xl">🕒</span>
                    <span>Cerrado en este momento. Consulta nuestros horarios.</span>
                </div>
            </header>

            <!-- Content -->
            <main class="px-4 pt-5">
                <section v-for="cat in productsByCategory" :key="cat.id || 'null'" :data-category-id="cat.id || ''" class="mb-8">
                    <h2 class="mb-3 text-lg font-bold text-gray-900">{{ cat.name }}</h2>
                    <div class="space-y-3">
                        <button v-for="p in cat.items" :key="p.id" @click="openProduct(p)"
                            :disabled="!branch.is_open"
                            class="group flex w-full items-center gap-3 rounded-2xl bg-white p-3 text-left shadow-sm ring-1 ring-gray-100 transition hover:ring-red-300 active:scale-[0.99] disabled:cursor-not-allowed">
                            <div class="h-24 w-24 shrink-0 overflow-hidden rounded-xl bg-gray-100">
                                <img v-if="p.image_url" :src="p.image_url" :alt="p.name" loading="lazy" decoding="async" class="h-full w-full object-cover" />
                                <div v-else class="flex h-full w-full items-center justify-center text-gray-300">
                                    <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>
                                </div>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="truncate text-base font-bold text-gray-900">{{ p.name }}</h3>
                                <p v-if="p.description" class="line-clamp-2 mt-1 text-sm text-gray-500">{{ p.description }}</p>
                                <p class="mt-2 text-lg font-extrabold text-red-600">${{ p.price.toFixed(2) }} <span class="text-sm font-medium text-gray-400">/ {{ unitTypeLabel(p.unit_type) }}</span></p>
                            </div>
                            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600 transition group-hover:bg-red-600 group-hover:text-white">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            </div>
                        </button>
                    </div>
                </section>

                <div v-if="productsByCategory.length === 0" class="py-20 text-center text-base text-gray-500">
                    {{ search ? 'Sin resultados para tu búsqueda.' : 'Aún no hay productos disponibles.' }}
                </div>
            </main>
        </div>

        <!-- Cart bar -->
        <CartBar v-if="branch?.is_open" :branch-id="branchId" @open="goToCart" />

        <!-- Product modal -->
        <ProductModal
            v-if="selectedProduct"
            :product="selectedProduct"
            :branch-id="branchId"
            @close="selectedProduct = null"
        />
    </div>
</template>

<style>
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>
