<script setup lang="ts">
/** The workspace you're working in, grouped by organization, with where work is waiting in each. */
const session = useSessionStore()
const { t } = useI18n()
const menu = ref<{ close: (refocus?: boolean) => void } | null>(null)

const groups = computed(() => {
  if (session.organizations.length) {
    return session.organizations.map((o) => ({ id: o.id ?? '', name: o.name ?? '', workspaces: o.workspaces ?? [] }))
  }
  return [{ id: 'all', name: '', workspaces: session.workspaces }]
})

function pick(id: string) {
  session.selectWorkspace(id)
  menu.value?.close()
  // Everything on screen belongs to a workspace: reload the current page's data from scratch.
  refreshNuxtData()
}
</script>

<template>
  <UiMenu ref="menu" :label="t('shell.switchWorkspace')" placement="side" width="w-80">
    <template #trigger="{ toggle, attrs }">
      <button
        type="button"
        v-bind="attrs"
        class="relative rounded-xl ring-white/20 transition hover:ring-2"
        :aria-label="t('shell.workspaceLabel', { name: session.workspace?.name ?? '' })"
        @click="toggle"
      >
        <UiAvatar :id="session.workspace?.id" :name="session.workspace?.name ?? '?'" square />
        <span v-if="session.unreadElsewhere > 0" class="absolute -end-1 -top-1 size-3 rounded-full border-2 border-ink bg-indigo" aria-hidden="true" />
      </button>
    </template>

    <div class="max-h-[70dvh] overflow-y-auto">
      <section v-for="g in groups" :key="g.id" class="py-1">
        <p v-if="g.name" class="flex items-center gap-1.5 px-3 pt-2 pb-1 text-xs font-semibold text-muted"><UiIcon name="building" :size="14" />{{ g.name }}</p>
        <button
          v-for="w in g.workspaces"
          :key="w.id"
          type="button"
          role="menuitem"
          class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-start hover:bg-paper focus:bg-paper focus:outline-none"
          :aria-current="w.id === session.workspaceId ? 'true' : undefined"
          @click="pick(w.id!)"
        >
          <UiAvatar :id="w.id" :name="w.name ?? '?'" size="sm" square />
          <span class="min-w-0 flex-1">
            <span class="block truncate font-semibold">{{ w.name }}</span>
            <span class="block text-xs text-muted">{{ t(`roles.${w.role ?? 'agent'}`) }}</span>
          </span>
          <UiBadge v-if="(w.badges?.unread ?? 0) > 0" tone="indigo" :count="w.badges!.unread" :aria-label="t('shell.unreadCount', { n: w.badges!.unread })" />
          <UiIcon v-if="w.id === session.workspaceId" name="check" :size="18" class="text-indigo" />
        </button>
      </section>
    </div>
  </UiMenu>
</template>
