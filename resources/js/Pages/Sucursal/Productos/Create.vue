<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({ categories: Array, tenant: Object });

const form = useForm({
    name: '',
    category_id: '',
    description: '',
    price: '',
    cost_price: '',
    sale_mode: 'weight',
    visibility: 'public',
    visible_online: false,
    image: null,
    presentations: [],
});

const suggestedPrice = (p) => {
    const base = parseFloat(form.price);
    const content = parseFloat(p.content);
    if (!base || !content || content <= 0) return null;
    if (p.unit === 'kg') return Math.round(content * base * 100) / 100;
    if (p.unit === 'g') return Math.round((content / 1000) * base * 100) / 100;
    return null;
};

const contentEquivalent = (p) => {
    const content = parseFloat(p.content);
    if (!content || content <= 0) return null;
    if (p.unit === 'g' && content >= 1) return `= ${Math.round((content / 1000) * 1000) / 1000} kg`;
    if (p.unit === 'kg' && content < 1) return `= ${Math.round(content * 1000)} g`;
    if (p.unit === 'ml' && content >= 1) return `= ${Math.round((content / 1000) * 1000) / 1000} l`;
    if (p.unit === 'l' && content < 1) return `= ${Math.round(content * 1000)} ml`;
    return null;
};

const contentWarning = (p) => {
    const content = parseFloat(p.content);
    if (!content) return null;
    if (p.unit === 'g' && content < 1) return '¿Quisiste decir kg?';
    if (p.unit === 'kg' && content > 100) return '¿Seguro? Eso es mas de 100 kg';
    if (p.unit === 'ml' && content < 1) return '¿Quisiste decir litros?';
    if (p.unit === 'l' && content > 100) return '¿Seguro? Eso es mas de 100 litros';
    return null;
};

const addPresentation = () => {
    form.presentations.push({ name: '', content: '', unit: 'g', price: '' });
};
const removePresentation = (idx) => {
    form.presentations.splice(idx, 1);
};

const imagePreview = ref(null);
const fileInput = ref(null);

const hasCategories = computed(() => props.categories && props.categories.length > 0);

const onFileSelect = (e) => {
    const file = e.target.files[0];
    if (!file) return;
    form.image = file;
    imagePreview.value = URL.createObjectURL(file);
};

const removeImage = () => {
    form.image = null;
    imagePreview.value = null;
    if (fileInput.value) fileInput.value.value = '';
};

const submit = () => {
    form.post(route('sucursal.productos.store', props.tenant.slug), {
        forceFormData: true,
    });
};
</script>

