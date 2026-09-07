/** @type {import('tailwindcss').Config} */
export default {
  darkMode: ["class"],
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      borderRadius: {
        lg: "var(--radius)",
        md: "calc(var(--radius) - 2px)",
        sm: "calc(var(--radius) - 4px)",
      },
      colors: {
        border: "hsl(var(--border))",
        input: "hsl(var(--input))",
        ring: "hsl(var(--ring))",
        background: "hsl(var(--background))",
        foreground: "hsl(var(--foreground))",
        primary: {
          DEFAULT: "hsl(var(--primary))",
          foreground: "hsl(var(--primary-foreground))",
        },
        secondary: {
          DEFAULT: "hsl(var(--secondary))",
          foreground: "hsl(var(--secondary-foreground))",
        },
        destructive: {
          DEFAULT: "hsl(var(--destructive))",
          foreground: "hsl(var(--destructive-foreground))",
        },
        muted: {
          DEFAULT: "hsl(var(--muted))",
          foreground: "hsl(var(--muted-foreground))",
        },
        accent: {
          DEFAULT: "hsl(var(--accent))",
          foreground: "hsl(var(--accent-foreground))",
        },
        popover: {
          DEFAULT: "hsl(var(--popover))",
          foreground: "hsl(var(--popover-foreground))",
        },
        card: {
          DEFAULT: "hsl(var(--card))",
          foreground: "hsl(var(--card-foreground))",
        },
        sidebar: {
          bg: "var(--sidebar-bg)",
          text: "var(--sidebar-text)",
          active: "var(--sidebar-active)",
          hover: "var(--sidebar-hover)",
          DEFAULT: "hsl(var(--sidebar-background))",
          foreground: "hsl(var(--sidebar-foreground))",
          primary: "hsl(var(--sidebar-primary))",
          "primary-foreground": "hsl(var(--sidebar-primary-foreground))",
          accent: "hsl(var(--sidebar-accent))",
          "accent-foreground": "hsl(var(--sidebar-accent-foreground))",
          border: "hsl(var(--sidebar-border))",
          ring: "hsl(var(--sidebar-ring))",
        },
      },
      animation: {
        "neon-pulse": "neon-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite",
        flicker: "flicker 0.1s infinite",
        "rgb-cycle": "rgb-cycle 10s linear infinite",
      },
      keyframes: {
        "neon-pulse": {
          "0%, 100%": {
            opacity: "1",
            filter: "brightness(1.5) drop-shadow(0 0 10px currentColor)",
          },
          "50%": {
            opacity: "0.7",
            filter: "brightness(1) drop-shadow(0 0 2px currentColor)",
          },
        },
        flicker: {
          "0%, 19.999%, 22%, 62.999%, 64%, 64.999%, 70%, 100%": {
            opacity: "1",
          },
          "20%, 21.999%, 63%, 63.999%, 65%, 69.999%": {
            opacity: "0.4",
          },
        },
        "rgb-cycle": {
          "0%": { filter: "hue-rotate(0deg)" },
          "100%": { filter: "hue-rotate(360deg)" },
        },
      },
    },
  },
  plugins: [require("tailwindcss-animate")],
}
