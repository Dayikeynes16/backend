<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    empresa: Object,
    branches: Array,
    tenantAdmins: Array,
    salesByBranch: Object,
    maxSalesBranch: Number,
});

const form = useForm({
    name: props.empresa.name,
    slug: props.empresa.slug,
    rfc: props.empresa.rfc || '',
    address: props.empresa.address || '',
    phone: props.empresa.phone || '',
    max_branches: props.empresa.max_branches || 1,
    max_users: props.empresa.max_users || 5,
    max_sales_per_branch_month: props.empresa.max_sales_per_branch_month || 500,
    status: props.empresa.status,
});

const submit = () => form.put(route('admin.empresas.update', props.empresa.id));

const destroy = () => {
    if (confirm('¿Eliminar esta empresa permanentemente? Se borraran todas las sucursales, productos, ventas y usuarios.')) {
        router.delete(route('admin.empresas.destroy', props.empresa.id));
    }
};

const usage = (current, max) => {
    const pct = max > 0 ? Math.min((current / max) * 100, 100) : 0;
    return { current, max, pct };
};
const barColor = (pct) => pct >= 90 ? 'bg-red-500' : pct >= 60 ? 'bg-orange-400' : 'bg-green-500';
const barTextColor = (pct) => pct >= 90 ? 'text-red-600' : pct >= 60 ? 'text-orange-600' : 'text-green-600';

const expandedBranches = ref({});
const toggleBranch = (id) => {
    expandedBranches.value[id] = !expandedBranches.value[id];
};

const roleBadge = (roleName) => {
    const map = {
        'admin-empresa': { label: 'Admin Empresa', cls: 'bg-red-50 text-red-700 ring-red-600/20' },
        'admin-sucursal': { label: 'Admin Sucursal', cls: 'bg-orange-50 text-orange-700 ring-orange-600/20' },
        'cajero': { label: 'Cajero', cls: 'bg-gray-100 text-gray-700 ring-gray-600/10' },
    };
    return map[roleName] || { label: roleName, cls: 'bg-gray-100 text-gray-700 ring-gray-600/10' };
};
</script>

