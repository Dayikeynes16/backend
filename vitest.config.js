import { defineConfig } from 'vitest/config';
import { fileURLToPath, URL } from 'node:url';

/**
 * Pruebas de los módulos JS que no dependen de Vue ni del navegador: la
 * lógica de tiempo real (estado del socket y registro del canal compartido) y
 * la de avisos de equipos. Es lógica que sólo se ejerce cuando la red falla o
 * la pestaña está en segundo plano, así que probarla a mano no es realista.
 * Mismo planteamiento que en carniceria-hub.
 *
 * `useDeviceAlerts.js` sí depende de Vue (onMounted/onUnmounted) y de
 * `document`: su prueba (`tests/js/useDeviceAlerts.test.js`) declara
 * `// @vitest-environment happy-dom` para pedir DOM sólo en ese archivo, sin
 * mover el resto de las pruebas de `node`.
 */
export default defineConfig({
    test: {
        environment: 'node',
        include: ['tests/js/**/*.test.js'],
    },
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
});
