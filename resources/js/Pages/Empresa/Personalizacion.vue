<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import MenuPreview from '@/Components/Branding/MenuPreview.vue';

const props = defineProps({
    tenant: { type: Object, required: true },
    branding: { type: Object, required: true },
    defaults: { type: Object, required: true },
});

const form = useForm({
    primary_color: props.branding.primary_color,
    accent_color: props.branding.accent_color,
    background_color: props.branding.background_color,
    text_color: props.branding.text_color,
    logo: null,
    remove_logo: false,
    default_product_image: null,
    remove_default_product_image: false,
});

const textAuto = ref(props.branding.text_color === 'auto');
const textManual = ref(props.branding.text_color === 'auto' ? '#111111' : props.branding.text_color);

watch(textAuto, (auto) => {
    form.text_color = auto ? 'auto' : textManual.value;
});
watch(textManual, (val) => {
    if (!textAuto.value) form.text_color = val;
});

const logoPreviewBlob = ref(null);
const logoPreviewUrl = computed(() => {
    if (form.remove_logo) return null;
    if (logoPreviewBlob.value) return logoPreviewBlob.value;
    return props.branding.logo_url;
});

const defaultProductPreviewBlob = ref(null);
const defaultProductPreviewUrl = computed(() => {
    if (form.remove_default_product_image) return null;
    if (defaultProductPreviewBlob.value) return defaultProductPreviewBlob.value;
    return props.branding.default_product_image_url;
});

const onLogoChange = (e) => {
    const file = e.target.files?.[0] ?? null;
    form.logo = file;
    form.remove_logo = false;
    if (logoPreviewBlob.value) URL.revokeObjectURL(logoPreviewBlob.value);
    logoPreviewBlob.value = file ? URL.createObjectURL(file) : null;
};

const onDefaultProductChange = (e) => {
    const file = e.target.files?.[0] ?? null;
    form.default_product_image = file;
    form.remove_default_product_image = false;
    if (defaultProductPreviewBlob.value) URL.revokeObjectURL(defaultProductPreviewBlob.value);
    defaultProductPreviewBlob.value = file ? URL.createObjectURL(file) : null;
};

const removeLogo = () => {
    form.logo = null;
    form.remove_logo = true;
    if (logoPreviewBlob.value) URL.revokeObjectURL(logoPreviewBlob.value);
    logoPreviewBlob.value = null;
};

const removeDefaultProduct = () => {
    form.default_product_image = null;
    form.remove_default_product_image = true;
    if (defaultProductPreviewBlob.value) URL.revokeObjectURL(defaultProductPreviewBlob.value);
    defaultProductPreviewBlob.value = null;
};

const submit = () => {
    form.post(route('empresa.personalizacion.update', props.tenant.slug), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            logoPreviewBlob.value = null;
            defaultProductPreviewBlob.value = null;
        },
    });
};

const resetConfirmOpen = ref(false);
const reset = () => {
    if (!resetConfirmOpen.value) {
        resetConfirmOpen.value = true;
        return;
    }
    form.post(route('empresa.personalizacion.reset', props.tenant.slug), {
        preserveScroll: true,
        onFinish: () => {
            resetConfirmOpen.value = false;
        },
    });
};

const hexRegex = /^#[0-9a-fA-F]{6}$/;
const isHex = (v) => typeof v === 'string' && hexRegex.test(v);

const hexToRgb = (hex) => {
    const h = hex.replace('#', '');
    return { r: parseInt(h.slice(0, 2), 16), g: parseInt(h.slice(2, 4), 16), b: parseInt(h.slice(4, 6), 16) };
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
    if (!isHex(a) || !isHex(b)) return 21;
    const la = relativeLuminance(a);
    const lb = relativeLuminance(b);
    const [hi, lo] = la > lb ? [la, lb] : [lb, la];
    return (hi + 0.05) / (lo + 0.05);
};

const primaryContrast = computed(() => contrast('#FFFFFF', form.primary_color));
const textContrast = computed(() => {
    if (form.text_color === 'auto') return 21;
    return contrast(form.text_color, form.background_color);
});

