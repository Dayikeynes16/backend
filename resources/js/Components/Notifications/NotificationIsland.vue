<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import IslandIcon from './IslandIcon.vue';
import NotificationRow from './NotificationRow.vue';
import { useNotifications } from '@/composables/useNotifications.js';
import {
    ISLAND_GAP,
    LEVEL_STYLE,
    groupItems,
    hasPendingAction,
    isAgendaReminder,
    islandPlacement,
    levelOf,
    relativeTime,
} from '@/lib/notificationsCore.js';
import { routeFor } from '@/lib/notificationRoutes.js';

/**
 * La isla: a la vez la campana (reposo), el anuncio de lo que acaba de llegar y
 * la bandeja. Un solo elemento que cambia de forma, para que nunca haya que
 * buscar «adónde fue» un aviso. Port de `carniceria-hub`
 * (`src/renderer/components/NotificationIsland.vue`).
 * Spec: docs/superpowers/specs/2026-09-24-isla-web-design.md §4
 *
 * Cómo se monta: el layout la pone como PRIMER hijo del grupo de botones de la
 * derecha del encabezado, marca el encabezado con `data-island-header` (y
 * `relative`) y el título con `data-island-title`. Así la medición no depende
 * de la forma de cada layout, que es distinta en los cuatro.
 *
 * Diferencia de fondo con el hub: allí, acoplada, la isla flota en absoluto a la
 * izquierda del grupo. En la web el título de cada página ocupa todo el ancho
 * libre (`flex-1`) y muchas páginas ponen botones a su derecha, así que flotar
 * encima los taparía. Acoplada, la isla ocupa su hueco DENTRO del grupo (el
 * flujo empuja al título) y sólo lo que se abre flota. Centrada, no ocupa nada.
 */
const page = usePage();
const center = useNotifications();
const { items, unreadCount, current, queue, trayOpen, online, lastSyncedAt, pulse, highlightId } = center;

const auth = computed(() => page.props.auth ?? {});
const slug = computed(() => auth.value.tenant_slug ?? null);
// `route` de Ziggy se resuelve al llamar, no al montar: así un cambio de slug no deja un contexto viejo.
const ctx = computed(() => ({ role: auth.value.role, slug: slug.value, route: (name, params) => route(name, params) }));

/*
 * El motor es un singleton que arranca una vez por usuario. El layout (y con él
 * la isla) se vuelve a montar en cada navegación de Inertia; aquí sólo se le
 * pide que esté corriendo, y al desmontar NO se detiene: un anuncio en curso
 * sobrevive a la navegación y no se pierde lo que llegue entre dos páginas.
 */
if (typeof window !== 'undefined' && auth.value.user?.id) {
    center.ensureStarted({ id: auth.value.user.id, role: auth.value.role });
}

const root = ref(null);

/* Teléfono: siempre acoplada y, abierta, a ancho de pantalla (§4.2). */
const phoneQuery = typeof window !== 'undefined' ? window.matchMedia('(max-width: 639px)') : null;
const phone = ref(phoneQuery?.matches ?? false);
const onPhoneChange = (e) => {
    phone.value = e.matches;
};

/*
 * Centrada si cabe; si no, acoplada al grupo derecho (ver `islandPlacement`).
 * `top` alinea la píldora centrada con la fila de botones; `room` es cuánto
 * puede crecer hacia la izquierda la acoplada sin salirse del encabezado;
 * `phoneTop` es dónde empieza, en el teléfono, lo que se abre (bajo el encabezado).
 */
const placement = ref({ docked: true, top: 13, room: Infinity, phoneTop: 72 });
let resizeObserver = null;
let mutationObserver = null;
let frame = 0;

/**
 * Borde derecho de lo que de verdad se ve en el título. El contenedor del slot
 * es `flex-1` (llega hasta el grupo) y los `h1` son bloques de ancho completo,
 * así que su rectángulo no sirve: se mide el texto y los controles.
 */
