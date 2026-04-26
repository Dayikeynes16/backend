<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({ sucursal: Object, tenant: Object });

const DAY_LABELS = { mon: 'Lun', tue: 'Mar', wed: 'Mié', thu: 'Jue', fri: 'Vie', sat: 'Sáb', sun: 'Dom' };
const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

// Resumen humano-legible (réplica de summarizeHours del backend para vista preview).
const hoursSummary = computed(() => {
    const hours = props.sucursal?.hours;
    if (!hours) return null;
    const perDay = {};
    for (const d of DAY_KEYS) {
        const v = hours[d];
        perDay[d] = (!v || !v.open || !v.close) ? 'cerrado' : `${v.open}-${v.close}`;
    }
    const groups = [];
    let startIdx = 0;
    for (let i = 1; i <= DAY_KEYS.length; i++) {
        const current = perDay[DAY_KEYS[startIdx]];
        const next = i < DAY_KEYS.length ? perDay[DAY_KEYS[i]] : null;
        if (current !== next) {
            const startLabel = DAY_LABELS[DAY_KEYS[startIdx]];
            const endLabel = DAY_LABELS[DAY_KEYS[i - 1]];
            const range = startIdx === i - 1 ? startLabel : `${startLabel}-${endLabel}`;
            groups.push(`${range} ${current}`);
            startIdx = i;
        }
    }
    return groups.join(', ');
});

const hasLocation = computed(() =>
    props.sucursal?.latitude != null && props.sucursal?.longitude != null
);

const mapsUrl = computed(() => {
    if (!hasLocation.value) return null;
    return `https://www.google.com/maps?q=${props.sucursal.latitude},${props.sucursal.longitude}`;
});

const staticMapUrl = computed(() => {
    if (!hasLocation.value) return null;
    const lat = props.sucursal.latitude;
    const lng = props.sucursal.longitude;
    return `https://maps.googleapis.com/maps/api/staticmap?center=${lat},${lng}&zoom=16&size=600x240&scale=2&markers=color:red%7C${lat},${lng}&key=${import.meta.env.VITE_GOOGLE_MAPS_KEY}`;
});

const menuUrl = computed(() => `${window.location.origin}/menu/${props.tenant.slug}/s/${props.sucursal.id}`);

const copyMenuUrl = async () => {
    await navigator.clipboard.writeText(menuUrl.value);
};

const sortedTiers = computed(() => {
    const list = props.sucursal?.delivery_tiers || [];
    return [...list].sort((a, b) => Number(a.max_km ?? 0) - Number(b.max_km ?? 0));
});

