<script setup lang="ts">
// The helpdesk layout with the workspace's live counts. The conversation list and chat land in the inbox PR.
const { t } = useI18n()
const session = useSessionStore()
useHead({ title: () => `${t('nav.inbox')} · ${t('brand.name')}` })

const filter = ref('all')
const groups = computed(() => [
  {
    id: 'queue',
    label: t('inbox.queue'),
    items: [
      { id: 'all', label: t('inbox.unread'), count: session.workspace?.badges?.unread ?? 0 },
      { id: 'mine', label: t('inbox.assignedToMe'), count: session.workspace?.badges?.assigned_to_me ?? 0 },
    ],
  },
])
</script>

<template>
  <div class="flex min-h-0 flex-1">
    <AppFilterPanel v-model="filter" :title="t('nav.inbox')" :groups="groups" />
    <section class="flex flex-1 items-center justify-center">
      <UiEmptyState icon="inbox" :title="t('placeholder.inbox.title')" :body="t('placeholder.inbox.body')" />
    </section>
  </div>
</template>
