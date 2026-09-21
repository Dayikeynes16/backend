<script setup>
/**
 * El ecosistema, dibujado.
 *
 * Es la pieza que justifica la landing entera: lo que vendemos no es una
 * pantalla, son cinco aplicaciones que se encuentran solas y siguen
 * funcionando cuando la red falla. Eso no se explica con un párrafo.
 *
 * ## Por qué SVG y no WebGL
 *
 * Son seis nodos y cuatro líneas. Three.js costaría ~150 KB, una pasada de
 * compilación de shaders y batería en una tablet, para dibujar algo que el
 * navegador ya sabe rasterizar. Este SVG pesa lo que pesa el marcado.
 *
 * ## Por qué CSS y no SMIL
 *
 * `<animateMotion>` es más cómodo para mover algo por una curva, pero SMIL
 * **no obedece a `prefers-reduced-motion`**: no hay forma de pararlo desde
 * una media query. Las líneas de aquí son rectas, así que un `translate` en
 * keyframes hace lo mismo y sí se puede detener. Quien pidió menos movimiento
 * ve el diagrama completo y quieto.
 */

import { useId } from 'vue';

defineProps({
  /**
   * Corta el enlace con la nube: se apaga esa línea y el hub queda
   * iluminado por su cuenta. Es la sección de «sigue cobrando sin internet»
   * contada sin texto.
   */
  offline: { type: Boolean, default: false },
});

/**
 * El diagrama sale dos veces en la misma página (conectado y sin internet).
 * Con ids fijos, el segundo `aria-labelledby` apuntaría al `<title>` del
 * primero y los dos `<rect>` compartirían un degradado ya definido — un id
 * repetido en un documento no es un detalle de estilo, es el lector de
 * pantalla leyendo el rótulo equivocado.
 */
const uid = useId();
const idTitulo = `eco-titulo-${uid}`;
const idDesc = `eco-desc-${uid}`;
const idCaja = `eco-caja-${uid}`;
const idBrillo = `eco-brillo-${uid}`;
const relleno = `url(#${idCaja})`;
const brillo = `url(#${idBrillo})`;
</script>

<template>
  <svg
    viewBox="0 0 820 540"
    class="ecosistema w-full h-auto"
    :class="{ 'is-offline': offline }"
    role="img"
    :aria-labelledby="`${idTitulo} ${idDesc}`"
  >
    <title :id="idTitulo">Cómo se conectan los equipos de Carnisoft</title>
    <desc :id="idDesc">
      La nube se comunica con el hub de la sucursal, y el hub con la báscula, la caja y la
      tablet. Cuando no hay internet, el hub sigue atendiendo a los tres equipos por la red
      local.
    </desc>

    <defs>
      <linearGradient :id="idCaja" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stop-color="#1a1a1a" />
        <stop offset="100%" stop-color="#0c0c0c" />
      </linearGradient>
      <filter :id="idBrillo" x="-60%" y="-60%" width="220%" height="220%">
        <feGaussianBlur stdDeviation="5" result="b" />
        <feMerge><feMergeNode in="b" /><feMergeNode in="SourceGraphic" /></feMerge>
      </filter>
    </defs>

    <!-- ── Enlaces ───────────────────────────────────────────────────── -->
    <g aria-hidden="true">
      <!-- Nube ↔ hub. Es el único que se apaga sin internet. -->
      <line class="enlace enlace-nube" x1="410" y1="86" x2="410" y2="236" />
      <line class="enlace" x1="370" y1="304" x2="162" y2="458" />
      <line class="enlace" x1="410" y1="304" x2="410" y2="458" />
      <line class="enlace" x1="450" y1="304" x2="658" y2="458" />

      <!-- Pulsos: lo que hace que el dibujo se lea como tráfico y no como
           un organigrama. Cada uno recorre su línea con su propio retraso. -->
      <circle class="pulso pulso-nube" :filter="brillo" cx="410" cy="86" r="4" />
      <circle class="pulso pulso-bascula" :filter="brillo" cx="370" cy="304" r="4" />
      <circle class="pulso pulso-caja" :filter="brillo" cx="410" cy="304" r="4" />
      <circle class="pulso pulso-tablet" :filter="brillo" cx="450" cy="304" r="4" />
    </g>

    <!-- ── Nube ──────────────────────────────────────────────────────── -->
    <g class="nodo nodo-nube" :fill="relleno">
      <rect x="335" y="24" width="150" height="62" rx="16" />
      <text x="410" y="50" class="etiqueta">Nube</text>
      <text x="410" y="70" class="detalle">Carnisoft</text>
    </g>

    <!-- ── Hub ───────────────────────────────────────────────────────── -->
    <g class="nodo nodo-hub" :fill="relleno">
      <rect x="315" y="238" width="190" height="66" rx="18" />
      <text x="410" y="266" class="etiqueta etiqueta-hub">Hub de sucursal</text>
      <text x="410" y="287" class="detalle">Windows · Android</text>
    </g>

    <!-- ── Equipos ───────────────────────────────────────────────────── -->
    <g class="nodo" :fill="relleno">
      <rect x="40" y="458" width="172" height="60" rx="16" />
      <text x="126" y="484" class="etiqueta">Báscula</text>
      <text x="126" y="504" class="detalle">pesa y cobra</text>
    </g>
    <g class="nodo" :fill="relleno">
      <rect x="324" y="458" width="172" height="60" rx="16" />
      <text x="410" y="484" class="etiqueta">Caja</text>
      <text x="410" y="504" class="detalle">cobros y turno</text>
    </g>
    <g class="nodo" :fill="relleno">
      <rect x="608" y="458" width="172" height="60" rx="16" />
      <text x="694" y="484" class="etiqueta">Tablet</text>
      <text x="694" y="504" class="detalle">mostrador</text>
    </g>

    <!-- Aparece solo en el modo sin internet. -->
    <g class="aviso-offline" aria-hidden="true">
      <rect x="512" y="38" width="176" height="34" rx="17" />
      <text x="600" y="60" class="aviso-texto">Sin internet</text>
    </g>
  </svg>
