import { onMounted, onUnmounted } from 'vue';

/**
 * Elementos que aparecen al entrar en pantalla.
 *
 * Es `IntersectionObserver` a pelo, que es exactamente lo que hacen AOS y
 * compañía: 20 líneas contra 15 KB de librería y una dependencia más que
 * mantener. Se marca el elemento con `data-reveal` y el observador le pone
 * `is-visible` la primera vez que se asoma.
 *
 * **Se desconecta tras revelar.** Una sección no tiene que volver a animarse
 * cada vez que el cursor pasa por encima: la primera vez explica, la segunda
 * molesta.
 *
 * Con `prefers-reduced-motion: reduce` no se observa nada y todo nace visible
 * (el CSS de `[data-reveal]` ya lo deja así bajo esa media query). Quien pidió
 * menos movimiento tiene que ver la página completa, no una a medias.
 */
export function useReveal(rootSelector = null) {
  let observer = null;

  onMounted(() => {
    const prefiereQuieto = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches;
    if (prefiereQuieto) return;

    const raiz = rootSelector ? document.querySelector(rootSelector) : document;
    const objetivos = raiz?.querySelectorAll('[data-reveal]') ?? [];
    if (objetivos.length === 0) return;

    observer = new IntersectionObserver(
      (entradas) => {
        for (const entrada of entradas) {
          if (!entrada.isIntersecting) continue;
          entrada.target.classList.add('is-visible');
          observer.unobserve(entrada.target);
        }
      },
      // 12 % basta para que la animación arranque cuando el elemento ya se
      // intuye, no cuando ya se leyó. El margen inferior negativo evita que
      // algo pegado al borde se revele sin que nadie lo haya visto.
      { threshold: 0.12, rootMargin: '0px 0px -8% 0px' },
    );

    for (const objetivo of objetivos) observer.observe(objetivo);
  });

  onUnmounted(() => observer?.disconnect());
}

/**
 * Cuenta de 0 al valor final cuando el elemento entra en pantalla.
 *
 * Existe porque un número que aparece contando se mira y uno estático se
 * salta. `requestAnimationFrame` y una curva de salida: sin librería, sin
 * `setInterval` (que se desincroniza del repintado y se ve a tirones).
 *
 * @param {import('vue').Ref<HTMLElement|null>} elementRef
 * @param {import('vue').Ref<number>} valorRef  se escribe aquí el valor en curso
 * @param {number} destino
 * @param {number} duracionMs
 */
export function useCountUp(elementRef, valorRef, destino, duracionMs = 1400) {
  let observer = null;
  let raf = null;

  onMounted(() => {
    const prefiereQuieto = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches;
    if (prefiereQuieto || !elementRef.value) {
      valorRef.value = destino;
      return;
    }

    observer = new IntersectionObserver(
      (entradas) => {
        if (!entradas[0]?.isIntersecting) return;
        observer.disconnect();

        const inicio = performance.now();
        const paso = (ahora) => {
          const t = Math.min(1, (ahora - inicio) / duracionMs);
          // easeOutExpo: arranca rápido y frena al final, que es como se lee
          // un número que "llega" en vez de uno que se arrastra.
          const suave = t === 1 ? 1 : 1 - Math.pow(2, -10 * t);
          valorRef.value = Math.round(destino * suave);
          if (t < 1) raf = requestAnimationFrame(paso);
        };
        raf = requestAnimationFrame(paso);
      },
      { threshold: 0.4 },
    );

    observer.observe(elementRef.value);
  });

  onUnmounted(() => {
    observer?.disconnect();
    if (raf) cancelAnimationFrame(raf);
  });
}
