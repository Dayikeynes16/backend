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
    visibility: 'public',
    image: null,
    unit_type: 'piece',
});

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
                        <InputLabel for="price" value="Precio de venta" />
                        <div class="relative mt-1.5">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">$</span>
                            <input id="price" v-model="form.price" type="number" step="0.01" min="0.01" required placeholder="0.00"
                                class="block w-full rounded-md border-gray-300 pl-7 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                        </div>
                        <p class="mt-1.5 text-xs text-gray-400">Monto que se cobra al cliente.</p>
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

            <!-- 3. Visibilidad -->
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
