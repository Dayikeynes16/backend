import { defineConfig } from 'vitest/config';
import { fileURLToPath, URL } from 'node:url';

/**
 * Pruebas de los módulos JS que no dependen de Vue ni del navegador: hoy, la
 * lógica de tiempo real (estado del socket y registro del canal compartido).
 * Es lógica que sólo se ejerce cuando la red falla, así que probarla a mano no
 * es realista. Mismo planteamiento que en carniceria-hub.
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
