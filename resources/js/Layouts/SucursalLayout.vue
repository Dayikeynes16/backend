<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const sidebarOpen = ref(false);
const slug = computed(() => page.props.auth.tenant_slug);

const navLinks = [
    { label: 'Dashboard', route: 'sucursal.dashboard', icon: 'dashboard' },
    { label: 'Categorias', route: 'sucursal.categorias.index', match: 'sucursal.categorias', icon: 'categorias' },
    { label: 'Productos', route: 'sucursal.productos.index', match: 'sucursal.productos', icon: 'productos' },
    { label: 'Mesa de Trabajo', route: 'sucursal.workbench', icon: 'workbench' },
    { label: 'Pagos', route: 'sucursal.pagos.index', icon: 'pagos' },
    { label: 'Historial', route: 'sucursal.historial.index', icon: 'historial' },
    { label: 'Mi Turno', route: 'sucursal.turno.active', icon: 'turno' },
    { label: 'Clientes', route: 'sucursal.clientes.index', icon: 'clientes' },
    { label: 'Cancelaciones', route: 'sucursal.cancelaciones.index', icon: 'cancelaciones' },
    { label: 'Cortes', route: 'sucursal.cortes.index', match: 'sucursal.cortes', icon: 'cortes' },
    { label: 'Configuracion', route: 'sucursal.configuracion', icon: 'config' },
];

const isActive = (link) => {
    if (link.match) return route().current(link.match + '*') || route().current(link.route);
    return route().current(link.route);
};

const iconPaths = {
    dashboard: 'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z',
    categorias: 'M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z M6 6h.008v.008H6V6Z',
    productos: 'M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z',
    workbench: 'M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z',
    pagos: 'M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    historial: 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
    turno: 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    clientes: 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z',
    cancelaciones: 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z',
    cortes: 'M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z',
    apikey: 'M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z',
    config: 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
};
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <Transition enter-active-class="transition-opacity duration-300" leave-active-class="transition-opacity duration-200" enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="sidebarOpen" class="fixed inset-0 z-40 bg-black/40 backdrop-blur-sm lg:hidden" @click="sidebarOpen = false" />
        </Transition>

        <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'" class="fixed inset-y-0 left-0 z-50 flex w-[264px] flex-col bg-gradient-to-b from-red-800 via-red-850 to-red-900 transition-transform duration-300 lg:translate-x-0">
            <div class="flex h-[72px] items-center gap-3.5 px-6">
                <img src="/logo.png" alt="El Puebla" class="h-10 w-10 rounded-xl" />
                <div class="flex flex-col">
                    <span class="text-sm font-bold leading-tight tracking-wide text-white">El Puebla</span>
                    <span class="text-xs font-semibold leading-tight text-orange-300">Sucursal</span>
                </div>
            </div>

            <nav class="flex-1 overflow-y-auto px-4 pt-6">
                <p class="mb-3 px-3 text-xs font-bold uppercase tracking-[0.15em] text-red-300/60">Menu</p>
                <div class="space-y-1">
                    <Link v-for="link in navLinks" :key="link.route" :href="route(link.route, slug)"
                        :class="['group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-all duration-200',
                            isActive(link) ? 'border-l-[3px] border-white bg-white/15 text-white shadow-sm shadow-black/10' : 'border-l-[3px] border-transparent text-red-100 hover:bg-white/10 hover:text-white']">
                        <svg class="h-5 w-5 shrink-0 transition-colors" :class="isActive(link) ? 'text-white' : 'text-red-300 group-hover:text-white'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" :d="iconPaths[link.icon]" />
                        </svg>
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
                    <span class="hidden rounded-full bg-orange-100 px-3 py-1 text-xs font-bold text-orange-700 sm:inline-flex">Admin Sucursal</span>
                </div>
            </header>
            <main class="p-5 lg:p-8"><slot /></main>
        </div>
    </div>
</template>
