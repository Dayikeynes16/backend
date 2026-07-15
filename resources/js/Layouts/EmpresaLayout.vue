<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AgendaBell from '@/Components/Agenda/AgendaBell.vue';
import VerifyEmailBanner from '@/Components/VerifyEmailBanner.vue';

const page = usePage();
const sidebarOpen = ref(false);
const slug = computed(() => page.props.auth.tenant_slug);

const webOrders = computed(() => page.props.features?.webOrders ?? false);

const baseNavLinks = [
    { label: 'Dashboard', route: 'empresa.dashboard', icon: 'dashboard' },
    { label: 'Agenda', route: 'agenda.index', match: 'agenda', icon: 'agenda' },
    { label: 'Sucursales', route: 'empresa.sucursales.index', match: 'empresa.sucursales', icon: 'sucursales' },
    { label: 'Usuarios', route: 'empresa.usuarios.index', match: 'empresa.usuarios', icon: 'usuarios' },
    { label: 'Tickets', route: 'empresa.tickets', icon: 'ticket' },
    // Compras agrupa Compras + Productos de compra + Proveedores (tabs en página; spec 2026-07-15).
    { label: 'Compras', route: 'empresa.compras.index', match: 'empresa.compras', extraMatch: ['empresa.productos-compra', 'empresa.proveedores'], icon: 'compras' },
    { label: 'Gastos', route: 'empresa.gastos.index', match: 'empresa.gastos', icon: 'gastos' },
    { label: 'Métricas', route: 'empresa.metricas.index', match: 'empresa.metricas', icon: 'metricas' },
    { label: 'Asistente', route: 'asistente.index', match: 'asistente.', icon: 'asistente' },
    { label: 'Personalizacion', route: 'empresa.personalizacion', icon: 'paint' },
    { label: 'Configuracion', route: 'empresa.configuracion', icon: 'config' },
];

const navLinks = computed(() =>
    webOrders.value ? baseNavLinks : baseNavLinks.filter(link => link.route !== 'empresa.personalizacion')
);