</template>

<style scoped>
.enlace {
  stroke: rgb(255 255 255 / 0.12);
  stroke-width: 1.5;
  transition: stroke 0.6s ease;
}

.pulso {
  fill: #ef4444;
}

/* Cada pulso recorre exactamente su recta. Los retrasos están escalonados
   para que el conjunto respire en vez de latir a la vez, que se lee como un
   parpadeo. */
.pulso-nube { animation: recorrer-nube 3.2s ease-in-out infinite; }
.pulso-bascula { animation: recorrer-bascula 3.6s ease-in-out 0.4s infinite; }
.pulso-caja { animation: recorrer-caja 3.6s ease-in-out 0.9s infinite; }
.pulso-tablet { animation: recorrer-tablet 3.6s ease-in-out 1.4s infinite; }

@keyframes recorrer-nube {
  0%   { transform: translateY(0); opacity: 0; }
  12%  { opacity: 1; }
  88%  { opacity: 1; }
  100% { transform: translateY(150px); opacity: 0; }
}
@keyframes recorrer-bascula {
  0%   { transform: translate(0, 0); opacity: 0; }
  12%  { opacity: 1; }
  88%  { opacity: 1; }
  100% { transform: translate(-208px, 154px); opacity: 0; }
}
@keyframes recorrer-caja {
  0%   { transform: translateY(0); opacity: 0; }
  12%  { opacity: 1; }
  88%  { opacity: 1; }
  100% { transform: translateY(154px); opacity: 0; }
}
@keyframes recorrer-tablet {
  0%   { transform: translate(0, 0); opacity: 0; }
  12%  { opacity: 1; }
  88%  { opacity: 1; }
  100% { transform: translate(208px, 154px); opacity: 0; }
}

.nodo rect {
  stroke: rgb(255 255 255 / 0.08);
  stroke-width: 1;
  transition: stroke 0.6s ease, opacity 0.6s ease;
}

.nodo-hub rect {
  stroke: rgb(239 68 68 / 0.45);
}

.etiqueta {
  fill: #f4f4f5;
  font-size: 17px;
  font-weight: 600;
  text-anchor: middle;
}
.etiqueta-hub { fill: #fca5a5; }

.detalle {
  fill: rgb(255 255 255 / 0.4);
  font-size: 12.5px;
  text-anchor: middle;
}

/* ── Sin internet ───────────────────────────────────────────────────── */

.aviso-offline { opacity: 0; transition: opacity 0.5s ease; }
.aviso-offline rect {
  fill: rgb(245 158 11 / 0.12);
  stroke: rgb(245 158 11 / 0.4);
}
.aviso-texto {
  fill: #fbbf24;
  font-size: 13px;
  font-weight: 600;
  text-anchor: middle;
}

/* El enlace con la nube se apaga y su pulso desaparece; el hub se queda
   iluminado y los tres equipos siguen conectados a él. Toda la promesa del
   producto, sin una palabra. */
.is-offline .enlace-nube {
  stroke: rgb(255 255 255 / 0.04);
  stroke-dasharray: 5 7;
}
.is-offline .pulso-nube { animation: none; opacity: 0; }
.is-offline .nodo-nube rect { opacity: 0.35; }
.is-offline .nodo-nube .etiqueta,
.is-offline .nodo-nube .detalle { opacity: 0.35; }
.is-offline .nodo-hub rect { stroke: rgb(239 68 68 / 0.85); }
.is-offline .aviso-offline { opacity: 1; }

/* ── Menos movimiento ──────────────────────────────────────────────────
   Los pulsos se detienen donde nacen, que es sobre su línea: el diagrama
   sigue siendo legible y no queda ningún punto suelto en mitad de la nada. */
@media (prefers-reduced-motion: reduce) {
  .pulso { animation: none !important; opacity: 0.9; }
  .nodo rect, .enlace, .aviso-offline { transition: none; }
}
</style>