function contentRight(el, fallback) {
    let right = -Infinity;
    const range = document.createRange();
    const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    while (walker.nextNode()) {
        const node = walker.currentNode;
        if (!node.textContent.trim()) continue;
        range.selectNodeContents(node);
        for (const r of range.getClientRects()) right = Math.max(right, r.right);
    }
    el.querySelectorAll('button, input, select, textarea, svg, img').forEach((n) => {
        const r = n.getBoundingClientRect();
        if (r.width) right = Math.max(right, r.right);
    });
    return right === -Infinity ? fallback : right;
}

function measure() {
    const el = root.value;
    const group = el?.parentElement;
    const header = el?.closest('[data-island-header]');
    if (!el || !group || !header) return;

    const h = header.getBoundingClientRect();
    const g = group.getBoundingClientRect();
    const title = header.querySelector('[data-island-title]');
    // El grupo sin la isla: si no, el hueco que ella misma reserva acoplada la
    // mantendría acoplada aunque ya cupiera centrada.
    const others = [...group.children].filter((c) => c !== el && c.getClientRects().length);
    const groupLeft = others.length ? Math.min(...others.map((c) => c.getBoundingClientRect().left)) : g.right;

    const { docked } = islandPlacement({
        header: h,
        title: { right: title ? contentRight(title, h.left) : h.left },
        group: { left: groupLeft },
    });
    const rowMiddle = g.height ? g.top + g.height / 2 : h.top + h.height / 2;
    placement.value = {
        docked,
        top: Math.max(0, Math.round(rowMiddle - h.top - 15)),
        room: Math.round(el.getBoundingClientRect().right - h.left - ISLAND_GAP),
        phoneTop: Math.max(8, Math.round(h.bottom + 8)),
    };
}

/* Varias señales en el mismo cuadro (resize + mutación) se miden una sola vez. */
function scheduleMeasure() {
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(measure);
}

const mode = computed(() => (trayOpen.value ? 'tray' : current.value ? 'announce' : 'rest'));
const RANK = { rest: 0, announce: 1, tray: 2 };
/** Crecer usa la curva de entrada y 240 ms; encoger, la de salida y 180 ms. */
const growing = ref(false);
watch(mode, (now, before) => {
    growing.value = RANK[now] > RANK[before];
    // En el teléfono lo abierto se pega bajo el encabezado; si la página se
    // desplazó (el de AuthenticatedLayout no es pegajoso), el borde cambió.
    if (now !== 'rest') measure();
});

const docked = computed(() => phone.value || placement.value.docked);
const restWidth = computed(() => (unreadCount.value === 0 ? 30 : unreadCount.value > 9 ? 62 : 54));
const floating = computed(() => phone.value && mode.value !== 'rest');

const PHONE_WIDTH = 'calc(100vw - 32px)';
const PHONE_TRAY_HEIGHT = 'min(480px, calc(100dvh - 5rem))';

/* Tamaño de lo que hay dentro: fijo durante el morph para que el texto no se reacomode mientras crece la caja. */
const content = computed(() => {
    if (mode.value === 'tray') {
        return phone.value ? { width: PHONE_WIDTH, height: PHONE_TRAY_HEIGHT } : { width: '380px', height: '480px' };
    }
    return { width: phone.value ? PHONE_WIDTH : '340px' };
});

const box = computed(() => {
    if (mode.value === 'tray') return { ...content.value, borderRadius: '22px' };
    if (mode.value === 'announce') {
        return { width: content.value.width, height: current.value?.reason ? '108px' : '92px', borderRadius: '20px' };
    }
    return { width: `${restWidth.value}px`, height: '30px', borderRadius: '15px' };
});

/*
 * Dónde va la caja. Acoplada crece hacia la izquierda desde el hueco del grupo;
 * si no cabe (tablet estrecha), se corre a la derecha lo justo para no salirse
 * del encabezado, como `islandRight` en el hub.
 */
const position = computed(() => {
    if (floating.value) return { position: 'fixed', left: '16px', top: `${placement.value.phoneTop}px` };
    if (docked.value) {
        const overflow = Math.max(0, parseInt(box.value.width, 10) - placement.value.room);
        return { right: `${-overflow}px`, top: '0px' };
    }
    return { top: `${placement.value.top}px` };
});

