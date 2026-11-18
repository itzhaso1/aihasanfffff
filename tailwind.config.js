import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Cairo', 'Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                hasim: {
                    50: '#F0FDFA',
                    100: '#E8F8F4',
                    200: '#D1F4EC',
                    300: '#A3E8D8',
                    400: '#48D3B9',
                    500: '#06C2A4',
                    600: '#049E86',
                    700: '#067E6B',
                    800: '#076456',
                    900: '#085247',
                    950: '#02312B',
                },
            },
            boxShadow: {
                'card': '0 1px 3px 0 rgba(15, 23, 42, 0.05), 0 1px 2px -1px rgba(15, 23, 42, 0.05)',
                'card-hover': '0 10px 25px -5px rgba(15, 23, 42, 0.06), 0 8px 10px -6px rgba(15, 23, 42, 0.04)',
                'dropdown': '0 10px 30px -4px rgba(15, 23, 42, 0.1), 0 4px 6px -4px rgba(15, 23, 42, 0.05)',
                'modal': '0 25px 50px -12px rgba(15, 23, 42, 0.18)',
            },
            borderRadius: {
                '2xl': '1rem',
                '3xl': '1.5rem',
            },
        },
    },

    plugins: [forms],
};
