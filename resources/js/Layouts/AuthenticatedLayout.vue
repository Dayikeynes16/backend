<script setup>
import { ref, computed } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link, usePage } from '@inertiajs/vue3';

const showingNavigationDropdown = ref(false);

const page = usePage();
const role = computed(() => page.props.auth.role);
const slug = computed(() => page.props.auth.tenant_slug);

const navLinks = computed(() => {
    const s = slug.value;
    switch (role.value) {
        case 'superadmin':
            return [
                { label: 'Dashboard', route: 'admin.dashboard', params: [] },
                { label: 'Empresas', route: 'admin.empresas.index', params: [] },
            ];
        case 'admin-empresa':
            return [
                { label: 'Dashboard', route: 'empresa.dashboard', params: [s] },
                { label: 'Sucursales', route: 'empresa.sucursales.index', params: [s] },
                { label: 'Usuarios', route: 'empresa.usuarios.index', params: [s] },
            ];
        case 'admin-sucursal':
            return [
                { label: 'Dashboard', route: 'sucursal.dashboard', params: [s] },
                { label: 'Productos', route: 'sucursal.productos.index', params: [s] },
                { label: 'Cajeros', route: 'sucursal.usuarios.index', params: [s] },
                { label: 'API Keys', route: 'sucursal.api-keys.index', params: [s] },
                { label: 'Cortes', route: 'sucursal.cortes.index', params: [s] },
            ];
        case 'cajero':
            return [
                { label: 'Cola', route: 'caja.queue', params: [s] },
                { label: 'Dashboard', route: 'caja.dashboard', params: [s] },
                { label: 'Corte', route: 'caja.shift.show', params: [s] },
            ];
        default:
            return [];
    }
});
</script>

<template>
    <div>
        <div class="min-h-screen bg-gray-100 dark:bg-gray-900">
            <nav class="border-b border-gray-100 bg-white dark:border-gray-700 dark:bg-gray-800">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 justify-between">
                        <div class="flex">
                            <div class="flex shrink-0 items-center">
                                <Link :href="route('dashboard')">
                                    <ApplicationLogo class="block h-9 w-auto fill-current text-gray-800 dark:text-gray-200" />
                                </Link>
                            </div>

                            <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                                <NavLink
                                    v-for="link in navLinks"
                                    :key="link.route"
                                    :href="route(link.route, ...link.params)"
                                    :active="route().current(link.route)"
                                >
                                    {{ link.label }}
                                </NavLink>
                            </div>
                        </div>

                        <div class="hidden sm:ms-6 sm:flex sm:items-center">
                            <span class="mr-3 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                {{ role }}
                            </span>
                            <div class="relative ms-3">
                                <Dropdown align="right" width="48">
                                    <template #trigger>
                                        <span class="inline-flex rounded-md">
                                            <button type="button" class="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none dark:bg-gray-800 dark:text-gray-400 dark:hover:text-gray-300">
                                                {{ $page.props.auth.user.name }}
                                                <svg class="-me-0.5 ms-2 h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>
                                    <template #content>
                                        <DropdownLink :href="route('profile.edit')">Perfil</DropdownLink>
                                        <DropdownLink :href="route('logout')" method="post" as="button">Cerrar Sesion</DropdownLink>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>

                        <div class="-me-2 flex items-center sm:hidden">
                            <button @click="showingNavigationDropdown = !showingNavigationDropdown"
                                class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none dark:text-gray-500 dark:hover:bg-gray-900 dark:hover:text-gray-400 dark:focus:bg-gray-900 dark:focus:text-gray-400">
                                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                    <path :class="{ hidden: showingNavigationDropdown, 'inline-flex': !showingNavigationDropdown }" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                                    <path :class="{ hidden: !showingNavigationDropdown, 'inline-flex': showingNavigationDropdown }" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div :class="{ block: showingNavigationDropdown, hidden: !showingNavigationDropdown }" class="sm:hidden">
                    <div class="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink
                            v-for="link in navLinks"
                            :key="link.route"
                            :href="route(link.route, ...link.params)"
                            :active="route().current(link.route)"
                        >
                            {{ link.label }}
                        </ResponsiveNavLink>
                    </div>

                    <div class="border-t border-gray-200 pb-1 pt-4 dark:border-gray-600">
                        <div class="px-4">
                            <div class="text-base font-medium text-gray-800 dark:text-gray-200">{{ $page.props.auth.user.name }}</div>
                            <div class="text-sm font-medium text-gray-500">{{ $page.props.auth.user.email }}</div>
                        </div>
                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink :href="route('profile.edit')">Perfil</ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('logout')" method="post" as="button">Cerrar Sesion</ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            <header class="bg-white shadow dark:bg-gray-800" v-if="$slots.header">
                <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
