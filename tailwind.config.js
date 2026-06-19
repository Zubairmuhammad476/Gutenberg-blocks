/**
 * tailwind.config.js — MFD Dashboard Widget
 *
 * Tailwind CSS v3 configuration.
 *
 * Key design decisions:
 *
 * 1. prefix: 'mfd-'
 *    ALL Tailwind utilities are prefixed with 'mfd-' (e.g. mfd-flex, mfd-text-sm).
 *    This is non-negotiable for WordPress environments — it eliminates any risk
 *    of Tailwind utilities bleeding into theme or other plugin styles.
 *
 * 2. content: scoped to src/js/** only.
 *    Tailwind's JIT purge engine only scans frontend source files.
 *    PHP render templates are also scanned for any inline class usage.
 *
 * 3. important: '#mfd-dashboard-root'
 *    All generated utilities carry specificity-boosting scoping by being
 *    wrapped under the root block container. This ensures dashboard styles
 *    win specificity battles against theme base styles without !important abuse.
 *
 * 4. Custom design tokens extend the default Tailwind scale — they do NOT
 *    replace it. Extending keeps full Tailwind utility availability while
 *    adding project-specific brand tokens.
 */

/** @type {import('tailwindcss').Config} */
module.exports = {
    // -----------------------------------------------------------------------
    // Utility class prefix — 'mfd-flex', 'mfd-text-sm', 'mfd-bg-white', etc.
    // -----------------------------------------------------------------------
    prefix: 'mfd-',

    // -----------------------------------------------------------------------
    // Content scan paths — JIT only generates classes found in these files.
    // -----------------------------------------------------------------------
    content: [
        './src/js/**/*.{js,jsx}',
        './src/css/**/*.css',
        './templates/**/*.php',
    ],

    // -----------------------------------------------------------------------
    // Specificity scoping — all utilities scoped under the block wrapper.
    // -----------------------------------------------------------------------
    important: '.mfd-dashboard-block',

    // -----------------------------------------------------------------------
    // Dark mode — class-based, toggled by adding .dark to the block wrapper.
    // -----------------------------------------------------------------------
    darkMode: 'class',

    theme: {
        extend: {
            // Brand color palette — HSL-based for precise control.
            colors: {
                'mfd-indigo': {
                    50:  'hsl(238, 100%, 97%)',
                    100: 'hsl(238, 100%, 94%)',
                    200: 'hsl(238, 96%, 87%)',
                    300: 'hsl(238, 94%, 78%)',
                    400: 'hsl(238, 92%, 69%)',
                    500: 'hsl(239, 84%, 67%)', // Primary brand — #6366f1
                    600: 'hsl(239, 76%, 57%)',
                    700: 'hsl(239, 68%, 48%)',
                    800: 'hsl(239, 62%, 40%)',
                    900: 'hsl(239, 57%, 33%)',
                },
                'mfd-slate': {
                    50:  'hsl(210, 40%, 98%)',
                    100: 'hsl(210, 40%, 96%)',
                    200: 'hsl(214, 32%, 91%)',
                    300: 'hsl(213, 27%, 84%)',
                    400: 'hsl(215, 20%, 65%)',
                    500: 'hsl(215, 16%, 47%)',
                    600: 'hsl(215, 19%, 35%)',
                    700: 'hsl(215, 25%, 27%)',
                    800: 'hsl(217, 33%, 17%)',
                    900: 'hsl(222, 47%, 11%)',
                },
                'mfd-success': 'hsl(142, 71%, 45%)',
                'mfd-warning': 'hsl(38, 92%, 50%)',
                'mfd-error':   'hsl(0, 84%, 60%)',
            },

            // Typography — Poppins loaded via editor.css @import.
            fontFamily: {
                'mfd-sans': ['Poppins', 'Inter', 'system-ui', 'sans-serif'],
                'mfd-mono': ['JetBrains Mono', 'Fira Code', 'monospace'],
            },

            // Font size scale tuned for dashboard information density.
            fontSize: {
                'mfd-2xs': ['0.625rem', { lineHeight: '1rem' }],
                'mfd-xs':  ['0.75rem',  { lineHeight: '1rem' }],
                'mfd-sm':  ['0.875rem', { lineHeight: '1.25rem' }],
                'mfd-md':  ['1rem',     { lineHeight: '1.5rem' }],
                'mfd-lg':  ['1.125rem', { lineHeight: '1.75rem' }],
                'mfd-xl':  ['1.25rem',  { lineHeight: '1.75rem' }],
                'mfd-2xl': ['1.5rem',   { lineHeight: '2rem' }],
            },

            // Spacing extensions for dashboard card gaps.
            spacing: {
                'mfd-18': '4.5rem',
                'mfd-22': '5.5rem',
                'mfd-76': '19rem',
                'mfd-88': '22rem',
                'mfd-96': '24rem',
            },

            // Border radii for card components.
            borderRadius: {
                'mfd-card': '0.875rem',
                'mfd-pill': '9999px',
            },

            // Box shadows — elevation system for cards.
            boxShadow: {
                'mfd-card':   '0 1px 3px 0 rgba(0,0,0,0.06), 0 1px 2px -1px rgba(0,0,0,0.04)',
                'mfd-card-md':'0 4px 6px -1px rgba(0,0,0,0.07), 0 2px 4px -2px rgba(0,0,0,0.05)',
                'mfd-card-lg':'0 10px 15px -3px rgba(0,0,0,0.08), 0 4px 6px -4px rgba(0,0,0,0.05)',
                'mfd-inset':  'inset 0 2px 4px 0 rgba(0,0,0,0.06)',
                'mfd-ring':   '0 0 0 3px rgba(99, 102, 241, 0.35)',
            },

            // Animation keyframes for skeleton shimmer and meter transitions.
            keyframes: {
                'mfd-shimmer': {
                    '0%':   { backgroundPosition: '-200% 0' },
                    '100%': { backgroundPosition: '200% 0' },
                },
                'mfd-pulse-soft': {
                    '0%, 100%': { opacity: '1' },
                    '50%':      { opacity: '0.6' },
                },
                'mfd-slide-up': {
                    '0%':   { transform: 'translateY(8px)', opacity: '0' },
                    '100%': { transform: 'translateY(0)',   opacity: '1' },
                },
                'mfd-stroke-fill': {
                    '0%':   { strokeDashoffset: '251' },
                    '100%': { strokeDashoffset: 'var(--mfd-stroke-offset, 0)' },
                },
            },
            animation: {
                'mfd-shimmer':     'mfd-shimmer 1.6s ease-in-out infinite',
                'mfd-pulse-soft':  'mfd-pulse-soft 2s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                'mfd-slide-up':    'mfd-slide-up 0.3s ease-out forwards',
                'mfd-stroke-fill': 'mfd-stroke-fill 1.2s ease-out forwards',
            },

            // Transition durations for consistent motion design.
            transitionDuration: {
                'mfd-fast':   '150ms',
                'mfd-normal': '250ms',
                'mfd-slow':   '400ms',
            },

            // Backdrop blur for glassmorphism card effects.
            backdropBlur: {
                'mfd-xs': '2px',
                'mfd-sm': '4px',
                'mfd-md': '8px',
            },
        },
    },

    plugins: [],
};
