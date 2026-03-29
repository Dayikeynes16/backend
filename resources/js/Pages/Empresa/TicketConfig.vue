<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({ branches: Array, tenant: Object });

const selectedBranchId = ref(props.branches[0]?.id || null);
const selectedBranch = computed(() => props.branches.find(b => b.id === selectedBranchId.value));

const defaults = {
    header_business_name: true,
    header_branch_name: true,
    header_address: true,
    header_phone: true,
    header_custom: '',
    show_date: true,
    show_folio: true,
    show_cashier: true,
    show_payment_method: true,
    footer_message: 'Gracias por su compra',
    footer_custom: '',
    width: '80mm',
};

const form = useForm({ ticket_config: { ...defaults } });

// Load config when branch changes
watch(selectedBranchId, () => {
    const branch = selectedBranch.value;
    if (branch) {
        form.ticket_config = { ...defaults, ...(branch.ticket_config || {}) };
    }
}, { immediate: true });

const save = () => {
    form.put(route('empresa.tickets.update', [props.tenant.slug, selectedBranchId.value]), {
        preserveScroll: true,
    });
};

// Mock data for preview
const mock = {
    folio: 'S-00042',
    date: new Date().toLocaleDateString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric' }),
    time: new Date().toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', hour12: true }),
    cashier: 'Maria Lopez',
    items: [
        { name: 'Bistec de res', qty: '1.250 kg', price: 189.50, subtotal: 236.88 },
        { name: 'Chuleta de cerdo', qty: '0.800 kg', price: 145.00, subtotal: 116.00 },
        { name: 'Queso Oaxaca 500g', qty: '1 pz', price: 85.00, subtotal: 85.00 },
    ],
    total: 437.88,
    payments: [{ method: 'Efectivo', amount: 500.00 }],
    change: 62.12,
};

const c = computed(() => form.ticket_config);
</script>

