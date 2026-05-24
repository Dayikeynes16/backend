<script setup>
import FlashToast from '@/Components/FlashToast.vue';
import AgendaItemModal from '@/Components/Agenda/AgendaItemModal.vue';
import AgendaCapturaIAModal from '@/Components/Agenda/AgendaCapturaIAModal.vue';
import AgendaCalendar from '@/Components/Agenda/AgendaCalendar.vue';
import CajeroLayout from '@/Layouts/CajeroLayout.vue';
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    today: { type: Array, default: () => [] },
    upcoming: { type: Array, default: () => [] },
    alerts: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    assignableUsers: { type: Array, default: () => [] },
    tenant: { type: Object, required: true },
});

const page = usePage();
const role = computed(() => page.props.auth.role);

// `<component :is="...">` necesita el OBJETO del componente, no un string. Por eso
// importamos los 3 layouts y resolvemos según el rol que comparte HandleInertiaRequests.
const layouts = {
    'admin-empresa': EmpresaLayout,
    'admin-sucursal': SucursalLayout,
    cajero: CajeroLayout,
};
const Layout = computed(() => layouts[role.value] || SucursalLayout);

const tabs = [
    ['today', 'Hoy'],
    ['calendar', 'Calendario'],
    ['alerts', 'Alertas'],
];

const tab = ref('today');
const modalOpen = ref(false);
const editing = ref(null);
const prefill = ref(null);
const iaOpen = ref(false);

const openCreate = () => {
    editing.value = null;
    prefill.value = null;
    modalOpen.value = true;
};
const openEdit = (item) => {
    editing.value = item;
    prefill.value = null;
    modalOpen.value = true;
};
const closeModal = () => {
    modalOpen.value = false;
    editing.value = null;
    prefill.value = null;
};

// La IA devolvió una propuesta: abrimos el modal de CREAR pre-rellenado. Nada
// se guarda hasta que el usuario confirme (POST a agenda.store sin cambios).
const onProposal = (proposal) => {
    iaOpen.value = false;
    editing.value = null;
    prefill.value = proposal;
    modalOpen.value = true;
};
const complete = (item) => router.patch(route('agenda.complete', [props.tenant.slug, item.id]), {}, { preserveScroll: true });
const remove = (item) => {
    if (!confirm('¿Eliminar de la agenda?')) return;
    router.delete(route('agenda.destroy', [props.tenant.slug, item.id]), { preserveScroll: true });
};

const formatDateTime = (iso) => new Date(iso).toLocaleString('es-MX', {
    timeZone: 'America/Mexico_City',
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
});
const formatShort = (iso) => new Date(iso).toLocaleDateString('es-MX', {
    timeZone: 'America/Mexico_City',
    weekday: 'short',
    day: '2-digit',
    month: 'short',
});

const whatsappUrl = (alert) => {
    if (!alert.phone) return null;
    let num = String(alert.phone).replace(/[\s\-()]/g, '');
    if (/^\d{10}$/.test(num)) num = '52' + num;
    return `https://wa.me/${num}`;
};
</script>

