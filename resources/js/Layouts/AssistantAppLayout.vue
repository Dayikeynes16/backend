<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const tenantName = computed(() => page.props.auth.tenant?.name || 'Mi negocio');
const branchName = computed(() => page.props.auth.branch?.name || null);
</script>

<template>
    <div
        class="flex h-[100dvh] flex-col bg-gray-50"
        style="padding-top: env(safe-area-inset-top); padding-left: env(safe-area-inset-left); padding-right: env(safe-area-inset-right);"
    >
        <header class="flex h-14 shrink-0 items-center justify-between gap-3 border-b border-gray-200 bg-white px-4">
            <div class="flex min-w-0 items-center gap-2.5">
                <img
                    v-if="page.props.auth.tenant?.logo_url"
                    :src="page.props.auth.tenant.logo_url"
                    :alt="tenantName"
                    class="h-8 w-8 rounded-lg object-cover"
                />
                <div v-else class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-orange-500 to-red-600 text-sm font-bold text-white">
                    {{ tenantName.charAt(0).toUpperCase() }}
                </div>
                <div class="min-w-0">
                    <p class="truncate text-sm font-bold leading-tight text-gray-900">{{ tenantName }}</p>
                    <p v-if="branchName" class="truncate text-xs leading-tight text-gray-500">{{ branchName }}</p>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-1.5">
                <slot name="header-actions" />
                <Link
                    :href="route('dashboard')"
                    class="flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:border-orange-400 hover:bg-orange-50 hover:text-orange-800"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                    </svg>
                    Salir al panel
                </Link>
            </div>
        </header>

        <main class="flex min-h-0 flex-1 flex-col">
            <slot />
        </main>
    </div>
</template>