<template>
    <Head title="Configuracion de Tickets" />
    <EmpresaLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Configuracion de Tickets</h1>
        </template>

        <div class="mx-auto max-w-6xl">
            <!-- Branch selector -->
            <div class="mb-6 flex items-center gap-3">
                <label class="text-sm font-medium text-gray-700">Sucursal:</label>
                <div class="flex gap-2">
                    <button v-for="branch in branches" :key="branch.id" @click="selectedBranchId = branch.id"
                        :class="['rounded-lg px-4 py-2 text-sm font-semibold transition',
                            selectedBranchId === branch.id ? 'bg-red-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50']">
                        {{ branch.name }}
                    </button>
                </div>
            </div>

            <div class="flex gap-8">
                <!-- LEFT: Config panel -->
                <div class="flex-1 space-y-6">
                    <!-- Header config -->
                    <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                        <div class="border-b border-gray-100 px-6 py-4">
                            <h2 class="text-sm font-bold text-gray-900">Encabezado</h2>
                            <p class="mt-0.5 text-xs text-gray-500">Informacion que aparece en la parte superior del ticket.</p>
                        </div>
                        <div class="space-y-3 p-6">
                            <label class="flex items-center justify-between rounded-lg p-3 ring-1 ring-gray-100 transition hover:bg-gray-50 cursor-pointer">
                                <span class="text-sm text-gray-700">Nombre del negocio</span>
                                <input type="checkbox" v-model="form.ticket_config.header_business_name" class="h-5 w-5 rounded border-gray-300 text-red-600 focus:ring-red-500" />
                            </label>
                            <label class="flex items-center justify-between rounded-lg p-3 ring-1 ring-gray-100 transition hover:bg-gray-50 cursor-pointer">
                                <span class="text-sm text-gray-700">Nombre de sucursal</span>
                                <input type="checkbox" v-model="form.ticket_config.header_branch_name" class="h-5 w-5 rounded border-gray-300 text-red-600 focus:ring-red-500" />
                            </label>
                            <label class="flex items-center justify-between rounded-lg p-3 ring-1 ring-gray-100 transition hover:bg-gray-50 cursor-pointer">
                                <span class="text-sm text-gray-700">Direccion</span>
                                <input type="checkbox" v-model="form.ticket_config.header_address" class="h-5 w-5 rounded border-gray-300 text-red-600 focus:ring-red-500" />
                            </label>
                            <label class="flex items-center justify-between rounded-lg p-3 ring-1 ring-gray-100 transition hover:bg-gray-50 cursor-pointer">
                                <span class="text-sm text-gray-700">Telefono</span>
                                <input type="checkbox" v-model="form.ticket_config.header_phone" class="h-5 w-5 rounded border-gray-300 text-red-600 focus:ring-red-500" />
                            </label>
                            <div>
                                <label class="text-xs font-medium text-gray-500">Texto personalizado (opcional)</label>
                                <input v-model="form.ticket_config.header_custom" type="text" placeholder="Ej: RFC, slogan, aviso..." maxlength="200"
                                    class="mt-1 block w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />
                            </div>
                        </div>
                    </section>

                    <!-- Body config -->
                    <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                        <div class="border-b border-gray-100 px-6 py-4">
                            <h2 class="text-sm font-bold text-gray-900">Contenido</h2>
                            <p class="mt-0.5 text-xs text-gray-500">Datos que se muestran junto con los productos.</p>
                        </div>
                        <div class="space-y-3 p-6">
                            <label class="flex items-center justify-between rounded-lg p-3 ring-1 ring-gray-100 transition hover:bg-gray-50 cursor-pointer">
                                <span class="text-sm text-gray-700">Fecha y hora</span>
                                <input type="checkbox" v-model="form.ticket_config.show_date" class="h-5 w-5 rounded border-gray-300 text-red-600 focus:ring-red-500" />
                            </label>
                            <label class="flex items-center justify-between rounded-lg p-3 ring-1 ring-gray-100 transition hover:bg-gray-50 cursor-pointer">
                                <span class="text-sm text-gray-700">Folio de venta</span>
                                <input type="checkbox" v-model="form.ticket_config.show_folio" class="h-5 w-5 rounded border-gray-300 text-red-600 focus:ring-red-500" />
                            </label>
                            <label class="flex items-center justify-between rounded-lg p-3 ring-1 ring-gray-100 transition hover:bg-gray-50 cursor-pointer">
                                <span class="text-sm text-gray-700">Nombre del cajero</span>
                                <input type="checkbox" v-model="form.ticket_config.show_cashier" class="h-5 w-5 rounded border-gray-300 text-red-600 focus:ring-red-500" />
                            </label>
                            <label class="flex items-center justify-between rounded-lg p-3 ring-1 ring-gray-100 transition hover:bg-gray-50 cursor-pointer">
                                <span class="text-sm text-gray-700">Metodo de pago</span>
                                <input type="checkbox" v-model="form.ticket_config.show_payment_method" class="h-5 w-5 rounded border-gray-300 text-red-600 focus:ring-red-500" />
                            </label>
                        </div>
                    </section>

                    <!-- Footer config -->
                    <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                        <div class="border-b border-gray-100 px-6 py-4">
                            <h2 class="text-sm font-bold text-gray-900">Pie de ticket</h2>
                        </div>
                        <div class="space-y-4 p-6">
                            <div>
                                <label class="text-xs font-medium text-gray-500">Mensaje principal</label>
                                <input v-model="form.ticket_config.footer_message" type="text" placeholder="Gracias por su compra" maxlength="200"
                                    class="mt-1 block w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />
                            </div>
                            <div>
                                <label class="text-xs font-medium text-gray-500">Texto adicional (opcional)</label>
                                <textarea v-model="form.ticket_config.footer_custom" rows="2" placeholder="Horarios, promociones, aviso legal..." maxlength="300"
                                    class="block w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />
                            </div>
                        </div>
                    </section>

                    <!-- Width + Save -->
                    <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                        <div class="flex items-center justify-between p-6">
                            <div class="flex items-center gap-4">
                                <span class="text-sm font-medium text-gray-700">Ancho de impresion:</span>
                                <div class="flex rounded-lg bg-gray-100 p-1">
                                    <button type="button" @click="form.ticket_config.width = '58mm'"
                                        :class="['rounded-md px-3 py-1.5 text-xs font-bold transition', c.width === '58mm' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500']">
                                        58mm
                                    </button>
                                    <button type="button" @click="form.ticket_config.width = '80mm'"
                                        :class="['rounded-md px-3 py-1.5 text-xs font-bold transition', c.width === '80mm' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500']">
                                        80mm
                                    </button>
                                </div>
                            </div>
                            <button @click="save" :disabled="form.processing" class="rounded-lg bg-red-600 px-6 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50">
                                Guardar
                            </button>
                        </div>
                    </section>
                </div>

                <!-- RIGHT: Live preview -->
                <div class="w-[320px] shrink-0">
                    <div class="sticky top-6">
                        <p class="mb-3 text-xs font-bold uppercase tracking-wider text-gray-400">Vista previa</p>
                        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                            <div class="rounded-lg border border-dashed border-gray-300 bg-white p-4" style="font-family: monospace; font-size: 12px; line-height: 1.5;">
                                <!-- Header -->
                                <div class="text-center">
                                    <p v-if="c.header_business_name" class="font-bold text-sm">{{ tenant.name }}</p>
                                    <p v-if="c.header_branch_name && selectedBranch" class="text-xs">{{ selectedBranch.name }}</p>
                                    <p v-if="c.header_address && selectedBranch?.address" class="text-xs text-gray-500">{{ selectedBranch.address }}</p>
                                    <p v-if="c.header_phone && selectedBranch?.phone" class="text-xs text-gray-500">Tel: {{ selectedBranch.phone }}</p>
                                    <p v-if="c.header_custom" class="text-xs text-gray-500">{{ c.header_custom }}</p>
                                </div>

                                <div class="my-2 border-t border-dashed border-gray-300" />

                                <!-- Meta -->
                                <div class="space-y-0.5">
                                    <p v-if="c.show_folio" class="font-bold">{{ mock.folio }}</p>
                                    <p v-if="c.show_date" class="text-xs text-gray-500">{{ mock.date }} {{ mock.time }}</p>
                                    <p v-if="c.show_cashier" class="text-xs text-gray-500">Cajero: {{ mock.cashier }}</p>
                                </div>

                                <div class="my-2 border-t border-dashed border-gray-300" />

                                <!-- Items -->
                                <div class="space-y-1">
                                    <div v-for="item in mock.items" :key="item.name" class="flex justify-between text-xs">
                                        <span class="flex-1 truncate">{{ item.name }} x{{ item.qty }}</span>
                                        <span class="ml-2 font-medium">${{ item.subtotal.toFixed(2) }}</span>
                                    </div>
                                </div>

                                <div class="my-2 border-t border-dashed border-gray-300" />

                                <!-- Total -->
                                <div class="flex justify-between font-bold text-sm">
                                    <span>TOTAL</span>
                                    <span>${{ mock.total.toFixed(2) }}</span>
                                </div>

                                <!-- Payments -->
                                <div v-if="c.show_payment_method" class="mt-1">
                                    <div v-for="p in mock.payments" :key="p.method" class="flex justify-between text-xs text-gray-600">
                                        <span>{{ p.method }}</span>
                                        <span>${{ p.amount.toFixed(2) }}</span>
                                    </div>
                                    <div class="flex justify-between text-xs font-semibold text-green-700">
                                        <span>Cambio</span>
                                        <span>${{ mock.change.toFixed(2) }}</span>
                                    </div>
                                </div>

                                <div class="my-2 border-t border-dashed border-gray-300" />

                                <!-- Footer -->
                                <div class="text-center">
                                    <p class="text-xs font-bold">PAGADO</p>
                                    <p v-if="c.footer_message" class="mt-1 text-xs text-gray-500">{{ c.footer_message }}</p>
                                    <p v-if="c.footer_custom" class="mt-0.5 text-xs text-gray-400" style="white-space: pre-line;">{{ c.footer_custom }}</p>
                                </div>
                            </div>
                        </div>
                        <p class="mt-2 text-center text-xs text-gray-400">Ancho: {{ c.width }}</p>
                    </div>
                </div>
            </div>
        </div>

        <FlashToast />
    </EmpresaLayout>
</template>
