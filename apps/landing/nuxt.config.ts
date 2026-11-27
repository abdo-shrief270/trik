import tailwindcss from '@tailwindcss/vite'

// trik.chat marketing site: static (nuxt generate), Arabic first, English under /en.
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: false },
  modules: ['@nuxtjs/i18n', '@nuxt/fonts', '@vueuse/nuxt', '@nuxt/eslint'],
  css: ['~/assets/css/main.css'],
  vite: { plugins: [tailwindcss()] },

  app: {
    pageTransition: { name: 'page', mode: 'out-in' },
    head: {
      htmlAttrs: { lang: 'ar' },
      meta: [
        { name: 'viewport', content: 'width=device-width, initial-scale=1, viewport-fit=cover' },
        { name: 'theme-color', content: '#14121F' },
      ],
      link: [{ rel: 'icon', type: 'image/svg+xml', href: '/favicon.svg' }],
    },
  },

  // Self-hosted at build time: no request to Google from the visitor's browser.
  fonts: {
    families: [
      { name: 'Space Grotesk', weights: [500, 600, 700] },
      { name: 'Inter', weights: [400, 500, 600, 700] },
      { name: 'IBM Plex Sans Arabic', weights: [400, 500, 600, 700], subsets: ['arabic', 'latin'] },
    ],
  },

  i18n: {
    baseUrl: 'https://trik.chat',
    defaultLocale: 'ar',
    strategy: 'prefix_except_default',
    langDir: 'locales',
    detectBrowserLanguage: false,
    locales: [
      { code: 'ar', language: 'ar-EG', dir: 'rtl', name: 'العربية', file: 'ar.json' },
      { code: 'en', language: 'en-US', dir: 'ltr', name: 'English', file: 'en.json' },
    ],
  },

  runtimeConfig: {
    public: {
      siteUrl: 'https://trik.chat',
      // The waitlist endpoint of apps/api (POST {apiBase}/waitlist).
      apiBase: 'http://localhost:8080/v1',
    },
  },

  nitro: {
    prerender: { crawlLinks: true, routes: ['/', '/en', '/404.html'] },
  },

  typescript: { strict: true },
})
