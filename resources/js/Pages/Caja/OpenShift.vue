<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, router, usePage } from '@inertiajs/vue3';

const props = defineProps({ tenant: Object });
const flash = usePage().props.flash;

const openShift = () => {
    router.post(route('caja.shift.store', props.tenant.slug));
};
</script>

<template>
    <Head title="Abrir Turno" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Abrir Turno</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-lg sm:px-6 lg:px-8">
                <div v-if="flash?.success" class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-800">
                    {{ flash.success }}
                </div>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-8 text-center">
                        <svg class="mx-auto h-16 w-16 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                        <h3 class="mt-4 text-lg font-semibold text-gray-900">
                            No tienes un turno abierto
                        </h3>
                        <p class="mt-2 text-sm text-gray-500">
                            Abre tu turno para empezar a cobrar ventas.
                        </p>
                        <PrimaryButton @click="openShift" class="mt-6">
                            Abrir Turno
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
