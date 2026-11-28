<script setup lang="ts">
const session = useSessionStore()
const { t, locale, locales, setLocale } = useI18n()

const other = computed(() => locales.value.find((l) => l.code !== locale.value))
</script>

<template>
  <UiMenu :label="t('shell.account')" placement="side" width="w-64">
    <template #trigger="{ toggle, attrs }">
      <button type="button" v-bind="attrs" class="rounded-full ring-white/20 transition hover:ring-2" :aria-label="t('shell.account')" @click="toggle">
        <UiAvatar :id="session.user?.id" :name="session.user?.name ?? '?'" size="sm" />
      </button>
    </template>
    <div class="border-b border-line px-3 pt-2 pb-3">
      <p class="truncate font-semibold">{{ session.user?.name }}</p>
      <p class="truncate text-sm text-muted" dir="ltr">{{ session.user?.email }}</p>
    </div>
    <div class="pt-1.5">
      <button
        v-if="other"
        type="button"
        role="menuitem"
        class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-start hover:bg-paper focus:bg-paper focus:outline-none"
        @click="setLocale(other.code)"
      >
        <UiIcon name="globe" :size="18" class="text-muted" />
        <span :lang="other.code">{{ other.name }}</span>
      </button>
      <NuxtLink to="/settings" role="menuitem" class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 hover:bg-paper focus:bg-paper focus:outline-none">
        <UiIcon name="settings" :size="18" class="text-muted" />{{ t('nav.settings') }}
      </NuxtLink>
      <button
        type="button"
        role="menuitem"
        class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-start text-danger hover:bg-danger-bg focus:bg-danger-bg focus:outline-none"
        @click="session.signOut()"
      >
        <UiIcon name="logout" :size="18" />{{ t('auth.signOut') }}
      </button>
    </div>
  </UiMenu>
</template>
