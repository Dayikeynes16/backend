<script setup>
import { ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const sidebarOpen = ref(false);

const navLinks = [
    { label: 'Dashboard', route: 'admin.dashboard', icon: 'dashboard' },
    { label: 'Empresas', route: 'admin.empresas.index', match: 'admin.empresas', icon: 'empresas' },
];

const isActive = (link) => {
    if (link.match) return route().current(link.match + '*') || route().current(link.route);
    return route().current(link.route);
};
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <!-- Mobile overlay -->
        <Transition enter-active-class="transition-opacity duration-300" leave-active-class="transition-opacity duration-300" enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="sidebarOpen" class="fixed inset-0 z-40 bg-gray-900/30 backdrop-blur-sm lg:hidden" @click="sidebarOpen = false" />
        </Transition>

        <!-- Sidebar -->
        <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'" class="fixed inset-y-0 left-0 z-50 flex w-[260px] flex-col border-r border-gray-200 bg-white transition-transform duration-300 lg:translate-x-0">
            <!-- Brand -->
            <div class="flex h-16 items-center gap-3 px-6">
                <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-red-600 to-orange-500 shadow-sm shadow-red-200">
                    <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
                    </svg>
                </div>
                <div class="flex flex-col">
                    <span class="text-[13px] font-bold leading-tight text-gray-900">Carniceria</span>
                    <span class="text-[11px] font-semibold leading-tight text-orange-500">SaaS Platform</span>
                </div>
            </div>

            <!-- Nav -->
            <nav class="flex-1 px-3 pt-6">
                <p class="mb-3 px-3 text-[10px] font-bold uppercase tracking-[0.15em] text-gray-400">Menu</p>

                <div class="space-y-1">
                    <Link v-for="link in navLinks" :key="link.route" :href="route(link.route)"
                        :class="[
                            'group flex items-center gap-3 rounded-lg px-3 py-2.5 text-[13px] font-medium transition-all duration-150',
                            isActive(link)
                                ? 'border-l-[3px] border-red-600 bg-red-50 text-red-700'
                                : 'border-l-[3px] border-transparent text-gray-600 hover:bg-gray-50 hover:text-gray-900',
                        ]"
                    >
                        <svg v-if="link.icon === 'dashboard'" class="h-[18px] w-[18px] shrink-0" :class="isActive(link) ? 'text-red-500' : 'text-gray-400 group-hover:text-gray-600'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
                        </svg>
                        <svg v-if="link.icon === 'empresas'" class="h-[18px] w-[18px] shrink-0" :class="isActive(link) ? 'text-red-500' : 'text-gray-400 group-hover:text-gray-600'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3H21m-3.75 3H21" />
                        </svg>
                        {{ link.label }}
                    </Link>
                </div>
            </nav>

            <!-- User -->
            <div class="border-t border-gray-100 bg-gray-50/80 p-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-br from-red-500 to-orange-400 text-xs font-bold text-white shadow-sm">
                        {{ page.props.auth.user.name.charAt(0) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[13px] font-semibold text-gray-900">{{ page.props.auth.user.name }}</p>
                        <p class="truncate text-[11px] text-gray-400">{{ page.props.auth.user.email }}</p>
                    </div>
                </div>
                <div class="mt-3 flex gap-2">
                    <Link :href="route('profile.edit')" class="flex-1 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-center text-[11px] font-medium text-gray-600 transition hover:border-gray-300 hover:text-gray-900">
                        Perfil
                    </Link>
                    <Link :href="route('logout')" method="post" as="button" class="flex-1 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-center text-[11px] font-medium text-gray-600 transition hover:border-red-200 hover:bg-red-50 hover:text-red-600">
                        Salir
                    </Link>
                </div>
            </div>
        </aside>

        <!-- Main -->
        <div class="lg:pl-[260px]">
            <!-- Top bar -->
            <header class="sticky top-0 z-30 flex h-14 items-center gap-4 border-b border-gray-200 bg-white/80 px-4 backdrop-blur-sm lg:px-8">
                <button @click="sidebarOpen = true" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 lg:hidden">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                </button>
                <div class="flex flex-1 items-center justify-between">
                    <slot name="header" />
                    <span class="hidden rounded-md bg-red-50 px-2.5 py-1 text-[11px] font-semibold text-red-700 ring-1 ring-red-100 sm:inline-flex">
                        Superadmin
                    </span>
                </div>
            </header>

            <main class="p-4 lg:p-8">
                <slot />
            </main>
        </div>
    </div>
</template>