<template>
    <Head title="Nuevo Producto" />
    <SucursalLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm">
                <Link :href="route('sucursal.productos.index', tenant.slug)" class="text-gray-400 transition hover:text-gray-600">Productos</Link>
                <svg class="h-4 w-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                <span class="font-bold text-gray-900">Nuevo Producto</span>
            </div>
        </template>

        <form @submit.prevent="submit" class="mx-auto max-w-3xl space-y-8">
            <!-- 1. Información del Producto -->
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Informacion del Producto</h2>
                    <p class="mt-1 text-sm text-gray-500">Nombre, categoria y descripcion del producto.</p>
                </div>
                <div class="space-y-5 p-6">
                    <div>
                        <InputLabel for="name" value="Nombre del producto" />
                        <TextInput id="name" v-model="form.name" type="text" class="mt-1.5 block w-full" required autofocus placeholder="Ej: Bistec de res, Chuleta de cerdo..." />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel for="category_id" value="Categoria" />
                        <div v-if="hasCategories">
                            <select id="category_id" v-model="form.category_id" class="mt-1.5 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-400 focus:ring-red-300">
                                <option value="">Sin categoria</option>
                                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                        </div>
                        <div v-else class="mt-1.5 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                            <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                            <div>
                                <p class="text-sm font-semibold text-amber-800">No hay categorias creadas</p>
                                <p class="mt-0.5 text-xs text-amber-700">Crea al menos una categoria antes de agregar productos.
                                    <Link :href="route('sucursal.categorias.index', tenant.slug)" class="font-bold underline">Ir a categorias</Link>
                                </p>
                            </div>
                        </div>
                        <InputError :message="form.errors.category_id" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel for="description" value="Descripcion (opcional)" />
                        <textarea id="description" v-model="form.description" rows="3" placeholder="Descripcion breve del producto..."
                            class="mt-1.5 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                        <InputError :message="form.errors.description" class="mt-1" />
                    </div>
                </div>
            </section>

            <!-- 2. Precios -->
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Precios</h2>
                    <p class="mt-1 text-sm text-gray-500">Define el precio de venta y opcionalmente el costo de produccion.</p>
                </div>
                <div class="grid gap-6 p-6 sm:grid-cols-2">
                    <div>
                        <InputLabel for="price" :value="form.sale_mode === 'weight' ? 'Precio de venta (por kg)' : 'Precio por kg'" />
                        <div class="relative mt-1.5">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">$</span>
                            <input id="price" v-model="form.price" type="number" step="0.01" min="0.01" required placeholder="0.00"
                                class="block w-full rounded-md border-gray-300 pl-7 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                        </div>
                        <p class="mt-1.5 text-xs text-gray-400">{{ form.sale_mode === 'weight' ? 'Monto que se cobra al cliente.' : 'Se usa como precio por kg y para calcular precio sugerido de presentaciones.' }}</p>
                        <InputError :message="form.errors.price" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel for="cost_price" value="Precio de produccion (opcional)" />
                        <div class="relative mt-1.5">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">$</span>
                            <input id="cost_price" v-model="form.cost_price" type="number" step="0.01" min="0" placeholder="0.00"
                                class="block w-full rounded-md border-gray-300 pl-7 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                        </div>
                        <p class="mt-1.5 text-xs text-gray-400">Referencia interna para estimar utilidad. No es visible para el cliente.</p>
                        <InputError :message="form.errors.cost_price" class="mt-1" />
                    </div>
                </div>
            </section>

            <!-- 3. Forma de Venta -->
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Forma de Venta</h2>
                    <p class="mt-1 text-sm text-gray-500">Define como se vende este producto al cliente.</p>
                </div>
                <div class="p-6 space-y-5">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <button type="button" @click="form.sale_mode = 'weight'"
                            :class="['flex items-start gap-4 rounded-xl p-5 text-left transition-all cursor-pointer',
                                form.sale_mode === 'weight' ? 'ring-2 ring-red-500 bg-red-50/50' : 'ring-1 ring-gray-200 hover:ring-gray-300']">
                            <div :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-lg', form.sale_mode === 'weight' ? 'bg-red-100' : 'bg-gray-100']">
                                <svg class="h-5 w-5" :class="form.sale_mode === 'weight' ? 'text-red-600' : 'text-gray-400'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v17.25m0 0c-1.472 0-2.882.265-4.185.75M12 20.25c1.472 0 2.882.265 4.185.75M18.75 4.97A48.416 48.416 0 0 0 12 4.5c-2.291 0-4.545.16-6.75.47m13.5 0c1.01.143 2.01.317 3 .52m-3-.52 2.62 10.726c.122.499-.106 1.028-.589 1.202a5.988 5.988 0 0 1-2.031.352 5.988 5.988 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L18.75 4.97Zm-16.5.52c.99-.203 1.99-.377 3-.52m0 0 2.62 10.726c.122.499-.106 1.028-.589 1.202a5.989 5.989 0 0 1-2.031.352 5.989 5.989 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L5.25 4.97Z" /></svg>
                            </div>
                            <div>
                                <p class="text-sm font-bold" :class="form.sale_mode === 'weight' ? 'text-red-800' : 'text-gray-900'">Peso variable</p>
                                <p class="mt-0.5 text-xs" :class="form.sale_mode === 'weight' ? 'text-red-600' : 'text-gray-500'">Se pesa al momento de la venta. Precio por kg.</p>
                            </div>
                        </button>
                        <button type="button" @click="form.sale_mode = 'presentation'"
                            :class="['flex items-start gap-4 rounded-xl p-5 text-left transition-all cursor-pointer',
                                form.sale_mode === 'presentation' ? 'ring-2 ring-orange-500 bg-orange-50/50' : 'ring-1 ring-gray-200 hover:ring-gray-300']">
                            <div :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-lg', form.sale_mode === 'presentation' ? 'bg-orange-100' : 'bg-gray-100']">
                                <svg class="h-5 w-5" :class="form.sale_mode === 'presentation' ? 'text-orange-600' : 'text-gray-400'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0-3-3m3 3 3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" /></svg>
                            </div>
                            <div>
                                <p class="text-sm font-bold" :class="form.sale_mode === 'presentation' ? 'text-orange-800' : 'text-gray-900'">Presentacion fija</p>
                                <p class="mt-0.5 text-xs" :class="form.sale_mode === 'presentation' ? 'text-orange-600' : 'text-gray-500'">Se vende en presentaciones predefinidas (500g, 1kg, etc.).</p>
                            </div>
                        </button>
                        <button type="button" @click="form.sale_mode = 'both'"
                            :class="['flex items-start gap-4 rounded-xl p-5 text-left transition-all cursor-pointer',
                                form.sale_mode === 'both' ? 'ring-2 ring-purple-500 bg-purple-50/50' : 'ring-1 ring-gray-200 hover:ring-gray-300']">
                            <div :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-lg', form.sale_mode === 'both' ? 'bg-purple-100' : 'bg-gray-100']">
                                <svg class="h-5 w-5" :class="form.sale_mode === 'both' ? 'text-purple-600' : 'text-gray-400'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" /></svg>
                            </div>
                            <div>
                                <p class="text-sm font-bold" :class="form.sale_mode === 'both' ? 'text-purple-800' : 'text-gray-900'">Ambos</p>
                                <p class="mt-0.5 text-xs" :class="form.sale_mode === 'both' ? 'text-purple-600' : 'text-gray-500'">Se puede pesar o vender por presentacion.</p>
                            </div>
                        </button>
                    </div>

                    <!-- Presentations list -->
                    <div v-if="form.sale_mode === 'presentation' || form.sale_mode === 'both'" class="space-y-3">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-semibold text-gray-700">Presentaciones</p>
                            <button type="button" @click="addPresentation" class="text-sm font-semibold text-red-600 hover:text-red-700">+ Agregar</button>
                        </div>

                        <InputError :message="form.errors.presentations" class="mt-1" />

                        <div v-if="form.presentations.length === 0" class="rounded-lg border border-dashed border-gray-200 px-4 py-6 text-center text-sm text-gray-400">
                            Agrega al menos una presentacion.
                        </div>

                        <div v-for="(p, idx) in form.presentations" :key="idx" class="rounded-lg bg-gray-50 p-4">
                            <div class="flex items-end gap-3">
                                <div class="flex-1">
                                    <label class="text-xs font-medium text-gray-500">Nombre</label>
                                    <input v-model="p.name" type="text" :placeholder="p.content && p.unit ? `${p.content}${p.unit}` : '500g, 1kg...'" class="mt-1 block w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />
                                </div>
                                <div class="w-24">
                                    <label class="text-xs font-medium text-gray-500">Contenido</label>
                                    <input v-model="p.content" type="number" step="0.001" min="0.001" placeholder="500" class="mt-1 block w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />
                                </div>
                                <div class="w-24">
                                    <label class="text-xs font-medium text-gray-500">Unidad</label>
                                    <select v-model="p.unit" class="mt-1 block w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300">
                                        <option value="g">g</option><option value="kg">kg</option><option value="ml">ml</option><option value="l">l</option><option value="pieza">pieza</option>
                                    </select>
                                </div>
                                <div class="w-28">
                                    <label class="text-xs font-medium text-gray-500">Precio</label>
                                    <div class="relative mt-1"><span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">$</span>
                                    <input v-model="p.price" type="number" step="0.01" min="0.01" :placeholder="suggestedPrice(p) ? suggestedPrice(p).toFixed(2) : '0.00'" class="block w-full rounded-lg border-gray-200 pl-7 text-sm focus:border-red-400 focus:ring-red-300" /></div>
                                </div>
                                <button type="button" @click="removePresentation(idx)" class="rounded-lg p-2 text-gray-400 hover:text-red-600">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                </button>
                            </div>
                            <!-- Ayudas visuales -->
                            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1">
                                <p v-if="suggestedPrice(p) && !p.price" class="text-xs text-blue-600">Precio sugerido: ${{ suggestedPrice(p).toFixed(2) }}</p>
                                <p v-if="contentEquivalent(p)" class="text-xs text-gray-400">{{ contentEquivalent(p) }}</p>
                                <p v-if="contentWarning(p)" class="text-xs font-medium text-amber-600">{{ contentWarning(p) }}</p>
                            </div>
                            <div v-if="form.errors[`presentations.${idx}.name`] || form.errors[`presentations.${idx}.content`] || form.errors[`presentations.${idx}.unit`] || form.errors[`presentations.${idx}.price`]" class="mt-2 space-y-1">
                                <InputError :message="form.errors[`presentations.${idx}.name`]" />
                                <InputError :message="form.errors[`presentations.${idx}.content`]" />
                                <InputError :message="form.errors[`presentations.${idx}.unit`]" />
                                <InputError :message="form.errors[`presentations.${idx}.price`]" />
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 4. Visibilidad -->
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Visibilidad</h2>
                    <p class="mt-1 text-sm text-gray-500">Define donde sera visible este producto.</p>
                </div>
                <div class="grid gap-4 p-6 sm:grid-cols-2">
                    <button type="button" @click="form.visibility = 'public'"
                        :class="['flex items-start gap-4 rounded-xl p-5 text-left transition-all cursor-pointer',
                            form.visibility === 'public' ? 'ring-2 ring-green-500 bg-green-50/50' : 'ring-1 ring-gray-200 hover:ring-gray-300 hover:bg-gray-50']">
                        <div :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-lg', form.visibility === 'public' ? 'bg-green-100' : 'bg-gray-100']">
                            <svg class="h-5 w-5" :class="form.visibility === 'public' ? 'text-green-600' : 'text-gray-400'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                        </div>
                        <div>
                            <p class="text-sm font-bold" :class="form.visibility === 'public' ? 'text-green-800' : 'text-gray-900'">Publico</p>
                            <p class="mt-0.5 text-xs" :class="form.visibility === 'public' ? 'text-green-600' : 'text-gray-500'">Visible en la app externa y catalogo de la sucursal.</p>
                        </div>
                    </button>

                    <button type="button" @click="form.visibility = 'restricted'"
                        :class="['flex items-start gap-4 rounded-xl p-5 text-left transition-all cursor-pointer',
                            form.visibility === 'restricted' ? 'ring-2 ring-amber-500 bg-amber-50/50' : 'ring-1 ring-gray-200 hover:ring-gray-300 hover:bg-gray-50']">
                        <div :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-lg', form.visibility === 'restricted' ? 'bg-amber-100' : 'bg-gray-100']">
                            <svg class="h-5 w-5" :class="form.visibility === 'restricted' ? 'text-amber-600' : 'text-gray-400'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
                        </div>
                        <div>
                            <p class="text-sm font-bold" :class="form.visibility === 'restricted' ? 'text-amber-800' : 'text-gray-900'">Restringido</p>
                            <p class="mt-0.5 text-xs" :class="form.visibility === 'restricted' ? 'text-amber-600' : 'text-gray-500'">Solo visible dentro del sistema interno.</p>
                        </div>
                    </button>
                </div>
                <InputError :message="form.errors.visibility" class="px-6 pb-4" />

                <!-- Visibilidad online (menú web) -->
                <div class="border-t border-gray-100 p-6">
                    <label class="flex cursor-pointer items-start gap-3">
                        <input type="checkbox" v-model="form.visible_online" class="mt-0.5 rounded text-red-600" />
                        <div>
                            <p class="text-sm font-bold text-gray-900">Visible en menú web</p>
                            <p class="mt-0.5 text-xs text-gray-500">Permite que los clientes vean y pidan este producto desde la URL pública /menu/{slug} de la empresa.</p>
                        </div>
                    </label>
                </div>
            </section>

            <!-- 4. Imagen -->
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Imagen del Producto</h2>
                    <p class="mt-1 text-sm text-gray-500">Sube una fotografia del producto. Formatos: JPG, PNG o WebP. Maximo 2MB.</p>
                </div>
                <div class="p-6">
                    <!-- Preview -->
                    <div v-if="imagePreview" class="space-y-3">
                        <div class="overflow-hidden rounded-xl ring-1 ring-gray-200">
                            <img :src="imagePreview" class="mx-auto max-h-48 object-cover" />
                        </div>
                        <div class="flex gap-3">
                            <button type="button" @click="fileInput?.click()" class="text-sm font-semibold text-red-600 transition hover:text-red-700">Cambiar imagen</button>
                            <button type="button" @click="removeImage" class="text-sm text-gray-400 transition hover:text-gray-600">Quitar</button>
                        </div>
                    </div>

                    <!-- Drop zone -->
                    <div v-else @click="fileInput?.click()" class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-gray-200 px-6 py-12 transition hover:border-red-300 hover:bg-red-50/30">
                        <svg class="h-10 w-10 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5a2.25 2.25 0 0 0 2.25-2.25V5.25a2.25 2.25 0 0 0-2.25-2.25H3.75a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 3.75 21Z" /></svg>
                        <p class="mt-3 text-sm font-medium text-gray-500">Haz click para seleccionar una imagen</p>
                        <p class="mt-1 text-xs text-gray-400">JPG, PNG o WebP. Maximo 2MB.</p>
                    </div>

                    <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onFileSelect" />
                    <InputError :message="form.errors.image" class="mt-2" />
                </div>
            </section>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-3 pb-8">
                <Link :href="route('sucursal.productos.index', tenant.slug)" class="rounded-lg px-5 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-100">Cancelar</Link>
                <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-6 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50">
                    Crear Producto
                </button>
            </div>
        </form>

        <FlashToast />
    </SucursalLayout>
</template>
