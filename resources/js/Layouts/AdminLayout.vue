<script setup>
import { ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const sidebarOpen = ref(false);

const navLinks = [
    {
        label: 'Dashboard',
        route: 'admin.dashboard',
        icon: 'dashboard',
    },
    {
        label: 'Empresas',
        route: 'admin.empresas.index',
        match: 'admin.empresas',
        icon: 'empresas',
    },
];

const isActive = (link) => {
    if (link.match) {
        return route().current(link.match + '*') || route().current(link.route);
    }
    return route().current(link.route);
};
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <!-- Mobile overlay -->
        <Transition
            enter-active-class="transition-opacity duration-300"
            leave-active-class="transition-opacity duration-300"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
        >
            <div
                v-if="sidebarOpen"
                class="fixed inset-0 z-40 bg-black/50 lg:hidden"
                @click="sidebarOpen = false"
            />
        </Transition>

        <!-- Sidebar -->
        <aside
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
            class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-gray-950 transition-transform duration-300 lg:translate-x-0"
        >
            <!-- Brand -->
            <div class="flex h-16 items-center gap-3 border-b border-white/10 px-6">
                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-gradient-to-br from-red-500 to-orange-500">
                    <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
                    </svg>
                </div>
                <div>
                    <span class="text-sm font-bold tracking-wide text-white">Carniceria</span>
                    <span class="text-sm font-bold tracking-wide text-orange-400">SaaS</span>
                </div>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 space-y-1 px-3 py-4">
                <p class="mb-2 px-3 text-[11px] font-semibold uppercase tracking-widest text-gray-500">Menu</p>

                <Link
                    v-for="link in navLinks"
                    :key="link.route"
                    :href="route(link.route)"
                    :class="[
                        'group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-all duration-150',
                        isActive(link)
                            ? 'border-l-[3px] border-red-500 bg-red-500/10 text-white'
                            : 'border-l-[3px] border-transparent text-gray-400 hover:bg-white/5 hover:text-white',
                    ]"
                >
                    <!-- Dashboard icon -->
                    <svg v-if="link.icon === 'dashboard'" class="h-5 w-5 shrink-0" :class="isActive(link) ? 'text-red-400' : 'text-gray-500 group-hover:text-gray-300'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
                    </svg>
                    <!-- Empresas icon -->
                    <svg v-if="link.icon === 'empresas'" class="h-5 w-5 shrink-0" :class="isActive(link) ? 'text-red-400' : 'text-gray-500 group-hover:text-gray-300'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3H21m-3.75 3H21" />
                    </svg>
                    {{ link.label }}
                </Link>
            </nav>

            <!-- User section -->
            <div class="border-t border-white/10 p-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-br from-red-600 to-orange-500 text-sm font-bold text-white">
                        {{ page.props.auth.user.name.charAt(0) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-white">{{ page.props.auth.user.name }}</p>
                        <p class="truncate text-xs text-gray-500">{{ page.props.auth.user.email }}</p>
                    </div>
                </div>
                <div class="mt-3 flex gap-2">
                    <Link :href="route('profile.edit')" class="flex-1 rounded-md bg-white/5 px-3 py-1.5 text-center text-xs font-medium text-gray-400 transition hover:bg-white/10 hover:text-white">
                        Perfil
                    </Link>
                    <Link :href="route('logout')" method="post" as="button" class="flex-1 rounded-md bg-white/5 px-3 py-1.5 text-center text-xs font-medium text-gray-400 transition hover:bg-red-500/20 hover:text-red-400">
                        Salir
                    </Link>
                </div>
            </div>
        </aside>

        <!-- Main content -->
        <div class="lg:pl-64">
            <!-- Top bar (mobile) -->
            <header class="sticky top-0 z-30 flex h-16 items-center gap-4 border-b border-gray-200 bg-white px-4 lg:px-8">
                <button
                    @click="sidebarOpen = true"
                    class="rounded-md p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-700 lg:hidden"
                >
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                </button>

                <!-- Page heading -->
                <div class="flex flex-1 items-center justify-between">
                    <div>
                        <slot name="header" />
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="hidden rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700 sm:inline-flex">
                            Superadmin
                        </span>
                    </div>
                </div>
            </header>

            <!-- Page content -->
            <main class="p-4 lg:p-8">
                <slot />
            </main>
        </div>
    </div>
</template>
