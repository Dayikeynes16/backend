<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const sidebarOpen = ref(false);
const slug = computed(() => page.props.auth.tenant_slug);

const navLinks = [
    { label: 'Mesa de Trabajo', route: 'caja.workbench', icon: 'M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z' },
    { label: 'Pagos', route: 'caja.pagos', icon: 'M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z' },
    { label: 'Mi Turno', route: 'caja.turno', icon: 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z' },
    { label: 'Historial', route: 'caja.historial', icon: 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z' },
];

const isActive = (link) => route().current(link.route + '*') || route().current(link.route);
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <Transition enter-active-class="transition-opacity duration-300" leave-active-class="transition-opacity duration-200" enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="sidebarOpen" class="fixed inset-0 z-40 bg-black/40 backdrop-blur-sm lg:hidden" @click="sidebarOpen = false" />
        </Transition>

        <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'" class="fixed inset-y-0 left-0 z-50 flex w-[240px] flex-col bg-gradient-to-b from-red-800 to-red-900 transition-transform duration-300 lg:translate-x-0">
            <div class="flex h-[68px] items-center gap-3 px-5">
                <img src="/logo.png" alt="El Puebla" class="h-9 w-9 rounded-xl" />
                <span class="text-sm font-bold text-white">Caja</span>
            </div>

            <nav class="flex-1 px-3 pt-4">
                <div class="space-y-1">
                    <Link v-for="link in navLinks" :key="link.route" :href="route(link.route, slug)"
                        :class="['group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-all',
                            isActive(link) ? 'border-l-[3px] border-white bg-white/15 text-white' : 'border-l-[3px] border-transparent text-red-100 hover:bg-white/10 hover:text-white']">
                        <svg class="h-5 w-5 shrink-0 transition-colors" :class="isActive(link) ? 'text-white' : 'text-red-300 group-hover:text-white'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="link.icon" /></svg>
                        {{ link.label }}
                    </Link>
                </div>
            </nav>

            <div class="bg-red-950/50 p-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-br from-orange-400 to-red-500 text-sm font-bold text-white">{{ page.props.auth.user.name.charAt(0) }}</div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-white">{{ page.props.auth.user.name }}</p>
                        <p class="truncate text-xs text-red-200">Cajero</p>
                    </div>
                </div>
                <Link :href="route('logout')" method="post" as="button" class="mt-3 w-full rounded-lg bg-white/10 px-3 py-2 text-center text-xs font-medium text-red-100 transition hover:bg-white/20 hover:text-white">Cerrar sesion</Link>
            </div>
        </aside>

        <div class="lg:pl-[240px]">
            <header class="sticky top-0 z-30 flex h-14 items-center gap-4 border-b border-gray-200 bg-white px-5 lg:px-8">
                <button @click="sidebarOpen = true" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 lg:hidden">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                </button>
                <div class="flex flex-1 items-center justify-between">
                    <slot name="header" />
                    <span class="hidden rounded-full bg-green-100 px-3 py-1 text-xs font-bold text-green-700 sm:inline-flex">Cajero</span>
                </div>
            </header>
            <main class="p-5 lg:p-8"><slot /></main>
        </div>
    </div>
</template>
