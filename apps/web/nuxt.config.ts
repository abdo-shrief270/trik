import tailwindcss from '@tailwindcss/vite'

// The seller web app (app.trik.chat). A single-page app: it talks to the API with the Sanctum session cookie
// (credentials + XSRF), so no server ever holds the session. Layers in ./layers register automatically.
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  ssr: false,
  devtools: { enabled: false },
  modules: ['@nuxtjs/i18n', '@pinia/nuxt', '@nuxt/fonts', '@nuxt/eslint'],
  css: ['~/assets/css/main.css'],
  vite: { plugins: [tailwindcss()] },
  app: {
    head: {
      title: 'Trik',
      meta: [
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
        { name: 'robots', content: 'noindex' },
        { name: 'theme-color', content: '#14121F' },
      ],
      link: [
        { rel: 'icon', type: 'image/svg+xml', href: '/favicon.svg' },
      ],
    },
  },
  // Self-hosted at build time: sellers' browsers never call Google.
  fonts: {
    families: [
      { name: 'Space Grotesk', weights: [500, 600, 700] },
      { name: 'Inter', weights: [400, 500, 600, 700] },
      { name: 'IBM Plex Sans Arabic', weights: [400, 500, 600, 700], subsets: ['arabic', 'latin'] },
    ],
  },
  i18n: {
    defaultLocale: 'ar',
    // Stable URLs (emails link to /reset-password and /verified); the language lives in a cookie.
    strategy: 'no_prefix',
    langDir: 'locales',
    detectBrowserLanguage: { useCookie: true, cookieKey: 'trik_locale', fallbackLocale: 'ar', redirectOn: 'root' },
    locales: [
      { code: 'ar', language: 'ar-EG', dir: 'rtl', name: 'العربية', file: 'ar.json' },
      { code: 'en', language: 'en-US', dir: 'ltr', name: 'English', file: 'en.json' },
    ],
  },
  runtimeConfig: {
    public: {
      // NUXT_PUBLIC_API_BASE. The CSRF cookie route is on the same origin, without /v1.
      apiBase: 'http://localhost:8080/v1',
      centrifugoUrl: 'ws://localhost:8000/connection/websocket',
    },
  },
  typescript: { strict: true },
})