const badge = computed(() => (unreadCount.value > 9 ? '9+' : String(unreadCount.value)));
const pendingAction = computed(() => hasPendingAction(items.value));
const groups = computed(() => groupItems(items.value));
const SECTIONS = [
    { key: 'pending', label: 'Por atender' },
    { key: 'today', label: 'Hoy' },
    { key: 'earlier', label: 'Antes' },
];

const style = (n) => LEVEL_STYLE[levelOf(n)];
const destination = (n) => routeFor(n, ctx.value);
// Sin tenant (superadmin en su perfil) no hay ruta a la que mandar la acción.
const isReminder = (n) => isAgendaReminder(n) && !!slug.value && n.item_id != null;

/*
 * Presets de movimiento (los del hub, `lib/motion.js`). Viven aquí y no en un
 * .js porque Tailwind sólo escanea los `.vue` de `resources/js`.
 * La caja anima `width`/`height`/`border-radius`: se acepta sólo aquí, es un
 * único elemento pequeño fuera del flujo que no recalcula el layout de nada más.
 */
const ISLAND = {
    enterActiveClass: 'transition duration-normal ease-enter delay-[60ms]',
    enterFromClass: 'scale-[.97] opacity-0',
    enterToClass: 'scale-100 opacity-100',
    leaveActiveClass: 'transition duration-fast ease-exit',
    leaveFromClass: 'opacity-100',
    leaveToClass: 'opacity-0',
};
const LIST = {
    enterActiveClass: 'transition duration-normal ease-enter',
    enterFromClass: 'translate-y-1 opacity-0',
    enterToClass: 'translate-y-0 opacity-100',
    leaveActiveClass: 'absolute transition duration-fast ease-exit',
    leaveFromClass: 'opacity-100',
    leaveToClass: 'scale-[.98] opacity-0',
    moveClass: 'transition-transform duration-normal ease-state',
};

/* Latido: se quita la clase y se vuelve a poner para que se repita. */
const beating = ref(false);
watch(pulse, () => {
    beating.value = false;
    requestAnimationFrame(() => {
        beating.value = true;
    });
});

function review(n) {
    const url = center.review(n, ctx.value);
    if (url) router.visit(url);
}

/*
 * Acciones del recordatorio. El backend cierra el ítem y marca leídos sus
 * avisos; `settle` recoge el anuncio y relee. Se llama en `onFinish` (también si
 * falla): lo que diga la relectura manda, y la isla no se queda trabada.
 */
const ACTIONS = {
    complete: ['agenda.complete', {}],
    snooze: ['agenda.snooze', { minutes: 30 }],
    seen: ['agenda.visto', {}],
};
const busyId = ref(null);

function act(n, kind) {
    if (busyId.value) return;
    const [name, payload] = ACTIONS[kind];
    busyId.value = n.id;
    router.patch(route(name, [slug.value, n.item_id]), payload, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            busyId.value = null;
            center.settle(n);
        },
    });
}

function toggle() {
    if (trayOpen.value) center.closeTray();
    else center.openTray();
}

/* Esc cierra la bandeja, salvo que haya un modal encima: ese Esc es suyo. */
function onKey(e) {
    if (e.key === 'Escape' && trayOpen.value && !document.querySelector('dialog[open], [aria-modal="true"]')) center.closeTray();
}

/* Clic fuera cierra. Abrir un modal desde otra parte es un clic fuera. */
function onPointerDown(e) {
    if (trayOpen.value && root.value && !root.value.contains(e.target)) center.closeTray();
}

/* Sin puente nativo: volver a la pestaña es la señal para ponerse al día. */
function onActive() {
    if (!document.hidden) center.onWindowActive();
}

let offNavigate = () => {};

