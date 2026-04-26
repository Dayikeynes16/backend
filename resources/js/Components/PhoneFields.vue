<script setup>
// PhoneFields — agrupa los dos teléfonos de una sucursal con jerarquía
// visual y leyendas claras: teléfono interno (tickets, reportes) vs
// WhatsApp público (pedidos online).
//
// Uso:
//   <PhoneFields
//     v-model:phone="form.phone"
//     v-model:public-phone="form.public_phone"
//     :phone-error="form.errors.phone"
//     :public-phone-error="form.errors.public_phone"
//     :online-enabled="form.online_ordering_enabled" />

import InputError from '@/Components/InputError.vue';

defineProps({
    phone: { type: String, default: '' },
    publicPhone: { type: String, default: '' },
    phoneError: { type: String, default: '' },
    publicPhoneError: { type: String, default: '' },
    onlineEnabled: { type: Boolean, default: false },
});

defineEmits(['update:phone', 'update:publicPhone']);
</script>

<template>
    <div class="space-y-4">
        <!-- Teléfono interno -->
        <div class="rounded-2xl bg-white p-4 ring-1 ring-gray-100 transition hover:ring-gray-200">
            <div class="flex items-start gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gray-100 text-gray-600">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-baseline justify-between gap-2">
                        <label for="phone" class="text-sm font-bold text-gray-900">Teléfono de contacto</label>
                        <span class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Uso interno</span>
                    </div>
                    <p class="mt-0.5 text-xs text-gray-500">Aparece en tickets impresos, reportes y respuesta de la API. Lo usa el equipo (caja, báscula).</p>
                    <input
                        id="phone"
                        type="tel"
                        :value="phone"
                        @input="$emit('update:phone', $event.target.value)"
                        placeholder="993-000-0000"
                        autocomplete="tel"
                        class="mt-2 block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm text-gray-900 transition focus:border-red-400 focus:ring-red-300" />
                    <InputError :message="phoneError" class="mt-1" />
                </div>
            </div>
        </div>

        <!-- WhatsApp público -->
        <div :class="['rounded-2xl bg-white p-4 ring-1 transition',
            onlineEnabled
                ? 'ring-emerald-200 hover:ring-emerald-300'
                : 'ring-gray-100 opacity-75 hover:opacity-100']">
            <div class="flex items-start gap-3">
                <div :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-xl',
                    onlineEnabled ? 'bg-[#25D366]/10 text-[#25D366]' : 'bg-gray-100 text-gray-400']">
                    <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347M12.05 21.785h-.004a9.87 9.87 0 0 1-5.03-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.002-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.892 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413" />
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-baseline gap-2">
                        <label for="public_phone" class="text-sm font-bold text-gray-900">WhatsApp para pedidos</label>
                        <span :class="['rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider',
                            onlineEnabled ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500']">
                            Uso público
                        </span>
                    </div>
                    <p class="mt-0.5 text-xs text-gray-500">
                        Los pedidos del menú web llegan a este número como mensaje de WhatsApp con el detalle completo del pedido.
                    </p>
                    <input
                        id="public_phone"
                        type="tel"
                        :value="publicPhone"
                        @input="$emit('update:publicPhone', $event.target.value)"
                        placeholder="+52 993 000 0000"
                        autocomplete="tel"
                        class="mt-2 block w-full rounded-xl border-gray-200 bg-white py-2.5 font-mono text-sm tabular-nums text-gray-900 transition focus:border-emerald-400 focus:ring-emerald-200" />
                    <p v-if="!onlineEnabled" class="mt-1.5 inline-flex items-center gap-1 text-[11px] text-amber-600">
                        <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1Zm3 8V5.5a3 3 0 1 0-6 0V9h6Z" clip-rule="evenodd" /></svg>
                        Activa "Pedidos en línea" más abajo para que este número se utilice.
                    </p>
                    <p v-else class="mt-1.5 text-[11px] text-gray-400">
                        Formato internacional con prefijo de país (ej. +52 para México).
                    </p>
                    <InputError :message="publicPhoneError" class="mt-1" />
                </div>
            </div>
        </div>
    </div>
</template>
