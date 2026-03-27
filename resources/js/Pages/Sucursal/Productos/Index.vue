<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({ productos: Object, categories: Array, filters: Object, tenant: Object });

const search = ref(props.filters?.search || '');
const categoryFilter = ref(props.filters?.category_id || '');

let debounce;
const applyFilters = () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(route('sucursal.productos.index', props.tenant.slug), {
            search: search.value || undefined,
            category_id: categoryFilter.value || undefined,
        }, { preserveState: true, replace: true });
    }, 300);
};
watch(search, applyFilters);
watch(categoryFilter, applyFilters);

const visibilityBadge = (v) => v === 'public'
    ? { label: 'Publico', cls: 'bg-green-50 text-green-700 ring-green-600/20' }
    : { label: 'Restringido', cls: 'bg-amber-50 text-amber-700 ring-amber-600/20' };
</script>

<template>
    <Head title="Productos" />
    <SucursalLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Productos</h1>
        </template>

        <div class="space-y-6">
            <!-- Toolbar -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex gap-3">
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                        <input v-model="search" type="text" placeholder="Buscar producto..." class="w-full rounded-lg border-gray-200 py-2.5 pl-10 pr-4 text-sm text-gray-700 placeholder-gray-400 focus:border-red-400 focus:ring-red-300 sm:w-64" />
                    </div>
                    <select v-model="categoryFilter" class="rounded-lg border-gray-200 py-2.5 text-sm text-gray-700 focus:border-red-400 focus:ring-red-300">
                        <option value="">Todas las categorias</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <Link :href="route('sucursal.productos.create', tenant.slug)" class="inline-flex items-center justify-center gap-2 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Nuevo Producto
                </Link>
            </div>

            <!-- Product list -->
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="divide-y divide-gray-50">
                    <div v-for="p in productos.data" :key="p.id" class="flex items-center gap-4 px-6 py-4 transition hover:bg-gray-50">
                        <!-- Thumbnail -->
                        <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-100 ring-1 ring-gray-200/50">
                            <img v-if="p.image_path" :src="`/storage/${p.image_path}`" class="h-full w-full object-cover" />
                            <svg v-else class="h-6 w-6 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5a2.25 2.25 0 0 0 2.25-2.25V5.25a2.25 2.25 0 0 0-2.25-2.25H3.75a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 3.75 21Z" /></svg>
                        </div>

                        <!-- Info -->
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p class="truncate text-sm font-bold text-gray-900">{{ p.name }}</p>
                                <span v-if="p.category" class="shrink-0 rounded-full bg-orange-50 px-2 py-0.5 text-xs font-semibold text-orange-700 ring-1 ring-orange-600/10">{{ p.category.name }}</span>
                            </div>
                            <p class="mt-0.5 text-xs text-gray-400">{{ p.description || 'Sin descripcion' }}</p>
                        </div>

                        <!-- Price -->
                        <div class="shrink-0 text-right">
                            <p class="text-base font-bold text-gray-900">${{ parseFloat(p.price).toFixed(2) }}</p>
                            <p v-if="p.cost_price" class="text-xs text-gray-400">Costo: ${{ parseFloat(p.cost_price).toFixed(2) }}</p>
                        </div>

                        <!-- Badges -->
                        <div class="flex shrink-0 items-center gap-2">
                            <span :class="visibilityBadge(p.visibility).cls" class="rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset">{{ visibilityBadge(p.visibility).label }}</span>
                            <span :class="p.status === 'active' ? 'bg-green-50 text-green-700 ring-green-600/20' : 'bg-red-50 text-red-700 ring-red-600/20'" class="rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset">{{ p.status === 'active' ? 'Activo' : 'Inactivo' }}</span>
                        </div>

                        <!-- Action -->
                        <Link :href="route('sucursal.productos.edit', [tenant.slug, p.id])" class="shrink-0 text-sm font-semibold text-red-600 transition hover:text-red-700">Editar</Link>
                    </div>

                    <div v-if="productos.data.length === 0" class="px-6 py-20 text-center">
                        <svg class="mx-auto h-10 w-10 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" /></svg>
                        <p class="mt-3 text-sm font-medium text-gray-400">No se encontraron productos.</p>
                    </div>
                </div>

                <div v-if="productos.last_page > 1" class="flex justify-center border-t border-gray-100 px-6 py-4">
                    <div class="flex gap-1">
                        <Link v-for="link in productos.links" :key="link.label" :href="link.url || '#'"
                            :class="['rounded-lg px-3.5 py-2 text-sm font-medium transition', link.active ? 'bg-red-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100', !link.url && 'pointer-events-none opacity-40']"
                            v-html="link.label" />
                    </div>
                </div>
            </div>
        </div>

        <FlashToast />
    </SucursalLayout>
</template>