<template>
    <Head :title="`Editar: ${empresa.name}`" />
    <AdminLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm">
                <Link :href="route('admin.empresas.index')" class="text-gray-400 transition hover:text-gray-600">Empresas</Link>
                <svg class="h-4 w-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                <span class="font-semibold text-gray-900">{{ empresa.name }}</span>
            </div>
        </template>

        <form @submit.prevent="submit" class="mx-auto max-w-3xl space-y-6">
            <!-- 1. Información General -->
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-bold text-gray-900">Informacion General</h2>
                    <p class="mt-0.5 text-xs text-gray-400">Datos de identificacion de la empresa.</p>
                </div>
                <div class="space-y-5 p-6">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel for="name" value="Nombre" />
                            <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.name" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="slug" value="Slug (URL)" />
                            <div class="mt-1 flex rounded-md shadow-sm">
                                <span class="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-50 px-3 text-xs text-gray-400">app.com/</span>
                                <input id="slug" v-model="form.slug" type="text" required class="block w-full min-w-0 rounded-none rounded-r-md border-gray-300 text-sm focus:border-red-300 focus:ring-red-200" />
                            </div>
                            <InputError :message="form.errors.slug" class="mt-1" />
                        </div>
                    </div>
                    <div>
                        <InputLabel for="rfc" value="RFC" />
                        <TextInput id="rfc" v-model="form.rfc" type="text" class="mt-1 block w-full sm:w-1/2" />
                        <InputError :message="form.errors.rfc" class="mt-1" />
                    </div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel for="address" value="Direccion" />
                            <TextInput id="address" v-model="form.address" type="text" class="mt-1 block w-full" />
                            <InputError :message="form.errors.address" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="phone" value="Telefono" />
                            <TextInput id="phone" v-model="form.phone" type="text" class="mt-1 block w-full" />
                            <InputError :message="form.errors.phone" class="mt-1" />
                        </div>
                    </div>
                </div>
            </section>

            <!-- 2. Configuración SaaS -->
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-bold text-gray-900">Configuracion SaaS</h2>
                    <p class="mt-0.5 text-xs text-gray-400">Limites y estado de la empresa.</p>
                </div>
                <div class="space-y-5 p-6">
                    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <InputLabel for="max_branches" value="Max. sucursales" />
                            <TextInput id="max_branches" v-model="form.max_branches" type="number" min="1" max="100" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.max_branches" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="max_users" value="Max. usuarios" />
                            <TextInput id="max_users" v-model="form.max_users" type="number" min="1" max="500" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.max_users" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="max_sales" value="Max. ventas/suc/30d" />
                            <TextInput id="max_sales" v-model="form.max_sales_per_branch_month" type="number" min="1" max="10000" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.max_sales_per_branch_month" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="status" value="Estado" />
                            <select id="status" v-model="form.status" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-300 focus:ring-red-200">
                                <option value="active">Activa</option>
                                <option value="inactive">Inactiva</option>
                            </select>
                            <InputError :message="form.errors.status" class="mt-1" />
                        </div>
                    </div>
                </div>
            </section>

            <!-- 3. Observabilidad -->
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-bold text-gray-900">Observabilidad</h2>
                    <p class="mt-0.5 text-xs text-gray-400">Uso actual vs limites configurados.</p>
                </div>
                <div class="p-6">
                    <div class="grid gap-6 sm:grid-cols-3">
                        <!-- Sucursales -->
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Sucursales</p>
                            <p class="mt-1.5 text-2xl font-bold text-gray-900">
                                {{ usage(empresa.branches_count, empresa.max_branches).current }}
                                <span class="text-sm font-normal text-gray-400">/ {{ usage(empresa.branches_count, empresa.max_branches).max }}</span>
                            </p>
                            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                                <div class="h-full rounded-full transition-all" :class="barColor(usage(empresa.branches_count, empresa.max_branches).pct)" :style="{ width: usage(empresa.branches_count, empresa.max_branches).pct + '%' }" />
                            </div>
                            <p class="mt-1 text-[11px]" :class="barTextColor(usage(empresa.branches_count, empresa.max_branches).pct)">{{ Math.round(usage(empresa.branches_count, empresa.max_branches).pct) }}% usado</p>
                        </div>
                        <!-- Usuarios -->
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Usuarios</p>
                            <p class="mt-1.5 text-2xl font-bold text-gray-900">
                                {{ usage(empresa.users_count, empresa.max_users).current }}
                                <span class="text-sm font-normal text-gray-400">/ {{ usage(empresa.users_count, empresa.max_users).max }}</span>
                            </p>
                            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                                <div class="h-full rounded-full transition-all" :class="barColor(usage(empresa.users_count, empresa.max_users).pct)" :style="{ width: usage(empresa.users_count, empresa.max_users).pct + '%' }" />
                            </div>
                            <p class="mt-1 text-[11px]" :class="barTextColor(usage(empresa.users_count, empresa.max_users).pct)">{{ Math.round(usage(empresa.users_count, empresa.max_users).pct) }}% usado</p>
                        </div>
                        <!-- Ventas (30d, sucursal mayor) -->
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Ventas/30d (mayor)</p>
                            <p class="mt-1.5 text-2xl font-bold text-gray-900">
                                {{ usage(maxSalesBranch, empresa.max_sales_per_branch_month).current }}
                                <span class="text-sm font-normal text-gray-400">/ {{ usage(maxSalesBranch, empresa.max_sales_per_branch_month).max }}</span>
                            </p>
                            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                                <div class="h-full rounded-full transition-all" :class="barColor(usage(maxSalesBranch, empresa.max_sales_per_branch_month).pct)" :style="{ width: usage(maxSalesBranch, empresa.max_sales_per_branch_month).pct + '%' }" />
                            </div>
                            <p class="mt-1 text-[11px]" :class="barTextColor(usage(maxSalesBranch, empresa.max_sales_per_branch_month).pct)">{{ Math.round(usage(maxSalesBranch, empresa.max_sales_per_branch_month).pct) }}% usado</p>
                        </div>
                    </div>
                    <p class="mt-4 text-[11px] text-gray-400">
                        Creada el {{ new Date(empresa.created_at).toLocaleDateString('es-MX', { day: '2-digit', month: 'long', year: 'numeric' }) }}
                    </p>
                </div>
            </section>

            <!-- 4. Estructura de la Empresa (jerárquica) -->
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-bold text-gray-900">Estructura de la Empresa</h2>
                    <p class="mt-0.5 text-xs text-gray-400">Sucursales, usuarios y roles.</p>
                </div>
                <div class="p-6">
                    <!-- Tenant-level admins -->
                    <div v-if="tenantAdmins.length > 0" class="mb-4">
                        <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-400">Administradores de empresa</p>
                        <div class="space-y-1.5">
                            <div v-for="admin in tenantAdmins" :key="admin.id" class="flex items-center gap-3 rounded-lg bg-gray-50 px-4 py-2.5">
                                <div class="flex h-7 w-7 items-center justify-center rounded-full bg-red-100 text-[11px] font-bold text-red-700">
                                    {{ admin.name.charAt(0) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-gray-900">{{ admin.name }}</p>
                                    <p class="truncate text-[11px] text-gray-400">{{ admin.email }}</p>
                                </div>
                                <span v-if="admin.roles?.[0]" :class="roleBadge(admin.roles[0].name).cls" class="rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset">
                                    {{ roleBadge(admin.roles[0].name).label }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Branches -->
                    <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-400">Sucursales</p>

                    <div v-if="branches.length === 0" class="rounded-lg border border-dashed border-gray-200 px-4 py-8 text-center">
                        <p class="text-sm text-gray-400">Esta empresa no tiene sucursales.</p>
                    </div>

                    <div v-else class="space-y-2">
                        <div v-for="branch in branches" :key="branch.id" class="overflow-hidden rounded-lg border border-gray-100">
                            <!-- Branch header -->
                            <button type="button" @click="toggleBranch(branch.id)"
                                class="flex w-full items-center justify-between bg-gray-50/80 px-4 py-3 text-left transition hover:bg-gray-100/80">
                                <div class="flex items-center gap-3">
                                    <svg class="h-4 w-4 text-orange-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 0 0 3.75.614m-16.5 0a3.004 3.004 0 0 1-.621-4.72l1.189-1.19A1.5 1.5 0 0 1 5.378 3h13.243a1.5 1.5 0 0 1 1.06.44l1.19 1.189a3 3 0 0 1-.621 4.72M6.75 18h3.75a.75.75 0 0 0 .75-.75V13.5a.75.75 0 0 0-.75-.75H6.75a.75.75 0 0 0-.75.75v3.75c0 .414.336.75.75.75Z" />
                                    </svg>
                                    <div>
                                        <span class="text-sm font-semibold text-gray-900">{{ branch.name }}</span>
                                        <span class="ml-2 text-[11px] text-gray-400">{{ branch.users_count }} usuario{{ branch.users_count !== 1 ? 's' : '' }}</span>
                                        <span v-if="salesByBranch[branch.id]" class="ml-2 text-[11px] text-gray-400">· {{ salesByBranch[branch.id] }} ventas/30d</span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span :class="branch.status === 'active' ? 'bg-green-50 text-green-700 ring-green-600/20' : 'bg-red-50 text-red-700 ring-red-600/20'" class="rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset">{{ branch.status === 'active' ? 'Activa' : 'Inactiva' }}</span>
                                    <svg class="h-4 w-4 text-gray-400 transition-transform" :class="expandedBranches[branch.id] ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                </div>
                            </button>

                            <!-- Branch users (collapsible) -->
                            <Transition enter-active-class="transition duration-200" leave-active-class="transition duration-150" enter-from-class="opacity-0" leave-to-class="opacity-0">
                                <div v-if="expandedBranches[branch.id]" class="border-t border-gray-100">
                                    <div v-if="branch.users.length === 0" class="px-4 py-4 text-center text-xs text-gray-400">Sin usuarios asignados.</div>
                                    <div v-else class="divide-y divide-gray-50">
                                        <div v-for="user in branch.users" :key="user.id" class="flex items-center gap-3 px-4 py-2.5 pl-12">
                                            <div class="flex h-6 w-6 items-center justify-center rounded-full bg-gray-100 text-[10px] font-bold text-gray-500">
                                                {{ user.name.charAt(0) }}
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-xs font-medium text-gray-700">{{ user.name }}</p>
                                                <p class="truncate text-[11px] text-gray-400">{{ user.email }}</p>
                                            </div>
                                            <span v-if="user.roles?.[0]" :class="roleBadge(user.roles[0].name).cls" class="rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset">
                                                {{ roleBadge(user.roles[0].name).label }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </Transition>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-3">
                <Link :href="route('admin.empresas.index')" class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-100">Cancelar</Link>
                <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-red-500 disabled:opacity-50">
                    Guardar Cambios
                </button>
            </div>

            <!-- 5. Zona de Peligro -->
            <section class="rounded-xl border border-red-200 bg-red-50/50">
                <div class="px-6 py-4">
                    <h2 class="text-sm font-bold text-red-900">Zona de peligro</h2>
                    <p class="mt-0.5 text-xs text-red-600/70">Estas acciones son irreversibles.</p>
                </div>
                <div class="border-t border-red-200 px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-red-900">Eliminar empresa</p>
                            <p class="text-[11px] text-red-600/70">Se eliminaran sucursales, productos, ventas y usuarios.</p>
                        </div>
                        <button type="button" @click="destroy" class="rounded-lg border border-red-300 bg-white px-4 py-2 text-xs font-semibold text-red-700 transition hover:bg-red-50">
                            Eliminar
                        </button>
                    </div>
                </div>
            </section>

            <div class="h-8"></div>
        </form>
    </AdminLayout>
</template>