onMounted(() => {
    document.addEventListener('keydown', onKey);
    document.addEventListener('pointerdown', onPointerDown, true);
    document.addEventListener('visibilitychange', onActive);
    window.addEventListener('focus', onActive);
    phoneQuery?.addEventListener('change', onPhoneChange);
    offNavigate = router.on('navigate', () => {
        if (trayOpen.value) center.closeTray();
    });

    const el = root.value;
    const header = el?.closest('[data-island-header]');
    const title = header?.querySelector('[data-island-title]');
    if (header && typeof ResizeObserver === 'function') {
        resizeObserver = new ResizeObserver(scheduleMeasure);
        [header, el.parentElement, title].forEach((n) => n && resizeObserver.observe(n));
    }
    // El título cambia de texto sin cambiar de tamaño (su contenedor es flex-1).
    if (title && typeof MutationObserver === 'function') {
        mutationObserver = new MutationObserver(scheduleMeasure);
        mutationObserver.observe(title, { childList: true, subtree: true, characterData: true });
    }
    measure();
});

onUnmounted(() => {
    document.removeEventListener('keydown', onKey);
    document.removeEventListener('pointerdown', onPointerDown, true);
    document.removeEventListener('visibilitychange', onActive);
    window.removeEventListener('focus', onActive);
    phoneQuery?.removeEventListener('change', onPhoneChange);
    offNavigate();
    resizeObserver?.disconnect();
    mutationObserver?.disconnect();
    cancelAnimationFrame(frame);
    // Sin `center.stop()`: ver arriba, el motor sobrevive a la navegación.
});
</script>

