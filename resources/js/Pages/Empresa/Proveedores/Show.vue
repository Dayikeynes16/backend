<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    provider: { type: Object, required: true },
});

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);

const fmt = (n) => '$' + Number(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <Head :title="provider.name" />
    <EmpresaLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('empresa.proveedores.index', slug)" class="text-sm text-orange-700 hover:underline">← Proveedores</Link>
                <h1 class="text-lg font-bold text-gray-900">{{ provider.name }}</h1>
            </div>
        </template>

        <div class="space-y-5">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Tipo</div>
                    <div class="text-base font-semibold text-gray-900">{{ provider.type_label }}</div>
                </div>
                <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500"># Compras</div>
                    <div class="text-2xl font-bold text-gray-900">{{ provider.purchases_count }}</div>
                </div>
                <div class="rounded-2xl border border-amber-100 bg-white p-4 shadow-sm">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Saldo pendiente</div>
                    <div class="text-2xl font-bold" :class="provider.pending_total > 0 ? 'text-amber-700' : 'text-gray-400'">
                        {{ provider.pending_total > 0 ? fmt(provider.pending_total) : '—' }}
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <h3 class="mb-3 text-sm font-bold uppercase tracking-wide text-gray-600">Contacto</h3>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-gray-500">Teléfono</dt><dd class="text-gray-900">{{ provider.phone || '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-gray-500">Email</dt><dd class="text-gray-900">{{ provider.email || '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-gray-500">RFC</dt><dd class="text-gray-900">{{ provider.rfc || '—' }}</dd></div>
                        <div v-if="provider.address" class="pt-2"><dt class="text-gray-500">Dirección</dt><dd class="text-gray-900">{{ provider.address }}</dd></div>
                    </dl>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <h3 class="mb-3 text-sm font-bold uppercase tracking-wide text-gray-600">Términos</h3>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-gray-500">Estado</dt><dd class="text-gray-900">{{ provider.status === 'active' ? 'Activo' : 'Inactivo' }}</dd></div>
                    </dl>
                    <p v-if="provider.notes" class="mt-3 rounded-lg bg-gray-50 p-3 text-sm text-gray-700">{{ provider.notes }}</p>
                </div>
            </div>

            <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-6 text-center text-sm text-gray-500">
                Las compras y pagos a este proveedor aparecerán aquí cuando habilitemos F2 (Compras).
            </div>
        </div>
    </EmpresaLayout>
</template>