const warnings = computed(() => {
    const w = [];
    if (isHex(form.primary_color) && primaryContrast.value < 4.5) {
        w.push(`El texto blanco sobre el color primario tiene contraste ${primaryContrast.value.toFixed(2)}:1 (mínimo 4.5).`);
    }
    if (form.text_color !== 'auto' && isHex(form.text_color) && textContrast.value < 4.5) {
        w.push(`Texto sobre fondo tiene contraste ${textContrast.value.toFixed(2)}:1 (mínimo 4.5).`);
    }
    if (isHex(form.primary_color) && isHex(form.accent_color) && form.primary_color.toLowerCase() === form.accent_color.toLowerCase()) {
        w.push('El color de acento no debe ser igual al primario.');
    }
    return w;
});

const colorFields = computed(() => [
    { key: 'primary_color', label: 'Color principal', help: 'Botones, precios, categoría activa.' },
    { key: 'accent_color', label: 'Color de acento', help: 'Etiquetas, ofertas, badges secundarios.' },
    { key: 'background_color', label: 'Color de fondo', help: 'Fondo general del menú.' },
]);
</script>

<template>
    <Head title="Personalización" />
    <EmpresaLayout>
        <template #header>
            <div>
                <h1 class="text-xl font-bold text-gray-900">Personalización del menú digital</h1>
                <p class="text-xs text-gray-500">Los cambios aplican solo al menú público de tus clientes. El panel administrativo mantiene su estilo.</p>
            </div>
        </template>

        <div class="mx-auto grid max-w-7xl gap-8 lg:grid-cols-[minmax(0,1fr)_360px]">
            <form @submit.prevent="submit" class="space-y-6">
                <!-- Logo -->
                <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                    <div class="border-b border-gray-100 px-6 py-5">
                        <h2 class="text-base font-bold text-gray-900">Logo de la empresa</h2>
                        <p class="mt-1 text-sm text-gray-500">JPG, PNG o WebP. Máximo 2 MB. Recomendado: cuadrado, mínimo 200×200 px.</p>
                    </div>
                    <div class="flex flex-col gap-5 p-6 sm:flex-row sm:items-center">
                        <div class="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-gray-100 ring-1 ring-gray-200">
                            <img v-if="logoPreviewUrl" :src="logoPreviewUrl" alt="Logo" class="h-full w-full object-cover" />
                            <span v-else class="text-xs text-gray-400">Sin logo</span>
                        </div>
                        <div class="flex flex-1 flex-col gap-2">
                            <label class="inline-flex w-fit cursor-pointer items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                                {{ logoPreviewUrl ? 'Cambiar logo' : 'Subir logo' }}
                                <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="onLogoChange" />
                            </label>
                            <button v-if="logoPreviewUrl" type="button" @click="removeLogo" class="w-fit text-xs font-semibold text-red-600 hover:underline">Quitar logo</button>
                            <InputError :message="form.errors.logo" class="mt-1" />
                        </div>
                    </div>
                </section>

                <!-- Colores -->
                <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                    <div class="border-b border-gray-100 px-6 py-5">
                        <h2 class="text-base font-bold text-gray-900">Colores</h2>
                        <p class="mt-1 text-sm text-gray-500">Validamos que los textos sean legibles. Si el contraste es bajo el guardado se bloquea.</p>
                    </div>
                    <div class="space-y-5 p-6">
                        <div v-for="field in colorFields" :key="field.key" class="grid gap-3 sm:grid-cols-[auto_1fr]">
                            <input type="color" :id="field.key" v-model="form[field.key]" class="h-11 w-16 cursor-pointer rounded-lg border border-gray-200" />
                            <div>
                                <InputLabel :for="field.key" :value="field.label" />
                                <input type="text" v-model="form[field.key]" class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm uppercase shadow-sm focus:border-gray-500 focus:ring-gray-500" maxlength="7" />
                                <p class="mt-1 text-xs text-gray-400">{{ field.help }}</p>
                                <InputError :message="form.errors[field.key]" class="mt-1" />
                            </div>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-[auto_1fr]">
                            <input type="color" :disabled="textAuto" v-model="textManual" class="h-11 w-16 cursor-pointer rounded-lg border border-gray-200 disabled:opacity-40" />
                            <div>
                                <InputLabel value="Color de texto" />
                                <div class="mt-1 flex items-center gap-3">
                                    <input type="text" :disabled="textAuto" v-model="textManual" class="block w-40 rounded-md border-gray-300 font-mono text-sm uppercase shadow-sm focus:border-gray-500 focus:ring-gray-500 disabled:bg-gray-50 disabled:text-gray-400" maxlength="7" />
                                    <label class="inline-flex items-center gap-2 text-xs font-medium text-gray-600">
                                        <input type="checkbox" v-model="textAuto" class="rounded border-gray-300 text-gray-900 focus:ring-gray-500" />
                                        Calcular automáticamente
                                    </label>
                                </div>
                                <p class="mt-1 text-xs text-gray-400">"Automático" elige blanco o negro según el contraste con el fondo.</p>
                                <InputError :message="form.errors.text_color" class="mt-1" />
                            </div>
                        </div>

                        <div v-if="warnings.length" class="rounded-lg border border-amber-200 bg-amber-50 p-3">
                            <p class="text-xs font-bold uppercase tracking-wide text-amber-800">Advertencias</p>
                            <ul class="mt-1 list-disc space-y-1 pl-4 text-sm text-amber-900">
                                <li v-for="(w, i) in warnings" :key="i">{{ w }}</li>
                            </ul>
                            <p class="mt-2 text-xs text-amber-700">El servidor rechazará el guardado mientras estas advertencias estén presentes.</p>
                        </div>
                    </div>
                </section>

                <!-- Default product image -->
                <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                    <div class="border-b border-gray-100 px-6 py-5">
                        <h2 class="text-base font-bold text-gray-900">Imagen por defecto para productos</h2>
                        <p class="mt-1 text-sm text-gray-500">Se usa cuando un producto no tiene foto propia. JPG, PNG o WebP. Máximo 1 MB.</p>
                    </div>
                    <div class="flex flex-col gap-5 p-6 sm:flex-row sm:items-center">
                        <div class="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-100 ring-1 ring-gray-200">
                            <img v-if="defaultProductPreviewUrl" :src="defaultProductPreviewUrl" alt="Default" class="h-full w-full object-cover" />
                            <span v-else class="text-xs text-gray-400">Sin imagen</span>
                        </div>
                        <div class="flex flex-1 flex-col gap-2">
                            <label class="inline-flex w-fit cursor-pointer items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                                {{ defaultProductPreviewUrl ? 'Cambiar imagen' : 'Subir imagen' }}
                                <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="onDefaultProductChange" />
                            </label>
                            <button v-if="defaultProductPreviewUrl" type="button" @click="removeDefaultProduct" class="w-fit text-xs font-semibold text-red-600 hover:underline">Quitar imagen</button>
                            <InputError :message="form.errors.default_product_image" class="mt-1" />
                        </div>
                    </div>
                </section>

                <!-- Actions -->
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <button type="button" @click="reset" class="text-sm font-semibold text-gray-600 underline-offset-4 hover:text-gray-900 hover:underline">
                        {{ resetConfirmOpen ? '¿Confirmar restauración? Click para aplicar.' : 'Restaurar valores por defecto' }}
                    </button>
                    <div class="flex items-center gap-3">
                        <span v-if="form.recentlySuccessful" class="text-xs font-semibold text-emerald-600">Guardado.</span>
                        <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-6 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-gray-800 disabled:opacity-50">
                            Guardar cambios
                        </button>
                    </div>
                </div>
            </form>

            <!-- Preview -->
            <aside class="lg:sticky lg:top-24 lg:h-fit">
                <p class="mb-3 text-xs font-bold uppercase tracking-wider text-gray-500">Vista previa del menú</p>
                <MenuPreview
                    :primary="isHex(form.primary_color) ? form.primary_color : '#DC2626'"
                    :accent="isHex(form.accent_color) ? form.accent_color : '#F59E0B'"
                    :background="isHex(form.background_color) ? form.background_color : '#FFFFFF'"
                    :text="form.text_color"
                    :logo-url="logoPreviewUrl"
                    :default-product-image-url="defaultProductPreviewUrl"
                    :tenant-name="tenant.name"
                />
                <p class="mt-3 text-xs text-gray-400">Simulación. El menú real se ve en <code class="rounded bg-gray-100 px-1 py-0.5 text-[10px]">/menu/{{ tenant.slug }}/...</code></p>
            </aside>
        </div>

        <FlashToast />
    </EmpresaLayout>
</template>