<template>
    <!--
      Acoplada, este envoltorio reserva el hueco de la píldora dentro del grupo.
      Centrada es `contents`: no ocupa nada y la caja se posiciona respecto del
      encabezado (`relative`).
    -->
    <div
        ref="root"
        :class="docked ? 'relative h-[30px] shrink-0' : 'contents'"
        :style="docked ? { width: `${restWidth}px` } : null"
    >
        <div
            class="island-box absolute z-40 overflow-hidden bg-gray-900 text-white shadow-2xl shadow-black/30 ring-1 ring-white/10 transition-[width,height,border-radius,right]"
            :class="[growing ? 'duration-slow ease-enter' : 'duration-normal ease-exit', docked ? '' : 'left-1/2 -translate-x-1/2']"
            :style="[box, position]"
        >
            <Transition v-bind="ISLAND" mode="out-in">
                <!-- Reposo: la campana -->
                <button
                    v-if="mode === 'rest'"
                    key="rest"
                    type="button"
                    class="flex h-[30px] w-full items-center justify-center gap-1.5 px-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/50"
                    :aria-label="unreadCount ? `Avisos: ${unreadCount} sin leer` : 'Avisos'"
                    @click="toggle"
                >
                    <span class="relative grid place-items-center" :class="beating ? 'island-beat' : ''" @animationend="beating = false">
                        <IslandIcon name="bell" :size="16" />
                        <span v-if="pendingAction" class="island-dot absolute -right-0.5 -top-0.5 h-2 w-2 rounded-full bg-rose-500" />
                    </span>
                    <span v-if="unreadCount" class="text-[11px] font-bold tabular-nums">{{ badge }}</span>
                </button>

                <!-- Anunciando -->
                <div
                    v-else-if="mode === 'announce'"
                    :key="`a-${current.id}`"
                    class="flex gap-3 p-3"
                    :style="content"
                    role="status"
                    aria-live="polite"
                    @mouseenter="center.pauseAuto()"
                    @mouseleave="center.resumeAuto()"
                >
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl" :class="style(current).chip">
                        <IslandIcon :name="style(current).icon" :size="18" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <!-- Un recordatorio lleva a Agenda al tocar el cuerpo; el resto abre la bandeja, como en el hub. -->
                        <button type="button" class="block w-full text-left" @click="isReminder(current) ? review(current) : center.openTray()">
                            <span class="block truncate text-sm font-semibold">{{ current.title }}</span>
                            <span class="block truncate text-xs text-gray-300">{{ current.body }}</span>
                            <span v-if="current.reason" class="block truncate text-[11px] italic text-gray-400">“{{ current.reason }}”</span>
                        </button>
                        <div class="mt-1.5 flex items-center gap-1.5">
                            <template v-if="isReminder(current)">
                                <button
                                    type="button"
                                    :disabled="busyId === current.id"
                                    class="rounded-full bg-white px-3 py-1 text-[11px] font-bold text-gray-900 transition hover:bg-gray-200 disabled:opacity-40"
                                    @click="act(current, 'complete')"
                                >
                                    Hecho
                                </button>
                                <button
                                    type="button"
                                    :disabled="busyId === current.id"
                                    class="rounded-full bg-white/10 px-3 py-1 text-[11px] font-semibold text-gray-200 transition hover:bg-white/20 disabled:opacity-40"
                                    @click="act(current, 'snooze')"
                                >
                                    +30 min
                                </button>
                                <button
                                    type="button"
                                    :disabled="busyId === current.id"
                                    class="rounded-full bg-white/10 px-3 py-1 text-[11px] font-semibold text-gray-200 transition hover:bg-white/20 disabled:opacity-40"
                                    @click="act(current, 'seen')"
                                >
                                    Visto
                                </button>
                            </template>
                            <template v-else-if="destination(current)">
                                <button
                                    type="button"
                                    class="rounded-full bg-white px-3 py-1 text-[11px] font-bold text-gray-900 transition hover:bg-gray-200"
                                    @click="review(current)"
                                >
                                    Revisar
                                </button>
                                <button
                                    type="button"
                                    class="rounded-full bg-white/10 px-3 py-1 text-[11px] font-semibold text-gray-200 transition hover:bg-white/20"
                                    @click="center.dismiss()"
                                >
                                    Luego
                                </button>
                            </template>
                            <button
                                v-else
                                type="button"
                                class="rounded-full bg-white px-3 py-1 text-[11px] font-bold text-gray-900 transition hover:bg-gray-200"
                                @click="review(current)"
                            >
                                Entendido
                            </button>
                            <button
                                v-if="queue.length"
                                type="button"
                                class="ml-auto shrink-0 rounded-full bg-white/10 px-2 py-1 text-[11px] font-semibold text-gray-300 transition hover:bg-white/20"
                                @click="center.openTray()"
                            >
                                +{{ queue.length }} más
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Bandeja -->
                <div v-else key="tray" class="flex flex-col" :style="content">
                    <div class="flex items-center justify-between px-4 pb-1 pt-3">
                        <p class="text-sm font-bold">Avisos</p>
                        <div class="flex items-center gap-1">
                            <button
                                v-if="unreadCount"
                                type="button"
                                class="rounded-full px-2 py-1 text-[11px] font-semibold text-rose-300 transition hover:bg-white/10 disabled:opacity-40"
                                :disabled="online === false"
                                @click="center.markAllRead()"
                            >
                                Marcar todo leído
                            </button>
                            <button
                                type="button"
                                class="grid h-7 w-7 place-items-center rounded-full text-gray-400 transition hover:bg-white/10 hover:text-white"
                                aria-label="Cerrar avisos"
                                @click="center.closeTray()"
                            >
                                <IslandIcon name="x-mark" :size="16" />
                            </button>
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto px-2 pb-2">
                        <p v-if="!items.length" class="px-3 py-12 text-center text-sm text-gray-400">
                            {{ online === false ? 'Sin conexión: los avisos aparecerán al volver la red.' : 'Sin avisos.' }}
                        </p>
                        <template v-for="section in SECTIONS" :key="section.key">
                            <template v-if="groups[section.key].length">
                                <p class="px-3 pb-1 pt-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">{{ section.label }}</p>
                                <TransitionGroup v-bind="LIST" tag="div" class="relative">
                                    <NotificationRow
                                        v-for="n in groups[section.key]"
                                        :key="n.id"
                                        :n="n"
                                        :highlight="n.id === highlightId"
                                        :reminder="isReminder(n)"
                                        :busy="busyId === n.id"
                                        @select="review(n)"
                                        @act="(kind) => act(n, kind)"
                                    />
                                </TransitionGroup>
                            </template>
                        </template>
                    </div>

                    <p v-if="online === false" class="border-t border-white/10 px-4 py-2 text-[11px] text-gray-400">
                        Sin conexión · actualizado {{ lastSyncedAt ? relativeTime(lastSyncedAt) : 'nunca' }}
                    </p>
                </div>
            </Transition>
        </div>
    </div>
</template>
