/** @type {import('tailwindcss').Config} */
export default {
    content: [
        "./index.html",
        "./src/**/*.{js,ts,jsx,tsx}",
    ],
    theme: {
        extend: {
            colors: {
                // We'll use CSS variables for theme-specific colors
                sidebar: {
                    bg: "var(--sidebar-bg)",
                    text: "var(--sidebar-text)",
                    active: "var(--sidebar-active)",
                    hover: "var(--sidebar-hover)",
                    border: "var(--sidebar-border)",
                }
            },
            animation: {
                'neon-pulse': 'neon-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                'flicker': 'flicker 0.1s infinite',
                'rgb-cycle': 'rgb-cycle 10s linear infinite',
            },
            keyframes: {
                'neon-pulse': {
                    '0%, 100%': { opacity: 1, filter: 'brightness(1.5) drop-shadow(0 0 10px currentColor)' },
                    '50%': { opacity: 0.7, filter: 'brightness(1) drop-shadow(0 0 2px currentColor)' },
                },
                'flicker': {
                    '0%, 19.999%, 22%, 62.999%, 64%, 64.999%, 70%, 100%': { opacity: 1 },
                    '20%, 21.999%, 63%, 63.999%, 65%, 69.999%': { opacity: 0.4 },
                },
                'rgb-cycle': {
                    '0%': { filter: 'hue-rotate(0deg)' },
                    '100%': { filter: 'hue-rotate(360deg)' },
                }
            }
        },
    },
    plugins: [],
}
