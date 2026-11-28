<script setup lang="ts">
/** The settings sections the member may open, as links (each section is its own page). */
const { t } = useI18n()
const route = useRoute()
const { can } = usePermissions()

const items = computed(() => [
  { to: '/settings', label: t('settingsNav.account'), show: true },
  { to: '/settings/stores', label: t('settingsNav.stores'), show: can('commerce.stores.view') },
  { to: '/confirmations/settings', label: t('settingsNav.confirmation'), show: can('confirmation.settings.view') },
  { to: '/settings/risk', label: t('settingsNav.risk'), show: can('risk.settings.view') },
  { to: '/settings/privacy', label: t('settingsNav.privacy'), show: can('compliance.requests.view') },
].filter((i) => i.show))
</script>

<template>
  <nav :aria-label="t('nav.settings')" class="mb-8 overflow-x-auto">
    <ul class="flex gap-1 border-b border-line">
      <li v-for="item in items" :key="item.to">
        <NuxtLink
          :to="item.to"
          :aria-current="route.path === item.to ? 'page' : undefined"
          class="-mb-px inline-block border-b-2 px-4 py-2.5 text-[15px] font-semibold whitespace-nowrap transition-colors"
          :class="route.path === item.to ? 'border-indigo text-indigo' : 'border-transparent text-muted hover:text-ink'"
        >
          {{ item.label }}
        </NuxtLink>
      </li>
    </ul>
  </nav>
</template>
