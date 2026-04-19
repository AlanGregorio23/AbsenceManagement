import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/*.blade.php',
        './resources/views/mail/*.blade.php',
        './resources/js/**/*.jsx',
    ],
    safelist: [
        'bg-amber-100',
        'bg-emerald-100',
        'bg-green-100',
        'bg-rose-100',
        'bg-sky-100',
        'bg-yellow-100',
        'text-amber-700',
        'text-emerald-700',
        'text-green-700',
        'text-rose-700',
        'text-sky-700',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms],
};