const isActive = (link) => {
    if (link.match) {
        if (route().current(link.match + '*') || route().current(link.route)) return true;
        const extras = Array.isArray(link.extraMatch) ? link.extraMatch : (link.extraMatch ? [link.extraMatch] : []);
        if (extras.some((m) => route().current(m + '*'))) return true;
    }
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
                <img v-if="page.props.auth.tenant?.logo_url" :src="page.props.auth.tenant.logo_url" :alt="page.props.auth.tenant.name"
                    class="h-10 w-10 rounded-xl object-cover ring-1 ring-white/20" />
                <div v-else class="flex h-10 w-10 items-center justify-center rounded-xl bg-white/10 text-base font-bold text-white ring-1 ring-white/20">
                    {{ (page.props.auth.tenant?.name || '?').charAt(0).toUpperCase() }}
                </div>
                <div class="flex min-w-0 flex-col">
                    <span class="truncate text-sm font-bold leading-tight tracking-wide text-white">{{ page.props.auth.tenant?.name || 'Empresa' }}</span>
                    <span class="truncate text-xs font-semibold leading-tight text-orange-300">{{ page.props.auth.branch?.name || 'Admin Empresa' }}</span>
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
                        <!-- Agenda -->
                        <svg v-if="link.icon === 'agenda'" class="h-5 w-5 shrink-0 transition-colors" :class="isActive(link) ? 'text-white' : 'text-red-300 group-hover:text-white'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                        <!-- Sucursales -->
                        <svg v-if="link.icon === 'sucursales'" class="h-5 w-5 shrink-0 transition-colors" :class="isActive(link) ? 'text-white' : 'text-red-300 group-hover:text-white'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 0 0 3.75.614m-16.5 0a3.004 3.004 0 0 1-.621-4.72l1.189-1.19A1.5 1.5 0 0 1 5.378 3h13.243a1.5 1.5 0 0 1 1.06.44l1.19 1.189a3 3 0 0 1-.621 4.72M6.75 18h3.75a.75.75 0 0 0 .75-.75V13.5a.75.75 0 0 0-.75-.75H6.75a.75.75 0 0 0-.75.75v3.75c0 .414.336.75.75.75Z" /></svg>
                        <!-- Usuarios -->
                        <svg v-if="link.icon === 'usuarios'" class="h-5 w-5 shrink-0 transition-colors" :class="isActive(link) ? 'text-white' : 'text-red-300 group-hover:text-white'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                        <!-- Tickets -->
                        <svg v-if="link.icon === 'ticket'" class="h-5 w-5 shrink-0 transition-colors" :class="isActive(link) ? 'text-white' : 'text-red-300 group-hover:text-white'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z" /></svg>
                        <!-- Compras -->
                        <svg v-if="link.icon === 'compras'" class="h-5 w-5 shrink-0 transition-colors" :class="isActive(link) ? 'text-white' : 'text-red-300 group-hover:text-white'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" /></svg>
                        <!-- Proveedores -->
                        <svg v-if="link.icon === 'proveedores'" class="h-5 w-5 shrink-0 transition-colors" :class="isActive(link) ? 'text-white' : 'text-red-300 group-hover:text-white'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 7.125C2.25 6.504 2.754 6 3.375 6h6c.621 0 1.125.504 1.125 1.125v3.75c0 .621-.504 1.125-1.125 1.125h-6a1.125 1.125 0 0 1-1.125-1.125v-3.75ZM14.25 8.625c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v8.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 0 1-1.125-1.125v-8.25ZM3.75 16.125c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v2.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 0 1-1.125-1.125v-2.25Z" /></svg>
                        <!-- Gastos -->
                        <svg v-if="link.icon === 'gastos'" class="h-5 w-5 shrink-0 transition-colors" :class="isActive(link) ? 'text-white' : 'text-red-300 group-hover:text-white'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                        <!-- Métricas -->
                        <svg v-if="link.icon === 'metricas'" class="h-5 w-5 shrink-0 transition-colors" :class="isActive(link) ? 'text-white' : 'text-red-300 group-hover:text-white'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
                        <!-- Asistente -->
                        <svg v-if="link.icon === 'asistente'" class="h-5 w-5 shrink-0 transition-colors" :class="isActive(link) ? 'text-white' : 'text-red-300 group-hover:text-white'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.847-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.091L15.75 12l-2.847.813a4.5 4.5 0 0 0-3.09 3.091ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.456-2.456L14.25 6l1.035-.259a3.375 3.375 0 0 0 2.456-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" /></svg>
                        <!-- Paint / Personalización -->
                        <svg v-if="link.icon === 'paint'" class="h-5 w-5 shrink-0 transition-colors" :class="isActive(link) ? 'text-white' : 'text-red-300 group-hover:text-white'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 0 0-5.78 1.128 2.25 2.25 0 0 1-2.4 2.245 4.5 4.5 0 0 0 8.4-2.245c0-.399-.078-.78-.22-1.128Zm0 0a15.998 15.998 0 0 0 3.388-1.62m-5.043-.025a15.994 15.994 0 0 1 1.622-3.395m3.42 3.42a15.995 15.995 0 0 0 4.764-4.648l3.876-5.814a1.151 1.151 0 0 0-1.597-1.597L14.146 6.32a15.996 15.996 0 0 0-4.649 4.763m3.42 3.42a6.776 6.776 0 0 0-3.42-3.42" /></svg>
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
                    <div class="flex items-center gap-2">
                        <AgendaBell />
                        <span class="hidden rounded-full bg-orange-100 px-3 py-1 text-xs font-bold text-orange-700 sm:inline-flex">Admin Empresa</span>
                    </div>
                </div>
            </header>
            <VerifyEmailBanner />
            <main class="p-5 lg:p-8"><slot /></main>
        </div>
    </div>
</template>
