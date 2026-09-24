import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
        // Clases que viven en JS (p. ej. LEVEL_STYLE de la isla de avisos).
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            // Tokens de movimiento de la isla de avisos, los mismos del hub
            // (`carniceria-hub/tailwind.config.js`). Sin tocar `DEFAULT`: el
            // resto de la web conserva sus transiciones de siempre.
            transitionDuration: {
                fast: '120ms',
                normal: '180ms',
                slow: '240ms',
            },
            transitionTimingFunction: {
                enter: 'cubic-bezier(0.25, 1, 0.5, 1)',
                exit: 'cubic-bezier(0.5, 0, 0.75, 0)',
                state: 'cubic-bezier(0.4, 0, 0.2, 1)',
            },
        },
    },

    plugins: [forms],
};
