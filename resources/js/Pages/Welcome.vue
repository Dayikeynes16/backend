<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import EcosistemaDiagrama from '@/Components/Landing/EcosistemaDiagrama.vue';
import { useReveal, useCountUp } from '@/composables/useReveal.js';

/* ──────────────────────────────────────────────────────────────────────────
 * CAMBIA ESTO: número de WhatsApp al que llegan los interesados.
 * Formato internacional sin signos: 52 + 10 dígitos para México.
 * Es el único dato de contacto de la página; si cambia, cambia aquí y ya.
 * ────────────────────────────────────────────────────────────────────────── */
const WHATSAPP = '520000000000';
const MENSAJE = 'Hola, vi la página de Carnisoft y quiero saber más.';
const whatsappUrl = `https://wa.me/${WHATSAPP}?text=${encodeURIComponent(MENSAJE)}`;

defineProps({
  canLogin: { type: Boolean, default: false },
});

useReveal();

/* ── Contadores ───────────────────────────────────────────────────────────
 * Son hechos verificables del propio sistema, no métricas de negocio: cuatro
 * tipos de equipo registrables (báscula Windows y Android, hub Windows y
 * Android), los cuatro roles de Spatie y los ocho ejes de `docs/modulos/
 * metricas.md`. Nada aquí afirma clientes, ventas ni porcentajes. */
const equiposRef = ref(null);
const equipos = ref(0);
useCountUp(equiposRef, equipos, 4);

const rolesRef = ref(null);
const roles = ref(0);
useCountUp(rolesRef, roles, 4);

const ejesRef = ref(null);
const ejes = ref(0);
useCountUp(ejesRef, ejes, 8);

/* ── Simulación de la báscula ─────────────────────────────────────────────
 * Cuenta el flujo real —el peso llega solo y se convierte en una línea de
 * venta— mejor que cualquier párrafo. Arranca al entrar en pantalla y se
 * detiene sola: un bucle infinito aquí sería ruido. */
const basculaRef = ref(null);
const peso = ref(0);
const lineaVisible = ref(false);
let observador = null;
let raf = null;

const PESO_FINAL = 1.284;
const PRECIO_KG = 189;
const importe = computed(() => (peso.value * PRECIO_KG).toFixed(2));

onMounted(() => {
  const quieto = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches;
  if (quieto || !basculaRef.value) {
    peso.value = PESO_FINAL;
    lineaVisible.value = true;
    return;
  }

  observador = new IntersectionObserver(
    (entradas) => {
      if (!entradas[0]?.isIntersecting) return;
      observador.disconnect();

      const inicio = performance.now();
      const paso = (ahora) => {
        const t = Math.min(1, (ahora - inicio) / 1600);
        // La báscula real no sube lineal: se pasa un poco y se asienta.
        const suave = 1 - Math.pow(1 - t, 3);
        peso.value = Number((PESO_FINAL * suave).toFixed(3));
        if (t < 1) raf = requestAnimationFrame(paso);
        else setTimeout(() => (lineaVisible.value = true), 350);
      };
      raf = requestAnimationFrame(paso);
    },
    { threshold: 0.45 },
  );
  observador.observe(basculaRef.value);
});

onUnmounted(() => {
  observador?.disconnect();
  if (raf) cancelAnimationFrame(raf);
});

/* ── Mesa de trabajo simulada ─────────────────────────────────────────────
 * Interacción real, no decorado: los filtros son los mismos de la app. */
const filtro = ref('activas');
const ventas = ref([
  { id: 'S-00011', hora: '01:01 p.m.', total: '$180.00', pendiente: '$80.00', estado: 'activas', origen: 'Báscula', cliente: 'Ana Ruiz' },
  { id: 'S-00007', hora: '01:01 p.m.', total: '$150.00', pendiente: '$150.00', estado: 'activas', origen: 'Mostrador', cliente: 'Carmen Solís' },
  { id: 'S-00008', hora: '12:58 p.m.', total: '$180.00', pendiente: '$180.00', estado: 'activas', origen: 'Báscula', cliente: 'Carmen Solís' },
  { id: 'S-00005', hora: '12:50 p.m.', total: '$240.00', pendiente: '$240.00', estado: 'pendientes', origen: 'Báscula', cliente: null },
]);
const ventasFiltradas = computed(() => ventas.value.filter((v) => v.estado === filtro.value));

