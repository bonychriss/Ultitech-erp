/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        /* Roadmaster brand: deep red primary, dark blue secondary */
        primary: {
          50: '#fef2f2',
          100: '#fee2e2',
          200: '#fecaca',
          300: '#fca5a5',
          400: '#f87171',
          500: '#dc2626',
          600: '#b91c1c',
          700: '#a82229',
          800: '#991b1b',
          900: '#7f1d1d',
        },
        brand: {
          red: '#a82229',
          navy: '#2c3640',
          cream: '#f2eee8',
        },
        accent: {
          DEFAULT: '#a82229',
          light: '#dc2626',
          dark: '#991b1b',
          hover: '#7f1d1d',
        },
        surface: {
          DEFAULT: '#ffffff',
          subtle: '#f8fafc',
          muted: '#f1f5f9',
        },
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'sans-serif'],
      },
      fontSize: {
        'page-title': ['1.5rem', { lineHeight: '1.75rem', fontWeight: '600' }],
        'section-title': ['0.8125rem', { lineHeight: '1.25rem', fontWeight: '600' }],
        'label': ['0.875rem', { lineHeight: '1.25rem', fontWeight: '500' }],
        'hint': ['0.75rem', { lineHeight: '1rem' }],
      },
      spacing: {
        'page-x': '1.5rem',
        'section': '1.5rem',
        'card': '1.25rem',
      },
      boxShadow: {
        'card': '0 1px 3px 0 rgb(0 0 0 / 0.05), 0 1px 2px -1px rgb(0 0 0 / 0.05)',
        'card-hover': '0 4px 6px -1px rgb(0 0 0 / 0.05), 0 2px 4px -2px rgb(0 0 0 / 0.05)',
      },
    },
  },
  plugins: [],
};
