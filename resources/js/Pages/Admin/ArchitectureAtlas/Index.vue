<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
</script>

<template>
    <Head title="Atlas del sistema" />

    <AdminLayout>
        <template #header>
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-red-600">Arquitectura</p>
                <h1 class="text-xl font-bold text-gray-900">Atlas del sistema</h1>
            </div>
        </template>

        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <p class="text-sm text-gray-600">Cargando mapa del ecosistema…</p>
        </section>
    </AdminLayout>
</template>