const capturas = [
  {
    src: '/capturas/equipos.jpg',
    alt: 'Panel de equipos de Carnisoft mostrando dos básculas y dos hubs con su versión, batería y dirección de red',
    titulo: 'Equipos',
    pie: 'Qué hay encendido en cada sucursal, con qué versión y cuánta pila le queda.',
  },
  {
    src: '/capturas/mesa-de-trabajo.jpg',
    alt: 'Mesa de trabajo de Carnisoft con las ventas activas en tarjetas',
    titulo: 'Mesa de trabajo',
    pie: 'Las ventas llegan de la báscula y se cobran desde aquí.',
  },
  {
    src: '/capturas/clientes.jpg',
    alt: 'Cartera de clientes de Carnisoft con saldos pendientes',
    titulo: 'Clientes y fiado',
    pie: 'Quién debe, cuánto y desde cuándo.',
  },
];
</script>

<template>
  <Head>
    <title>Carnisoft — punto de venta para vender a peso</title>
    <meta
      name="description"
      content="Carnisoft conecta tu báscula, tu caja y tus sucursales. Con el hub en el local, el mostrador sigue cobrando aunque se caiga el internet."
    />
    <meta property="og:title" content="Carnisoft — Punto de venta para negocios que venden a peso" />
    <meta
      property="og:description"
      content="Báscula, caja, tablets y sucursales trabajando juntas. Y el mostrador sigue cobrando aunque se caiga el internet."
    />
    <meta property="og:type" content="website" />
    <meta property="og:image" content="/logo-512.png" />
    <meta name="twitter:card" content="summary_large_image" />
  </Head>

  <div class="landing min-h-screen bg-[#050505] text-zinc-300 antialiased selection:bg-red-500 selection:text-white">
    <a href="#contenido" class="salto-al-contenido">Saltar al contenido</a>

    <!-- ══ Navegación ══════════════════════════════════════════════════ -->
    <header class="sticky top-0 z-50 border-b border-white/5 bg-[#050505]/80 backdrop-blur">
      <nav class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-5 py-4 sm:px-8" aria-label="Principal">
        <a href="#contenido" class="flex items-center gap-2.5">
          <img src="/logo.png" alt="" class="h-8 w-8 rounded-lg object-contain" width="32" height="32" />
          <span class="font-display text-lg font-bold tracking-tight text-white">Carnisoft</span>
        </a>

        <div class="hidden items-center gap-7 text-sm text-zinc-400 md:flex">
          <a href="#ecosistema" class="transition-colors hover:text-white">Ecosistema</a>
          <a href="#sin-internet" class="transition-colors hover:text-white">Sin internet</a>
          <a href="#operacion" class="transition-colors hover:text-white">Operación</a>
          <a href="#producto" class="transition-colors hover:text-white">Producto</a>
        </div>

        <div class="flex items-center gap-2 sm:gap-3">
          <Link
            v-if="canLogin"
            :href="route('login')"
            class="rounded-full px-3 py-2 text-sm font-medium text-zinc-300 transition-colors hover:text-white"
          >
            Ingresar
          </Link>
          <a
            :href="whatsappUrl"
            target="_blank"
            rel="noopener"
            class="rounded-full bg-red-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-red-500 sm:px-5"
          >
            Hablemos
          </a>
        </div>
      </nav>
    </header>

    <main id="contenido">
      <!-- ══ Hero ══════════════════════════════════════════════════════ -->
      <section class="relative overflow-hidden px-5 pb-20 pt-16 sm:px-8 sm:pt-24">
        <div
          class="pointer-events-none absolute left-1/2 top-0 h-[460px] w-full max-w-4xl -translate-x-1/2 opacity-40"
          style="background: radial-gradient(ellipse at 50% 0%, rgba(220,38,38,0.35), transparent 65%)"
          aria-hidden="true"
        />

        <div class="relative mx-auto max-w-6xl">
          <div class="grid items-center gap-14 lg:grid-cols-[1fr_1.05fr] lg:gap-10">
            <div data-reveal>
              <p class="mb-5 inline-flex items-center gap-2 rounded-full border border-red-500/20 bg-red-950/30 px-3 py-1.5 text-xs font-semibold uppercase tracking-wider text-red-300">
                <span class="relative flex h-1.5 w-1.5">
                  <span class="latido absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75" />
                  <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-red-500" />
                </span>
                Para negocios que venden a peso
              </p>

              <h1 class="font-display text-[2.35rem] font-extrabold leading-[1.08] tracking-tight text-white sm:text-5xl lg:text-[3.4rem]">
                Cuando se cae el internet,<br />
                <span class="bg-gradient-to-r from-red-500 to-orange-400 bg-clip-text text-transparent">
                  tu mostrador sigue cobrando
                </span>
              </h1>

              <p class="mt-6 max-w-xl text-base leading-relaxed text-zinc-400 sm:text-lg">
                Carnisoft conecta tu báscula, tu caja y tus sucursales. Con el hub instalado en el
                local, las ventas se siguen registrando y cobrando sin conexión, y suben solas
                cuando la red vuelve.
              </p>

              <div class="mt-9 flex flex-col gap-3 sm:flex-row sm:items-center">
                <a
                  :href="whatsappUrl"
                  target="_blank"
                  rel="noopener"
                  class="inline-flex items-center justify-center gap-2 rounded-full bg-white px-7 py-3.5 font-display text-base font-semibold text-black transition-transform hover:scale-[1.02] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                >
                  Quiero verlo en mi negocio
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7-7 7M21 12H3" />
                  </svg>
                </a>
                <a
                  href="#ecosistema"
                  class="inline-flex items-center justify-center rounded-full border border-white/12 px-7 py-3.5 text-base font-medium text-zinc-200 transition-colors hover:border-white/25 hover:text-white"
                >
                  Ver cómo funciona
                </a>
              </div>
            </div>

            <div class="relative" data-reveal>
              <EcosistemaDiagrama />
            </div>
          </div>
        </div>
      </section>

      <!-- ══ El problema ═══════════════════════════════════════════════ -->
      <section class="border-t border-white/5 px-5 py-20 sm:px-8 sm:py-24">
        <div class="mx-auto max-w-6xl">
          <div class="max-w-2xl" data-reveal>
            <h2 class="font-display text-3xl font-bold tracking-tight text-white sm:text-4xl">
              Tres cosas que pasan en el mostrador
            </h2>
            <p class="mt-4 text-zinc-400">
              No son hipótesis. Son las que nos hicieron construir el sistema como está construido.
            </p>
          </div>

          <div class="mt-12 grid gap-5 md:grid-cols-3">
            <article
              v-for="(p, i) in [
                { t: 'Se va el internet a media venta', d: 'Y con un punto de venta en la nube, el negocio se para. Hay clientes esperando y la caja no responde.' },
                { t: 'Dos cajeros cobran la misma cuenta', d: 'Uno la abre, el otro no sabe, y al cuadrar el turno falta dinero que nadie se llevó.' },
                { t: 'El corte no cuadra y nadie sabe por qué', d: 'Retiros sin anotar, pagos con tarjeta mezclados, fiado cobrado a mano. El descuadre aparece al final del día.' },
              ]"
              :key="p.t"
              data-reveal
              :style="{ transitionDelay: `${i * 90}ms` }"
              class="rounded-2xl border border-white/5 bg-gradient-to-b from-[#141414] to-[#0b0b0b] p-7 transition-colors hover:border-white/12"
            >
              <div class="mb-5 flex h-11 w-11 items-center justify-center rounded-xl bg-red-500/10 text-red-400">
                <span class="font-display text-lg font-bold">{{ i + 1 }}</span>
              </div>
              <h3 class="font-display text-lg font-semibold text-white">{{ p.t }}</h3>
              <p class="mt-2.5 text-sm leading-relaxed text-zinc-400">{{ p.d }}</p>
            </article>
          </div>
        </div>
      </section>

      <!-- ══ Ecosistema ════════════════════════════════════════════════ -->
      <section id="ecosistema" class="scroll-mt-20 border-t border-white/5 px-5 py-20 sm:px-8 sm:py-24">
        <div class="mx-auto max-w-6xl">
          <div class="grid gap-14 lg:grid-cols-[1.05fr_1fr] lg:items-center">
            <div data-reveal>
              <p class="mb-3 text-sm font-semibold uppercase tracking-wider text-red-400">Ecosistema</p>
              <h2 class="font-display text-3xl font-bold tracking-tight text-white sm:text-4xl">
                No es una app. Son cinco, y se encuentran solas.
              </h2>
              <p class="mt-5 leading-relaxed text-zinc-400">
                El hub vive en la sucursal y guarda el catálogo y las ventas. Las básculas y las
                tablets lo encuentran por la red local — y si cambias de router y le cambia la
                dirección, lo vuelven a encontrar solas, sin que nadie configure nada.
              </p>

              <dl class="mt-9 space-y-5">
                <div v-for="e in [
                  { t: 'Hub de sucursal', d: 'Windows o Android. Guarda catálogo y ventas, y atiende a los equipos aunque no haya internet.' },
                  { t: 'Básculas', d: 'El peso entra solo a la venta por puerto serie. Nadie teclea kilos.' },
                  { t: 'Caja y tablets', d: 'Cobran, abren y cierran turno, registran gastos y compras.' },
                  { t: 'Nube', d: 'Consolida todas tus sucursales: métricas, clientes, compras y el panel de equipos.' },
                ]" :key="e.t" class="flex gap-4">
                  <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-red-500" aria-hidden="true" />
                  <div>
                    <dt class="font-display font-semibold text-white">{{ e.t }}</dt>
                    <dd class="mt-1 text-sm leading-relaxed text-zinc-400">{{ e.d }}</dd>
                  </div>
                </div>
              </dl>
            </div>

            <div class="rounded-3xl border border-white/5 bg-gradient-to-b from-[#111] to-[#080808] p-6 sm:p-9" data-reveal>
              <EcosistemaDiagrama />
            </div>
          </div>

          <!-- Cifras: hechos del sistema, no métricas de negocio. -->
          <div class="mt-16 grid gap-5 sm:grid-cols-3">
            <div ref="equiposRef" data-reveal class="rounded-2xl border border-white/5 bg-[#0c0c0c] p-7 text-center">
              <p class="font-display text-4xl font-extrabold text-white">{{ equipos }}</p>
              <p class="mt-1.5 text-sm text-zinc-400">tipos de equipo conectados</p>
            </div>
            <div ref="rolesRef" data-reveal :style="{ transitionDelay: '90ms' }" class="rounded-2xl border border-white/5 bg-[#0c0c0c] p-7 text-center">
              <p class="font-display text-4xl font-extrabold text-white">{{ roles }}</p>
              <p class="mt-1.5 text-sm text-zinc-400">roles con permisos propios</p>
            </div>
            <div ref="ejesRef" data-reveal :style="{ transitionDelay: '180ms' }" class="rounded-2xl border border-white/5 bg-[#0c0c0c] p-7 text-center">
              <p class="font-display text-4xl font-extrabold text-white">{{ ejes }}</p>
              <p class="mt-1.5 text-sm text-zinc-400">ejes de métricas</p>
            </div>
          </div>
        </div>
      </section>

      <!-- ══ Sin internet ══════════════════════════════════════════════ -->
      <section id="sin-internet" class="scroll-mt-20 border-t border-white/5 bg-gradient-to-b from-[#0a0706] to-[#050505] px-5 py-20 sm:px-8 sm:py-24">
        <div class="mx-auto max-w-6xl">
          <div class="grid gap-14 lg:grid-cols-2 lg:items-center">
            <div class="order-2 rounded-3xl border border-amber-500/15 bg-gradient-to-b from-[#12100c] to-[#080808] p-6 sm:p-9 lg:order-1" data-reveal>
              <EcosistemaDiagrama offline />
            </div>

            <div class="order-1 lg:order-2" data-reveal>
              <p class="mb-3 text-sm font-semibold uppercase tracking-wider text-amber-400">Sin internet</p>
              <h2 class="font-display text-3xl font-bold tracking-tight text-white sm:text-4xl">
                Se cae la red y la venta sigue
              </h2>
              <p class="mt-5 leading-relaxed text-zinc-400">
                El hub tiene el catálogo y las ventas en el propio local. Cuando la conexión se va,
                la caja sigue cobrando contra el hub: el dinero se registra, el turno sigue abierto
                y todo sube solo en cuanto la red vuelve.
              </p>

              <ul class="mt-8 space-y-3.5">
                <li v-for="s in [
                  'Las ventas de la báscula llegan al hub por la red local',
                  'Se cobran con efectivo, tarjeta o transferencia',
                  'El turno se abre y se cierra igual',
                  'Nada se pierde: la cola sube sola al reconectar',
                ]" :key="s" class="flex items-start gap-3 text-sm text-zinc-300">
                  <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 011.4-1.4l3.3 3.3 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd" />
                  </svg>
                  <span>{{ s }}</span>
                </li>
              </ul>

              <p class="mt-8 rounded-xl border border-white/8 bg-white/[0.03] p-4 text-sm leading-relaxed text-zinc-400">
                <strong class="font-semibold text-zinc-200">Con el hub instalado en la sucursal.</strong>
                Es el equipo que guarda todo en el local; sin él, los demás dispositivos necesitan
                conexión para vender.
              </p>
            </div>
          </div>
        </div>
      </section>

      <!-- ══ La báscula ════════════════════════════════════════════════ -->
      <section class="border-t border-white/5 px-5 py-20 sm:px-8 sm:py-24">
        <div class="mx-auto max-w-6xl">
          <div class="grid gap-12 lg:grid-cols-2 lg:items-center">
            <div data-reveal>
              <p class="mb-3 text-sm font-semibold uppercase tracking-wider text-red-400">Báscula conectada</p>
              <h2 class="font-display text-3xl font-bold tracking-tight text-white sm:text-4xl">
                El peso entra solo a la venta
              </h2>
              <p class="mt-5 leading-relaxed text-zinc-400">
                La báscula está conectada de verdad, por puerto serie. Pesas el producto y la línea
                aparece en la venta con su importe calculado. Nadie teclea kilos, nadie se equivoca
                al pasar un número de una pantalla a otra.
              </p>
            </div>

            <div ref="basculaRef" class="rounded-3xl border border-white/5 bg-gradient-to-b from-[#121212] to-[#080808] p-7 sm:p-9" data-reveal>
              <div class="rounded-2xl border border-white/8 bg-[#0a0a0a] p-7 text-center">
                <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Báscula</p>
                <p class="mt-3 font-display text-6xl font-extrabold tabular-nums text-white">
                  {{ peso.toFixed(3) }}<span class="ml-2 text-2xl font-semibold text-zinc-500">kg</span>
                </p>
                <p class="mt-1.5 text-sm text-zinc-500">estable</p>
              </div>

              <Transition name="linea">
                <div v-if="lineaVisible" class="mt-5 rounded-2xl border border-red-500/25 bg-red-500/[0.07] p-5">
                  <div class="flex items-center justify-between gap-4">
                    <div class="min-w-0">
                      <p class="truncate font-display font-semibold text-white">Producto al peso</p>
                      <p class="mt-0.5 text-sm text-zinc-400">
                        {{ peso.toFixed(3) }} kg × ${{ PRECIO_KG }}.00 / kg
                      </p>
                    </div>
                    <p class="shrink-0 font-display text-2xl font-bold tabular-nums text-white">
                      ${{ importe }}
                    </p>
                  </div>
                </div>
              </Transition>
            </div>
          </div>
        </div>
      </section>

      <!-- ══ Operación ═════════════════════════════════════════════════ -->
      <section id="operacion" class="scroll-mt-20 border-t border-white/5 px-5 py-20 sm:px-8 sm:py-24">
        <div class="mx-auto max-w-6xl">
          <div class="max-w-2xl" data-reveal>
            <p class="mb-3 text-sm font-semibold uppercase tracking-wider text-red-400">Operación</p>
            <h2 class="font-display text-3xl font-bold tracking-tight text-white sm:text-4xl">
              El día completo, de la apertura al corte
            </h2>
          </div>

          <div class="mt-12 grid gap-5 md:grid-cols-3">
            <!-- Mesa de trabajo, con la interacción real -->
            <article class="rounded-3xl border border-white/5 bg-gradient-to-b from-[#141414] to-[#0a0a0a] p-7 md:col-span-2 md:p-9" data-reveal>
              <div class="grid gap-8 md:grid-cols-2 md:items-center">
                <div>
                  <h3 class="font-display text-xl font-bold text-white">Mesa de trabajo</h3>
                  <p class="mt-3 text-sm leading-relaxed text-zinc-400">
                    Las ventas llegan de la báscula y esperan aquí. Se cobran enteras o en partes,
                    se pausan y se reactivan. Mientras un cajero tiene una venta abierta, queda
                    bloqueada para los demás — dos personas no pueden cobrar lo mismo.
                  </p>
                  <div class="mt-5 flex flex-wrap gap-2">
                    <button
                      v-for="f in [{ k: 'activas', t: 'Activas' }, { k: 'pendientes', t: 'Pendientes' }]"
                      :key="f.k"
                      type="button"
                      :aria-pressed="filtro === f.k"
                      :class="[
                        'rounded-full px-4 py-1.5 text-sm font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-500',
                        filtro === f.k ? 'bg-red-600 text-white' : 'bg-white/5 text-zinc-400 hover:bg-white/10',
                      ]"
                      @click="filtro = f.k"
                    >
                      {{ f.t }} ({{ ventas.filter((v) => v.estado === f.k).length }})
                    </button>
                  </div>
                </div>

                <div class="rounded-2xl border border-white/8 bg-[#0a0a0a] p-3">
                  <TransitionGroup name="venta" tag="div" class="space-y-2">
                    <div
                      v-for="v in ventasFiltradas"
                      :key="v.id"
                      class="rounded-xl border border-white/5 bg-[#151515] p-3.5"
                    >
                      <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                          <p class="font-display text-sm font-bold text-white">{{ v.id }}</p>
                          <p class="mt-0.5 truncate text-xs text-zinc-500">
                            {{ v.cliente ?? 'Sin cliente' }} · {{ v.hora }}
                          </p>
                        </div>
                        <span class="shrink-0 rounded-md bg-white/5 px-2 py-0.5 text-[10px] font-semibold uppercase text-zinc-400">
                          {{ v.origen }}
                        </span>
                      </div>
                      <div class="mt-2.5 flex items-baseline justify-between gap-3">
                        <span class="font-display text-lg font-bold text-white">{{ v.total }}</span>
                        <span class="text-xs font-medium text-amber-400">Pendiente {{ v.pendiente }}</span>
                      </div>
                    </div>
                  </TransitionGroup>
                </div>
              </div>
            </article>

            <article
              v-for="(f, i) in [
                { t: 'Turnos y cortes', d: 'Apertura, retiros y cierre con conciliación por método de pago. El descuadre se ve en el momento, no al día siguiente.' },
                { t: 'Clientes y fiado', d: 'Quién debe y cuánto. Un abono salda las cuentas más viejas primero, sin llevar la cuenta a mano.' },
                { t: 'Gastos y compras', d: 'Lo que entra y lo que sale, con proveedores, cuentas por pagar y adjuntos.' },
                { t: 'Captura con IA', d: 'Fotografías el ticket y los datos se rellenan solos. Tú revisas y confirmas.' },
                { t: 'Métricas', d: 'Ventas, margen, productos, clientes, cajeros, turnos, cobranza y cancelaciones.' },
                { t: 'Varias sucursales', d: 'Cada una ve lo suyo; tú las ves todas. Y decides qué puede hacer cada una.' },
              ]"
              :key="f.t"
              data-reveal
              :style="{ transitionDelay: `${(i % 3) * 80}ms` }"
              class="rounded-3xl border border-white/5 bg-gradient-to-b from-[#141414] to-[#0a0a0a] p-7 transition-colors hover:border-white/12"
            >
              <h3 class="font-display text-lg font-bold text-white">{{ f.t }}</h3>
              <p class="mt-2.5 text-sm leading-relaxed text-zinc-400">{{ f.d }}</p>
            </article>
          </div>
        </div>
      </section>

      <!-- ══ Producto ══════════════════════════════════════════════════ -->
      <section id="producto" class="scroll-mt-20 border-t border-white/5 px-5 py-20 sm:px-8 sm:py-24">
        <div class="mx-auto max-w-6xl">
          <div class="max-w-2xl" data-reveal>
            <p class="mb-3 text-sm font-semibold uppercase tracking-wider text-red-400">Producto</p>
            <h2 class="font-display text-3xl font-bold tracking-tight text-white sm:text-4xl">
              Así se ve por dentro
            </h2>
          </div>

          <div class="mt-12 space-y-8">
            <figure
              v-for="(c, i) in capturas"
              :key="c.src"
              data-reveal
              class="overflow-hidden rounded-3xl border border-white/8 bg-[#0c0c0c]"
            >
              <img
                :src="c.src"
                :alt="c.alt"
                width="1568"
                height="512"
                :loading="i === 0 ? 'eager' : 'lazy'"
                decoding="async"
                class="w-full"
              />
              <figcaption class="border-t border-white/5 px-6 py-5">
                <p class="font-display font-semibold text-white">{{ c.titulo }}</p>
                <p class="mt-1 text-sm text-zinc-400">{{ c.pie }}</p>
              </figcaption>
            </figure>
          </div>
        </div>
      </section>

      <!-- ══ Cierre ════════════════════════════════════════════════════ -->
      <section class="relative overflow-hidden border-t border-white/5 px-5 py-24 sm:px-8 sm:py-28">
        <div
          class="pointer-events-none absolute left-1/2 top-1/2 h-[380px] w-full max-w-3xl -translate-x-1/2 -translate-y-1/2 opacity-30"
          style="background: radial-gradient(ellipse at center, rgba(220,38,38,0.35), transparent 65%)"
          aria-hidden="true"
        />
        <div class="relative mx-auto max-w-2xl text-center" data-reveal>
          <h2 class="font-display text-3xl font-bold tracking-tight text-white sm:text-[2.6rem] sm:leading-tight">
            ¿Lo vemos en tu negocio?
          </h2>
          <p class="mt-5 text-zinc-400">
            Cuéntanos cómo trabajas hoy y te decimos si Carnisoft encaja. Sin compromiso.
          </p>
          <a
            :href="whatsappUrl"
            target="_blank"
            rel="noopener"
            class="mt-9 inline-flex items-center justify-center gap-2.5 rounded-full bg-red-600 px-8 py-4 font-display text-base font-semibold text-white transition-transform hover:scale-[1.02] hover:bg-red-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-500"
          >
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
              <path d="M17.47 14.38c-.3-.15-1.75-.86-2.02-.96-.27-.1-.47-.15-.67.15-.2.3-.77.96-.94 1.16-.17.2-.35.22-.65.07-.3-.15-1.25-.46-2.38-1.47-.88-.78-1.48-1.75-1.65-2.05-.17-.3-.02-.46.13-.6.13-.14.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.6-.92-2.2-.24-.58-.49-.5-.67-.5h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.22 3.08c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.7.63.71.22 1.36.19 1.87.12.57-.09 1.75-.72 2-1.41.25-.7.25-1.29.17-1.42-.07-.13-.27-.2-.57-.35z" />
              <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.27-1.38a9.86 9.86 0 004.77 1.21h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0012.04 2zm0 18.13h-.01a8.2 8.2 0 01-4.18-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.18 8.18 0 01-1.26-4.36c0-4.53 3.7-8.22 8.25-8.22 2.2 0 4.27.86 5.83 2.42a8.18 8.18 0 012.41 5.81c0 4.54-3.69 8.21-8.25 8.21z" />
            </svg>
            Escríbenos por WhatsApp
          </a>
        </div>
      </section>
    </main>

    <!-- ══ Pie ═════════════════════════════════════════════════════════ -->
    <footer class="border-t border-white/5 bg-[#030303] px-5 py-10 sm:px-8">
      <div class="mx-auto flex max-w-6xl flex-col gap-5 text-sm text-zinc-500 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-2.5">
          <img src="/logo.png" alt="" class="h-6 w-6 rounded object-contain opacity-60" width="24" height="24" />
          <span>© {{ new Date().getFullYear() }} Carnisoft</span>
        </div>
        <a :href="whatsappUrl" target="_blank" rel="noopener" class="transition-colors hover:text-zinc-300">
          Contacto por WhatsApp
        </a>
      </div>
    </footer>
  </div>