<template>
    <Head title="Agenda" />
    <component :is="Layout">
        <template #header><h1 class="text-xl font-bold text-gray-900">Agenda</h1></template>

        <div class="mx-auto max-w-4xl space-y-5">
            <div class="flex items-center justify-between">
                <div class="flex gap-2">
                    <button v-for="t in tabs" :key="t[0]" type="button" @click="tab = t[0]"
                        :class="['rounded-xl px-4 py-2 text-sm font-bold transition', tab === t[0] ? 'bg-red-600 text-white' : 'bg-white text-gray-600 ring-1 ring-gray-200 hover:bg-gray-50']">
                        {{ t[1] }}<span v-if="t[0] === 'alerts' && alerts.length" class="ml-1 rounded-full bg-white/30 px-1.5 text-xs">{{ alerts.length }}</span>
                    </button>
                </div>
                <div class="flex gap-2">
                    <button type="button" @click="iaOpen = true"
                        class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:from-violet-700 hover:to-fuchsia-700">✨ Dictar</button>
                    <button type="button" @click="openCreate" class="rounded-xl bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700">+ Nuevo</button>
                </div>
            </div>

            <!-- HOY -->
            <div v-if="tab === 'today'" class="space-y-4">
                <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                    <p class="mb-2 text-xs font-bold uppercase tracking-wider text-gray-400">Hoy y pendiente</p>
                    <p v-if="!today.length" class="py-6 text-center text-sm text-gray-400">Nada pendiente. 🎉</p>
                    <div v-for="it in today" :key="it.id" class="flex items-center gap-3 border-b border-gray-50 py-2 last:border-0">
                        <button v-if="it.type === 'task'" type="button" @click="complete(it)"
                            class="h-5 w-5 shrink-0 rounded-full border-2 border-gray-300 hover:border-red-500" title="Marcar como hecho"></button>
                        <button type="button" @click="openEdit(it)" class="min-w-0 flex-1 text-left">
                            <p class="truncate text-sm font-semibold text-gray-900">{{ it.title }}</p>
                            <p v-if="it.starts_at" class="text-xs text-gray-400">{{ formatDateTime(it.starts_at) }}</p>
                        </button>
                        <a :href="route('agenda.ics', [tenant.slug, it.id])" class="text-xs font-medium text-gray-400 hover:text-gray-700" title="Descargar .ics">📅</a>
                        <button type="button" @click="remove(it)" class="text-xs text-gray-300 hover:text-red-600" title="Eliminar">✕</button>
                    </div>
                </div>
                <div v-if="upcoming.length" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                    <p class="mb-2 text-xs font-bold uppercase tracking-wider text-gray-400">Próximos</p>
                    <div v-for="it in upcoming" :key="it.id" class="flex items-center justify-between border-b border-gray-50 py-2 last:border-0">
                        <button type="button" @click="openEdit(it)" class="truncate text-left text-sm text-gray-700 hover:text-gray-900">{{ it.title }}</button>
                        <span class="text-xs text-gray-400">{{ formatShort(it.starts_at) }}</span>
                    </div>
                </div>
            </div>

            <!-- CALENDARIO -->
            <AgendaCalendar v-else-if="tab === 'calendar'" :tenant-slug="tenant.slug" />

            <!-- ALERTAS -->
            <div v-else class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                <p v-if="!alerts.length" class="py-6 text-center text-sm text-gray-400">Sin alertas. Todo al corriente.</p>
                <div v-for="a in alerts" :key="a.key" class="flex items-center gap-3 border-b border-gray-50 py-2.5 last:border-0">
                    <span :class="['h-2 w-2 shrink-0 rounded-full', a.severity === 'high' ? 'bg-red-500' : 'bg-amber-400']"></span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-gray-900">{{ a.title }}</p>
                        <p class="text-xs text-gray-400">{{ a.detail }}</p>
                    </div>
                    <span v-if="a.amount" class="text-sm font-bold tabular-nums text-gray-900">${{ Number(a.amount).toLocaleString('es-MX') }}</span>
                    <a v-if="whatsappUrl(a)" :href="whatsappUrl(a)" target="_blank" rel="noopener"
                        class="rounded-lg bg-green-50 px-2 py-1 text-xs font-bold text-green-700 hover:bg-green-100">WhatsApp</a>
                </div>
            </div>
        </div>

        <AgendaItemModal :open="modalOpen" :tenant-slug="tenant.slug" :branches="branches"
            :assignable-users="assignableUsers" :item="editing" :prefill="prefill" @close="closeModal" />
        <AgendaCapturaIAModal :open="iaOpen" :tenant-slug="tenant.slug" @close="iaOpen = false" @proposal="onProposal" />
        <FlashToast />
    </component>
</template>