const paymentMethodsLabel = computed(() => {
    const map = { cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' };
    const list = props.sucursal?.payment_methods_enabled || [];
    if (list.length === 0) return 'Sin métodos configurados';
    return list.map(m => map[m] || m).join(' · ');
});

const isActive = computed(() => props.sucursal?.status === 'active');
const onlineEnabled = computed(() => !!props.sucursal?.online_ordering_enabled);
const deliveryEnabled = computed(() => !!props.sucursal?.delivery_enabled);
const pickupEnabled = computed(() => !!props.sucursal?.pickup_enabled);

// Avatar gradient por nombre (consistente con Categorías/Productos).
const avatarColors = [
    'from-rose-500 to-red-600',
    'from-orange-500 to-amber-600',
    'from-amber-400 to-yellow-500',
    'from-emerald-500 to-teal-600',
    'from-sky-500 to-blue-600',
    'from-indigo-500 to-violet-600',
    'from-purple-500 to-fuchsia-600',
    'from-pink-500 to-rose-500',
];
const avatarColor = computed(() => {
    let hash = 0;
    const s = String(props.sucursal?.name || '');
    for (let i = 0; i < s.length; i++) { hash = ((hash << 5) - hash) + s.charCodeAt(i); hash |= 0; }
    return avatarColors[Math.abs(hash) % avatarColors.length];
});
const avatarInitials = computed(() => {
    const words = String(props.sucursal?.name || '?').trim().split(/\s+/).filter(Boolean);
    if (words.length === 0) return '?';
    if (words.length === 1) return words[0].slice(0, 2).toUpperCase();
    return (words[0][0] + words[1][0]).toUpperCase();
});
</script>

<template>
    <Head :title="sucursal.name" />
    <EmpresaLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm">
                <Link :href="route('empresa.sucursales.index', tenant.slug)" class="text-gray-400 transition hover:text-gray-600">Sucursales</Link>
                <svg class="h-4 w-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                <span class="font-bold text-gray-900">{{ sucursal.name }}</span>
            </div>
        </template>

        <div class="mx-auto max-w-4xl space-y-6 pb-12">
            <!-- Hero header -->
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="flex flex-col gap-4 p-6 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex items-start gap-4">
                        <div :class="['flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br text-base font-bold text-white shadow-sm ring-2 ring-white', avatarColor]">
                            {{ avatarInitials }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <h1 class="truncate text-2xl font-bold tracking-tight text-gray-900">{{ sucursal.name }}</h1>
                            <p v-if="sucursal.address" class="mt-0.5 truncate text-sm text-gray-500">{{ sucursal.address }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <span :class="['inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold ring-1 ring-inset',
                                    isActive ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-gray-100 text-gray-600 ring-gray-200']">
                                    <span :class="['h-1.5 w-1.5 rounded-full', isActive ? 'bg-emerald-500' : 'bg-gray-400']" />
                                    {{ isActive ? 'Activa' : 'Inactiva' }}
                                </span>
                                <span :class="['inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold ring-1 ring-inset',
                                    onlineEnabled ? 'bg-blue-50 text-blue-700 ring-blue-600/20' : 'bg-gray-100 text-gray-500 ring-gray-200']">
                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" /></svg>
                                    Menú online {{ onlineEnabled ? 'activo' : 'apagado' }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <Link :href="route('empresa.sucursales.edit', [tenant.slug, sucursal.id])"
                        class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-red-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 active:scale-95">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" /></svg>
                        Editar sucursal
                    </Link>
                </div>
            </div>

            <!-- Información general -->
            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <header class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-base font-bold text-gray-900">Información general</h2>
                </header>
                <dl class="divide-y divide-gray-50">
                    <div class="grid grid-cols-3 gap-3 px-6 py-3.5">
                        <dt class="text-xs font-semibold uppercase tracking-wider text-gray-400">Nombre</dt>
                        <dd class="col-span-2 text-sm font-medium text-gray-900">{{ sucursal.name }}</dd>
                    </div>
                    <div class="grid grid-cols-3 gap-3 px-6 py-3.5">
                        <dt class="text-xs font-semibold uppercase tracking-wider text-gray-400">Dirección</dt>
                        <dd class="col-span-2 text-sm text-gray-900">
                            <span v-if="sucursal.address" class="font-medium">{{ sucursal.address }}</span>
                            <span v-else class="italic text-gray-400">Sin dirección</span>
                        </dd>
                    </div>
                    <div class="grid grid-cols-3 gap-3 px-6 py-3.5">
                        <dt class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-gray-400">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                            Teléfono interno
                        </dt>
                        <dd class="col-span-2 text-sm text-gray-900">
                            <span v-if="sucursal.phone" class="font-medium tabular-nums">{{ sucursal.phone }}</span>
                            <span v-else class="italic text-gray-400">Sin configurar</span>
                        </dd>
                    </div>
                    <div class="grid grid-cols-3 gap-3 px-6 py-3.5">
                        <dt class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-gray-400">
                            <svg class="h-3.5 w-3.5 text-[#25D366]" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347M12.05 21.785h-.004a9.87 9.87 0 0 1-5.03-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.002-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.892 6.994c-.003 5.45-4.437 9.884-9.885 9.884" /></svg>
                            WhatsApp pedidos
                        </dt>
                        <dd class="col-span-2 text-sm text-gray-900">
                            <span v-if="sucursal.public_phone" class="font-mono font-medium tabular-nums">{{ sucursal.public_phone }}</span>
                            <span v-else class="italic text-gray-400">Sin configurar</span>
                        </dd>
                    </div>
                </dl>
            </section>

            <!-- Ubicación -->
            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <header class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-base font-bold text-gray-900">Ubicación</h2>
                </header>
                <div class="p-6">
                    <div v-if="hasLocation" class="space-y-3">
                        <div class="overflow-hidden rounded-xl ring-1 ring-gray-200">
                            <img v-if="staticMapUrl" :src="staticMapUrl" alt="Mapa de la ubicación" class="h-[240px] w-full object-cover" />
                        </div>
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <span class="font-mono text-xs text-gray-500 tabular-nums">
                                {{ Number(sucursal.latitude).toFixed(6) }}, {{ Number(sucursal.longitude).toFixed(6) }}
                            </span>
                            <a :href="mapsUrl" target="_blank" rel="noopener noreferrer"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700 ring-1 ring-inset ring-blue-200 transition hover:bg-blue-100">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" /></svg>
                                Abrir en Google Maps
                            </a>
                        </div>
                    </div>
                    <div v-else class="rounded-xl border-2 border-dashed border-gray-200 px-6 py-8 text-center">
                        <p class="text-sm font-medium text-gray-500">Sin ubicación configurada</p>
                        <p class="mt-1 text-xs text-gray-400">Edita la sucursal para colocar el pin en el mapa.</p>
                    </div>
                </div>
            </section>

            <!-- Horarios -->
            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <header class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-base font-bold text-gray-900">Horarios</h2>
                </header>
                <div class="p-6">
                    <div v-if="hoursSummary" class="space-y-3">
                        <p class="text-sm font-medium text-gray-700">{{ hoursSummary }}</p>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-7">
                            <div v-for="d in DAY_KEYS" :key="d" class="rounded-xl bg-gray-50 px-3 py-2.5 text-center">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">{{ DAY_LABELS[d] }}</p>
                                <p v-if="sucursal.hours?.[d]?.open && sucursal.hours?.[d]?.close" class="mt-1 font-mono text-[11px] tabular-nums text-gray-900">
                                    {{ sucursal.hours[d].open }}<br />–<br />{{ sucursal.hours[d].close }}
                                </p>
                                <p v-else class="mt-1 text-[11px] italic text-gray-400">Cerrado</p>
                            </div>
                        </div>
                    </div>
                    <div v-else class="rounded-xl border-2 border-dashed border-gray-200 px-6 py-8 text-center">
                        <p class="text-sm font-medium text-gray-500">Sin horarios configurados</p>
                        <p class="mt-1 text-xs text-gray-400">La sucursal aparecerá como cerrada en el menú online hasta que se configuren los horarios.</p>
                    </div>
                </div>
            </section>

            <!-- Pedidos en línea -->
            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <header class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <h2 class="text-base font-bold text-gray-900">Pedidos en línea</h2>
                    <span :class="['inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold ring-1 ring-inset',
                        onlineEnabled ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-gray-100 text-gray-600 ring-gray-200']">
                        <span :class="['h-1.5 w-1.5 rounded-full', onlineEnabled ? 'bg-emerald-500' : 'bg-gray-400']" />
                        {{ onlineEnabled ? 'Habilitados' : 'Deshabilitados' }}
                    </span>
                </header>
                <div v-if="onlineEnabled" class="space-y-4 p-6">
                    <!-- Modos -->
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div :class="['rounded-xl px-4 py-3 ring-1', deliveryEnabled ? 'bg-blue-50 ring-blue-200' : 'bg-gray-50 ring-gray-200']">
                            <div class="flex items-center gap-2">
                                <span :class="['text-base', deliveryEnabled ? '' : 'grayscale opacity-60']">🚚</span>
                                <p :class="['text-sm font-bold', deliveryEnabled ? 'text-blue-900' : 'text-gray-500']">Envío a domicilio</p>
                            </div>
                            <p :class="['mt-1 text-xs', deliveryEnabled ? 'text-blue-700' : 'text-gray-500']">
                                {{ deliveryEnabled ? 'Activo' : 'Sin envío' }}
                            </p>
                        </div>
                        <div :class="['rounded-xl px-4 py-3 ring-1', pickupEnabled ? 'bg-emerald-50 ring-emerald-200' : 'bg-gray-50 ring-gray-200']">
                            <div class="flex items-center gap-2">
                                <span :class="['text-base', pickupEnabled ? '' : 'grayscale opacity-60']">🏪</span>
                                <p :class="['text-sm font-bold', pickupEnabled ? 'text-emerald-900' : 'text-gray-500']">Recolección en sucursal</p>
                            </div>
                            <p :class="['mt-1 text-xs', pickupEnabled ? 'text-emerald-700' : 'text-gray-500']">
                                {{ pickupEnabled ? 'Activa' : 'Sin pickup' }}
                            </p>
                        </div>
                    </div>

                    <!-- Pedido mínimo -->
                    <div v-if="sucursal.min_order_amount" class="rounded-xl bg-gray-50 px-4 py-3">
                        <p class="text-xs font-medium text-gray-500">Pedido mínimo</p>
                        <p class="mt-0.5 font-mono text-sm font-bold tabular-nums text-gray-900">${{ Number(sucursal.min_order_amount).toFixed(2) }}</p>
                    </div>

                    <!-- Tarifas de envío -->
                    <div v-if="deliveryEnabled && sortedTiers.length > 0">
                        <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-gray-500">Tarifas de envío</p>
                        <div class="mt-2 overflow-hidden rounded-xl ring-1 ring-gray-100">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50/60">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-[10px] font-bold uppercase tracking-wider text-gray-500">Hasta</th>
                                        <th class="px-4 py-2 text-right text-[10px] font-bold uppercase tracking-wider text-gray-500">Tarifa</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="(t, i) in sortedTiers" :key="i">
                                        <td class="px-4 py-2 font-mono tabular-nums text-gray-900">{{ Number(t.max_km).toFixed(1) }} km</td>
                                        <td class="px-4 py-2 text-right font-mono font-bold tabular-nums text-gray-900">${{ Number(t.fee).toFixed(2) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- URL pública -->
                    <div class="rounded-xl bg-gradient-to-r from-red-50 to-orange-50 px-4 py-3 ring-1 ring-red-100">
                        <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-red-700/70">URL pública del menú</p>
                        <div class="mt-1.5 flex items-center gap-2">
                            <code class="flex-1 truncate font-mono text-xs text-gray-900">{{ menuUrl }}</code>
                            <button type="button" @click="copyMenuUrl" class="shrink-0 rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 ring-1 ring-gray-200 transition hover:bg-gray-50">
                                Copiar
                            </button>
                            <a :href="menuUrl" target="_blank" rel="noopener noreferrer" class="shrink-0 rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-red-700">
                                Abrir
                            </a>
                        </div>
                    </div>
                </div>
                <div v-else class="p-6">
                    <p class="rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-500">
                        Esta sucursal <strong>no recibe pedidos en línea</strong>. No aparece en el menú web público.
                    </p>
                </div>
            </section>

            <!-- Métodos de pago -->
            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <header class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-base font-bold text-gray-900">Métodos de pago aceptados</h2>
                </header>
                <div class="p-6">
                    <p class="text-sm text-gray-700">{{ paymentMethodsLabel }}</p>
                    <p class="mt-1 text-xs text-gray-400">El admin de la sucursal puede ajustar esto desde su panel.</p>
                </div>
            </section>

            <!-- Equipo -->
            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <header class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <h2 class="text-base font-bold text-gray-900">Equipo</h2>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-bold text-gray-700">{{ sucursal.users?.length ?? 0 }}</span>
                </header>
                <div v-if="sucursal.users && sucursal.users.length > 0" class="divide-y divide-gray-50">
                    <div v-for="u in sucursal.users" :key="u.id" class="flex items-center justify-between px-6 py-3">
                        <div>
                            <p class="text-sm font-semibold text-gray-900">{{ u.name }}</p>
                            <p class="text-xs text-gray-500">{{ u.email }}</p>
                        </div>
                        <span v-if="u.roles && u.roles[0]" class="rounded-full bg-orange-50 px-2.5 py-0.5 text-xs font-semibold text-orange-700 ring-1 ring-inset ring-orange-600/20">
                            {{ u.roles[0].name }}
                        </span>
                    </div>
                </div>
                <p v-else class="px-6 py-8 text-center text-sm italic text-gray-400">Sin usuarios asignados a esta sucursal.</p>
            </section>
        </div>

        <FlashToast />
    </EmpresaLayout>
</template>