</template>

<style scoped>
/* Outfit lo carga `app.blade.php` solo en esta página: no tiene sentido
   pagarla en cada pantalla de la aplicación. Figtree, que ya viene cargada
   para toda la app, hace de cuerpo de texto. */
.font-display {
  font-family: 'Outfit', ui-sans-serif, system-ui, sans-serif;
}

.salto-al-contenido {
  position: absolute;
  left: -9999px;
  z-index: 100;
  padding: 0.75rem 1.25rem;
  border-radius: 0 0 0.75rem 0;
  background: #fff;
  color: #000;
  font-weight: 600;
}
.salto-al-contenido:focus {
  left: 0;
  top: 0;
}

/* Entradas al hacer scroll. El estado inicial vive aquí y `useReveal` solo
   añade la clase: si el JavaScript no llega a ejecutarse, la media query de
   abajo y este `opacity` dejarían la página invisible — por eso el fallback
   sin JS es visible (ver `@media (scripting: none)`). */
[data-reveal] {
  opacity: 0;
  transform: translateY(22px);
  transition: opacity 0.7s cubic-bezier(0.16, 1, 0.3, 1), transform 0.7s cubic-bezier(0.16, 1, 0.3, 1);
  will-change: opacity, transform;
}
[data-reveal].is-visible {
  opacity: 1;
  transform: none;
}

