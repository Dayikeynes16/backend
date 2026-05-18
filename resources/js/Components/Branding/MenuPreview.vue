<script setup>
import { computed } from 'vue';

const props = defineProps({
    primary: { type: String, required: true },
    accent: { type: String, required: true },
    background: { type: String, required: true },
    text: { type: String, required: true },
    logoUrl: { type: String, default: null },
    defaultProductImageUrl: { type: String, default: null },
    tenantName: { type: String, default: 'Mi Carnicería' },
});

const hexToRgb = (hex) => {
    const h = hex.replace('#', '');
    return {
        r: parseInt(h.slice(0, 2), 16),
        g: parseInt(h.slice(2, 4), 16),
        b: parseInt(h.slice(4, 6), 16),
    };
};

const relativeLuminance = (hex) => {
    const { r, g, b } = hexToRgb(hex);
    const ch = (c) => {
        const v = c / 255;
        return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * ch(r) + 0.7152 * ch(g) + 0.0722 * ch(b);
};

const contrast = (a, b) => {
    const la = relativeLuminance(a);
    const lb = relativeLuminance(b);
    const [hi, lo] = la > lb ? [la, lb] : [lb, la];
    return (hi + 0.05) / (lo + 0.05);
};

const autoText = (bg) => (contrast('#FFFFFF', bg) >= contrast('#000000', bg) ? '#FFFFFF' : '#000000');

const resolvedText = computed(() => (props.text === 'auto' ? autoText(props.background) : props.text));
const subtleText = computed(() => (resolvedText.value === '#FFFFFF' ? 'rgba(255,255,255,0.7)' : 'rgba(0,0,0,0.55)'));
const cardBg = computed(() => (resolvedText.value === '#FFFFFF' ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.04)'));
const borderColor = computed(() => (resolvedText.value === '#FFFFFF' ? 'rgba(255,255,255,0.14)' : 'rgba(0,0,0,0.08)'));
const onPrimary = computed(() => autoText(props.primary));

const styleVars = computed(() => ({
    '--brand-primary': props.primary,
    '--brand-accent': props.accent,
    '--brand-background': props.background,
    '--brand-text': resolvedText.value,
    '--brand-text-subtle': subtleText.value,
    '--brand-card': cardBg.value,
    '--brand-border': borderColor.value,
    '--brand-on-primary': onPrimary.value,
}));

const categorias = ['Res', 'Cerdo', 'Pollo', 'Embutidos'];
const productos = [
    { name: 'Arrachera marinada', price: 289, img: true },
    { name: 'Costilla de cerdo', price: 159, img: false },
    { name: 'Chorizo casero', price: 119, img: true },
];
</script>

<template>
    <div class="overflow-hidden rounded-[2rem] border-[10px] border-gray-900 bg-gray-900 shadow-xl">
        <div class="flex h-6 items-center justify-center bg-gray-900">
            <div class="h-1.5 w-16 rounded-full bg-gray-700" />
        </div>
        <div :style="styleVars" class="flex h-[560px] flex-col" style="background-color: var(--brand-background); color: var(--brand-text);">
            <header class="flex items-center gap-3 border-b px-4 py-3" style="border-color: var(--brand-border);">
                <div v-if="logoUrl" class="h-10 w-10 overflow-hidden rounded-full ring-1" :style="{ ringColor: 'var(--brand-border)' }">
                    <img :src="logoUrl" alt="Logo" class="h-full w-full object-cover" />
                </div>
                <div v-else class="flex h-10 w-10 items-center justify-center rounded-full text-sm font-bold" style="background-color: var(--brand-primary); color: var(--brand-on-primary);">
                    {{ tenantName.charAt(0) }}
                </div>
                <div class="flex-1 min-w-0">
                    <p class="truncate text-sm font-bold leading-tight">{{ tenantName }}</p>
                    <p class="truncate text-xs" style="color: var(--brand-text-subtle);">Sucursal Centro · Abierto</p>
                </div>
                <div class="flex h-8 w-8 items-center justify-center rounded-full" style="background-color: var(--brand-card);">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.34-4.34M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z" /></svg>
                </div>
            </header>

            <div class="flex gap-2 overflow-x-auto px-4 py-3">
                <span v-for="(cat, idx) in categorias" :key="cat"
                    class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold"
                    :style="idx === 0
                        ? { backgroundColor: 'var(--brand-primary)', color: 'var(--brand-on-primary)' }
                        : { backgroundColor: 'var(--brand-card)', color: 'var(--brand-text)' }">
                    {{ cat }}
                </span>
            </div>

            <div class="flex-1 space-y-3 overflow-y-auto px-4 pb-24">
                <div v-for="p in productos" :key="p.name" class="flex items-center gap-3 rounded-xl p-2" style="background-color: var(--brand-card);">
                    <div v-if="p.img && defaultProductImageUrl" class="h-16 w-16 overflow-hidden rounded-lg">
                        <img :src="defaultProductImageUrl" alt="" class="h-full w-full object-cover" />
                    </div>
                    <div v-else class="flex h-16 w-16 items-center justify-center rounded-lg" style="background-color: var(--brand-border);">
                        <svg class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="color: var(--brand-text-subtle);"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="truncate text-sm font-semibold">{{ p.name }}</p>
                        <p class="text-base font-bold" style="color: var(--brand-primary);">${{ p.price }}</p>
                    </div>
                    <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full text-lg font-bold shadow"
                        style="background-color: var(--brand-primary); color: var(--brand-on-primary);" disabled>+</button>
                </div>

                <div class="rounded-xl p-3" style="background-color: var(--brand-accent); color: #1F2937;">
                    <p class="text-xs font-bold uppercase tracking-wider">Oferta del día</p>
                    <p class="text-sm">2x1 en chorizo casero hasta agotar existencias.</p>
                </div>
            </div>

            <div class="absolute" />
            <div class="border-t px-4 py-3" style="border-color: var(--brand-border); background-color: var(--brand-background);">
                <button type="button" class="flex w-full items-center justify-between rounded-xl px-4 py-3 text-sm font-bold shadow-lg"
                    style="background-color: var(--brand-primary); color: var(--brand-on-primary);" disabled>
                    <span>3 productos · $567</span>
                    <span>Ver carrito →</span>
                </button>
            </div>
        </div>
    </div>
</template>
