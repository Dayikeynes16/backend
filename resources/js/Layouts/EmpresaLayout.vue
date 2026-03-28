<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const sidebarOpen = ref(false);
const slug = computed(() => page.props.auth.tenant_slug);

const navLinks = [
    { label: 'Dashboard', route: 'empresa.dashboard', icon: 'dashboard' },
    { label: 'Sucursales', route: 'empresa.sucursales.index', match: 'empresa.sucursales', icon: 'sucursales' },
    { label: 'Usuarios', route: 'empresa.usuarios.index', match: 'empresa.usuarios', icon: 'usuarios' },
    { label: 'Configuracion', route: 'empresa.configuracion', icon: 'config' },
];

const isActive = (link) => {
    if (link.match) return route().current(link.match + '*') || route().current(link.route);
    return route().current(link.route);
};
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <Transition enter-active-class="transition-opacity duration-300" leave-active-class="transition-opacity duration-200" enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="sidebarOpen" class="fixed inset-0 z-40 bg-black/40 backdrop-blur-sm lg:hidden" @click="sidebarOpen = false" />
        </Transition>

        <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'" class="fixed inset-y-0 left-0 z-50 flex w-[264px] flex-col bg-gradient-to-b from-red-800 via-red-850 to-red-900 transition-transform duration-300 lg:translate-x-0">
            <!-- Brand -->
            <div class="flex h-[72px] items-center gap-3.5 px-6">
                <img src="/logo.png" alt="El Puebla" class="h-10 w-10 rounded-xl" />
                <div class="flex flex-col">
                    <span class="text-sm font-bold leading-tight tracking-wide text-white">El Puebla</span>
                    <span class="text-xs font-semibold leading-tight text-orange-300">Admin Empresa</span>
                </div>
            </div>

            <nav class="flex-1 px-4 pt-8">
                <p class="mb-3 px-3 text-xs font-bold uppercase tracking-[0.15em] text-red-300/60">Menu</p>
                <div class="space-y-1">
                    <Link v-for="link in navLinks" :key="link.route" :href="route(link.route, slug)"
                        :class="['group flex items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium transition-all duration-200',
                            isActive(link) ? 'border-l-[3px] border-white bg-white/15 text-white shadow-sm shadow-black/10' : 'border-l-[3px] border-transparent text-red-100 hover:bg-white/10 hover:text-white']">
                        <!-- Dashboard -->
                        <svg v-if="link.icon === 'dashboard'" class="h-5 w-5 shrink-0 transition-colors" :class="isActive(link) ? 'text-white' : 'text-red-300 group-hover:text-white'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" /></svg>
                        <!-- Sucursales -->
                        <svg v-if="link.icon === 'sucursales'" class="h-5 w-5 shrink-0 transition-colors" :class="isActive(link) ? 'text-white' : 'text-red-300 group-hover:text-white'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 0 0 3.75.614m-16.5 0a3.004 3.004 0 0 1-.621-4.72l1.189-1.19A1.5 1.5 0 0 1 5.378 3h13.243a1.5 1.5 0 0 1 1.06.44l1.19 1.189a3 3 0 0 1-.621 4.72M6.75 18h3.75a.75.75 0 0 0 .75-.75V13.5a.75.75 0 0 0-.75-.75H6.75a.75.75 0 0 0-.75.75v3.75c0 .414.336.75.75.75Z" /></svg>
                        <!-- Usuarios -->
                        <svg v-if="link.icon === 'usuarios'" class="h-5 w-5 shrink-0 transition-colors" :class="isActive(link) ? 'text-white' : 'text-red-300 group-hover:text-white'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                        <!-- Config -->
                        <svg v-if="link.icon === 'config'" class="h-5 w-5 shrink-0 transition-colors" :class="isActive(link) ? 'text-white' : 'text-red-300 group-hover:text-white'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                        {{ link.label }}
                    </Link>
                </div>
            </nav>

            <div class="bg-red-950/50 p-5">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-orange-400 to-red-500 text-sm font-bold text-white shadow-md shadow-black/20">{{ page.props.auth.user.name.charAt(0) }}</div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-white">{{ page.props.auth.user.name }}</p>
                        <p class="truncate text-xs text-red-200">{{ page.props.auth.user.email }}</p>
                    </div>
                </div>
                <div class="mt-3 flex gap-2">
                    <Link :href="route('profile.edit')" class="flex-1 rounded-lg bg-white/10 px-3 py-2 text-center text-xs font-medium text-red-100 transition hover:bg-white/20 hover:text-white">Perfil</Link>
                    <Link :href="route('logout')" method="post" as="button" class="flex-1 rounded-lg bg-white/10 px-3 py-2 text-center text-xs font-medium text-red-100 transition hover:bg-white/20 hover:text-white">Salir</Link>
                </div>
            </div>
        </aside>

        <div class="lg:pl-[264px]">
            <header class="sticky top-0 z-30 flex h-16 items-center gap-4 border-b border-gray-200 bg-white px-5 lg:px-8">
                <button @click="sidebarOpen = true" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-700 lg:hidden">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                </button>
                <div class="flex flex-1 items-center justify-between">
                    <slot name="header" />
                    <span class="hidden rounded-full bg-orange-100 px-3 py-1 text-xs font-bold text-orange-700 sm:inline-flex">Admin Empresa</span>
                </div>
            </header>
            <main class="p-5 lg:p-8"><slot /></main>
        </div>
    </div>
</template>
