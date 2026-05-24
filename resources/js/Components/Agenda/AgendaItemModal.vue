<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    tenantSlug: { type: String, required: true },
    branches: { type: Array, default: () => [] },
    assignableUsers: { type: Array, default: () => [] },
    item: { type: Object, default: null }, // null = crear
    prefill: { type: Object, default: null }, // propuesta IA para pre-rellenar al crear
});
const emit = defineEmits(['close']);

// Campos que vinieron de la IA (para marcarlos con ✨ en la UI).
const aiFields = ref(new Set());
const fromAi = (field) => aiFields.value.has(field);

const form = useForm({
    type: 'task',
    title: '',
    body: '',
    scope: 'personal',
    branch_id: '',
    assigned_to_user_id: '',
    starts_at: '',
    ends_at: '',
    all_day: false,
    remind_at: '',
    priority: 'normal',
    recurrence: 'none',
    recurrence_until: '',
});

const types = [
    ['task', 'Tarea'],
    ['event', 'Evento'],
    ['note', 'Nota'],
];

// Convierte un ISO/datetime del servidor al formato que espera <input datetime-local>.
const toLocalInput = (value) => {
    if (!value) return '';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return '';
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

// Aplica una propuesta IA al form (modo crear). Marca los campos que vinieron
// de la IA y NUNCA toca la asignación (assigned_to_user_id es manual por voz).
const applyPrefill = (p) => {
    form.reset();
    aiFields.value = new Set();
    const mark = (field, value) => {
        if (value !== null && value !== undefined && value !== '') {
            aiFields.value.add(field);
        }
    };
    form.type = p.type ?? 'task';
    mark('type', p.type);
    form.title = p.title ?? '';
    mark('title', p.title);
    form.body = p.body ?? '';
    mark('body', p.body);
    form.scope = p.scope ?? 'personal';
    mark('scope', p.scope);
    form.starts_at = toLocalInput(p.starts_at);
    mark('starts_at', form.starts_at);
    form.ends_at = toLocalInput(p.ends_at);
    mark('ends_at', form.ends_at);
    form.remind_at = toLocalInput(p.remind_at);
    mark('remind_at', form.remind_at);
    form.recurrence = p.recurrence ?? 'none';
    mark('recurrence', p.recurrence && p.recurrence !== 'none' ? p.recurrence : null);
    form.priority = p.priority ?? 'normal';
    mark('priority', p.priority);
};

watch(
    () => props.open,
    (v) => {
        form.clearErrors();
        aiFields.value = new Set();
        if (v && props.item) {
            form.type = props.item.type ?? 'task';
            form.title = props.item.title ?? '';
            form.body = props.item.body ?? '';
            form.scope = props.item.scope ?? 'personal';
            form.branch_id = props.item.branch_id ?? '';
            form.assigned_to_user_id = props.item.assigned_to_user_id ?? '';
            form.starts_at = toLocalInput(props.item.starts_at);
            form.ends_at = toLocalInput(props.item.ends_at);
            form.all_day = !!props.item.all_day;
            form.remind_at = toLocalInput(props.item.remind_at);
            form.priority = props.item.priority ?? 'normal';
            form.recurrence = props.item.recurrence ?? 'none';
            form.recurrence_until = props.item.recurrence_until ?? '';
        } else if (v && props.prefill) {
            applyPrefill(props.prefill);
        } else if (v) {
            form.reset();
        }
    }
);

const isEdit = computed(() => !!props.item);
const needsBranch = computed(() => form.scope === 'branch');
const isEvent = computed(() => form.type === 'event');

const close = () => {
    form.clearErrors();
    emit('close');
};

const submit = () => {
    const opts = {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            emit('close');
        },
    };
    if (isEdit.value) {
        form.put(route('agenda.update', [props.tenantSlug, props.item.id]), opts);
    } else {
        form.post(route('agenda.store', props.tenantSlug), opts);
    }
};
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition" leave-active-class="transition"
            enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="open"
                class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 backdrop-blur-sm sm:items-center sm:p-4"
                @click.self="close">
                <div class="flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
                    <header class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                        <h2 class="text-lg font-bold text-gray-900">{{ isEdit ? 'Editar' : 'Nuevo' }} en agenda</h2>
                        <button type="button" @click="close" :disabled="form.processing" class="text-gray-400 hover:text-gray-700">✕</button>
                    </header>

                    <form @submit.prevent="submit" class="flex-1 space-y-4 overflow-y-auto px-5 py-5">
                        <div v-if="aiFields.size" class="flex items-center gap-2 rounded-xl bg-gradient-to-r from-violet-50 to-fuchsia-50 px-3 py-2 text-xs font-medium text-violet-800">
                            <span>✨ Lo pre-rellenó la IA. Revisa y ajusta antes de guardar.</span>
                        </div>
                        <div class="flex gap-2">
                            <button v-for="t in types" :key="t[0]" type="button"
                                @click="form.type = t[0]"
                                :class="['flex-1 rounded-xl border-2 py-2 text-sm font-bold transition', form.type === t[0] ? 'border-red-400 bg-red-50 text-red-700' : 'border-gray-200 text-gray-600 hover:border-gray-300']">
                                {{ t[1] }}
                            </button>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Título <span class="text-red-600">*</span><span v-if="fromAi('title')" class="ml-1 text-violet-500" title="Sugerido por IA">✨</span></label>
                            <input v-model="form.title" type="text" maxlength="160" placeholder="Ej. Pagar al proveedor"
                                class="w-full rounded-xl border-gray-300 text-sm focus:border-red-500 focus:ring-red-500" />
                            <p v-if="form.errors.title" class="mt-1 text-xs text-red-600">{{ form.errors.title }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Notas</label>
                            <textarea v-model="form.body" rows="2" placeholder="Detalles (opcional)"
                                class="w-full rounded-xl border-gray-300 text-sm focus:border-red-500 focus:ring-red-500"></textarea>
                            <p v-if="form.errors.body" class="mt-1 text-xs text-red-600">{{ form.errors.body }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Alcance</label>
                            <select v-model="form.scope" class="w-full rounded-xl border-gray-300 text-sm focus:border-red-500 focus:ring-red-500">
                                <option value="personal">Personal</option>
                                <option value="branch">Sucursal</option>
                                <option value="company">Empresa</option>
                            </select>
                            <p v-if="form.errors.scope" class="mt-1 text-xs text-red-600">{{ form.errors.scope }}</p>
                        </div>

                        <div v-if="needsBranch && branches.length > 1">
                            <label class="mb-1 block text-sm font-medium text-gray-700">Sucursal</label>
                            <select v-model="form.branch_id" class="w-full rounded-xl border-gray-300 text-sm focus:border-red-500 focus:ring-red-500">
                                <option value="">Selecciona sucursal…</option>
                                <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                            <p v-if="form.errors.branch_id" class="mt-1 text-xs text-red-600">{{ form.errors.branch_id }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">
                                Fecha y hora <span v-if="isEvent" class="text-red-600">*</span>
                            </label>
                            <input v-model="form.starts_at" type="datetime-local" :required="isEvent"
                                class="w-full rounded-xl border-gray-300 text-sm focus:border-red-500 focus:ring-red-500" />
                            <p v-if="form.errors.starts_at" class="mt-1 text-xs text-red-600">{{ form.errors.starts_at }}</p>
                        </div>

                        <div v-if="isEvent">
                            <label class="mb-1 block text-sm font-medium text-gray-700">Termina</label>
                            <input v-model="form.ends_at" type="datetime-local"
                                class="w-full rounded-xl border-gray-300 text-sm focus:border-red-500 focus:ring-red-500" />
                            <p v-if="form.errors.ends_at" class="mt-1 text-xs text-red-600">{{ form.errors.ends_at }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Recordatorio</label>
                            <input v-model="form.remind_at" type="datetime-local"
                                class="w-full rounded-xl border-gray-300 text-sm focus:border-red-500 focus:ring-red-500" />
                            <p v-if="form.errors.remind_at" class="mt-1 text-xs text-red-600">{{ form.errors.remind_at }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Repetir</label>
                            <select v-model="form.recurrence" class="w-full rounded-xl border-gray-300 text-sm focus:border-red-500 focus:ring-red-500">
                                <option value="none">No se repite</option>
                                <option value="daily">Diario</option>
                                <option value="weekly">Semanal</option>
                                <option value="monthly">Mensual</option>
                            </select>
                        </div>

                        <div v-if="form.type === 'task' && assignableUsers.length">
                            <label class="mb-1 block text-sm font-medium text-gray-700">Asignar a</label>
                            <select v-model="form.assigned_to_user_id" class="w-full rounded-xl border-gray-300 text-sm focus:border-red-500 focus:ring-red-500">
                                <option value="">Sin asignar</option>
                                <option v-for="u in assignableUsers" :key="u.id" :value="u.id">{{ u.name }}</option>
                            </select>
                            <p v-if="form.errors.assigned_to_user_id" class="mt-1 text-xs text-red-600">{{ form.errors.assigned_to_user_id }}</p>
                        </div>
                    </form>

                    <footer class="flex justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3">
                        <button type="button" @click="close" :disabled="form.processing"
                            class="rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">Cancelar</button>
                        <button type="button" @click="submit" :disabled="form.processing || !form.title.trim()"
                            class="rounded-xl bg-red-600 px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700 disabled:opacity-50">
                            {{ form.processing ? 'Guardando…' : 'Guardar' }}
                        </button>
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
