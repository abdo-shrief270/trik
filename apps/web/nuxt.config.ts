import tailwindcss from '@tailwindcss/vite'

// Layers in ./layers are registered automatically (ui, auth, inbox, orders, …).
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },
  modules: ['@nuxtjs/i18n', '@pinia/nuxt', '@nuxt/eslint'],
  css: ['~/assets/css/main.css'],
  vite: { plugins: [tailwindcss()] },
  app: {
    head: {
      title: 'Trik',
      link: [
        { rel: 'preconnect', href: 'https://fonts.googleapis.com' },
        { rel: 'stylesheet', href: 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap' },
      ],
    },
  },
  i18n: {
    defaultLocale: 'ar',
    strategy: 'prefix_except_default',
    langDir: 'locales',
    locales: [
      { code: 'ar', language: 'ar-EG', dir: 'rtl', name: 'العربية', file: 'ar.json' },
      { code: 'en', language: 'en-US', dir: 'ltr', name: 'English', file: 'en.json' },
    ],
  },
  runtimeConfig: {
    public: {
      apiBase: 'http://localhost:8080/v1',
      centrifugoUrl: 'ws://localhost:8000/connection/websocket',
    },
  },
  typescript: { strict: true },
})
