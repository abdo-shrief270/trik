<script setup lang="ts">
/** The app shell: icon rail (workspace, navigation, account) and the content area. */
const session = useSessionStore()
const { t } = useI18n()
const route = useRoute()

const nav = computed(() => [
  { to: '/', icon: 'home' as const, label: t('nav.home'), exact: true },
  { to: '/inbox', icon: 'inbox' as const, label: t('nav.inbox'), badge: session.workspace?.badges?.unread ?? 0 },
  { to: '/confirmations', icon: 'checkCircle' as const, label: t('nav.confirmations') },
  { to: '/orders', icon: 'orders' as const, label: t('nav.orders') },
])

function active(item: { to: string; exact?: boolean }) {
  return item.exact ? route.path === item.to : route.path.startsWith(item.to)
}

// Badges until the real-time channel is wired (badges.updated on user:{id}): refresh while the tab is open.
let timer: ReturnType<typeof setInterval> | undefined
function poll() {
  if (document.visibilityState === 'visible') session.load().catch(() => {})
}
onMounted(() => (timer = setInterval(poll, 60_000)))
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <div class="flex h-dvh overflow-hidden">
    <!-- A fixed-height viewport: pages scroll inside <main> (the inbox scrolls each column on its own). -->
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:start-24 focus:top-3 focus:z-50 focus:rounded-lg focus:bg-indigo focus:px-4 focus:py-2 focus:text-white">{{ t('shell.skip') }}</a>

    <nav :aria-label="t('shell.main')" class="on-dark flex h-full w-[76px] shrink-0 flex-col items-center gap-2 bg-ink py-4 text-white">
      <NuxtLink to="/" class="mb-3 grid h-10 place-items-center" :aria-label="t('brand.name')"><BrandMark tone="ink" :size="34" /></NuxtLink>
      <WorkspaceSwitcher />
      <span class="my-2 h-px w-8 bg-white/10" aria-hidden="true" />

      <ul class="flex flex-col items-center gap-1.5">
        <li v-for="item in nav" :key="item.to">
          <NuxtLink
            :to="item.to"
            class="group relative grid size-12 place-items-center rounded-xl transition-colors"
            :class="active(item) ? 'bg-white/12 text-white' : 'text-dusk hover:bg-white/6 hover:text-white'"
            :aria-current="active(item) ? 'page' : undefined"
          >
            <UiIcon :name="item.icon" :size="22" />
            <span class="sr-only">{{ item.label }}<template v-if="item.badge">, {{ t('shell.unreadCount', { n: item.badge }) }}</template></span>
            <span v-if="item.badge" class="absolute -end-0.5 -top-0.5" aria-hidden="true"><UiBadge tone="indigo" :count="item.badge" /></span>
            <!-- label on hover/focus -->
            <span class="pointer-events-none absolute start-full z-40 ms-3 rounded-md bg-ink-3 px-2.5 py-1 text-sm font-medium whitespace-nowrap text-white opacity-0 shadow-lg transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100" aria-hidden="true">{{ item.label }}</span>
          </NuxtLink>
        </li>
      </ul>

      <div class="mt-auto flex flex-col items-center gap-3">
        <NuxtLink
          to="/settings"
          class="grid size-12 place-items-center rounded-xl transition-colors"
          :class="route.path.startsWith('/settings') ? 'bg-white/12 text-white' : 'text-dusk hover:bg-white/6 hover:text-white'"
          :aria-label="t('nav.settings')"
          :aria-current="route.path.startsWith('/settings') ? 'page' : undefined"
        >
          <UiIcon name="settings" :size="22" />
        </NuxtLink>
        <AccountMenu />
      </div>
    </nav>

    <div class="flex min-w-0 flex-1 flex-col">
      <VerifyEmailBanner />
      <main id="main" tabindex="-1" class="flex min-h-0 flex-1 overflow-y-auto outline-none">
        <slot />
      </main>
    </div>
    <UiToaster />
  </div>
</template>