.latido {
  animation: latir 2s cubic-bezier(0, 0, 0.2, 1) infinite;
}
@keyframes latir {
  75%, 100% { transform: scale(2); opacity: 0; }
}

/* La línea de venta que aparece tras pesar. */
.linea-enter-active { transition: opacity 0.45s ease, transform 0.45s cubic-bezier(0.16, 1, 0.3, 1); }
.linea-enter-from { opacity: 0; transform: translateY(12px); }

/* Tarjetas de venta al cambiar de filtro. */
.venta-enter-active, .venta-leave-active { transition: all 0.3s ease; }
.venta-enter-from, .venta-leave-to { opacity: 0; transform: translateX(-14px); }
.venta-leave-active { position: absolute; }

/* ── Menos movimiento ──────────────────────────────────────────────────
   Todo nace visible y nada se mueve. La página cuenta lo mismo: ninguna
   animación de aquí aporta información que no esté también en el texto. */
@media (prefers-reduced-motion: reduce) {
  [data-reveal] {
    opacity: 1;
    transform: none;
    transition: none;
  }
  .latido { animation: none; }
  .linea-enter-active, .venta-enter-active, .venta-leave-active { transition: none; }
  .venta-leave-active { position: static; }
}

/* Sin JavaScript no hay quien añada `is-visible`: la página tiene que
   leerse igual. */
@media (scripting: none) {
  [data-reveal] { opacity: 1; transform: none; }
}
</style>
