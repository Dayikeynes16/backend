<script setup>
import CajeroLayout from '@/Layouts/CajeroLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({ tenant: Object });
const form = useForm({ opening_amount: '' });
const submit = () => form.post(route('caja.turno.open', props.tenant.slug));
</script>

<template>
    <Head title="Abrir Turno" />
    <CajeroLayout>
        <template #header><h1 class="text-xl font-bold text-gray-900">Abrir Turno</h1></template>
        <div class="mx-auto max-w-lg">
            <div class="rounded-xl bg-white p-8 shadow-sm ring-1 ring-gray-100">
                <div class="text-center mb-8">
                    <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-red-500 to-orange-500 shadow-lg">
                        <svg class="h-8 w-8 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    </div>
                    <h2 class="text-lg font-bold text-gray-900">Iniciar turno</h2>
                    <p class="mt-2 text-sm text-gray-500">Si recibiste fondo de cambio, registralo. Si no, dejalo vacio.</p>
                </div>
                <form @submit.prevent="submit" class="space-y-6">
                    <div>
                        <label class="text-sm font-medium text-gray-700">Fondo de caja (opcional)</label>
                        <div class="relative mt-1.5">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">$</span>
                            <TextInput v-model="form.opening_amount" type="number" step="0.01" min="0" placeholder="0.00" class="block w-full pl-7" />
                        </div>
                    </div>
                    <button type="submit" :disabled="form.processing" class="w-full rounded-lg bg-red-600 py-3 text-sm font-bold text-white transition hover:bg-red-700 disabled:opacity-50">Abrir Turno</button>
                </form>
            </div>
        </div>
        <FlashToast />
    </CajeroLayout>
</template>
