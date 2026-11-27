<script setup lang="ts">
import type { Conversation } from '../composables/useInbox'

const props = defineProps<{ conversation: Conversation; active: boolean; assigneeName?: string | null }>()
const { t, locale } = useI18n()

const name = computed(() => props.conversation.contact?.name || formatPhone(props.conversation.contact?.phone))
const unread = computed(() => props.conversation.unread_count ?? 0)
const preview = computed(() => {
  const last = props.conversation.last_message
  if (!last) return ''
  const text = last.preview || t(`inbox.kind.${['image', 'video', 'audio', 'document', 'sticker', 'location', 'template'].includes(last.type ?? '') ? last.type : 'unsupported'}`)
  return last.direction === 'out' ? `${t('inbox.you')}: ${text}` : text
})
const when = computed(() => shortTime(props.conversation.last_message?.at, locale.value))
</script>

<template>
  <NuxtLink
    :to="{ query: { ...$route.query, c: conversation.id } }"
    class="relative flex gap-3 border-b border-line px-4 py-3.5 transition-colors"
    :class="active ? 'bg-indigo-soft/70' : 'hover:bg-paper'"
    :aria-current="active ? 'true' : undefined"
  >
    <span v-if="active" class="absolute inset-y-0 start-0 w-1 bg-indigo" aria-hidden="true" />
    <UiAvatar :id="conversation.contact?.id" :name="name" />
    <span class="min-w-0 flex-1">
      <span class="flex items-baseline justify-between gap-2">
        <span class="flex min-w-0 items-center gap-1.5">
          <span v-if="unread" class="size-2 shrink-0 rounded-full bg-indigo" aria-hidden="true" />
          <span class="truncate" :class="unread ? 'font-bold' : 'font-semibold'" :dir="conversation.contact?.name ? 'auto' : 'ltr'">{{ name }}</span>
        </span>
        <time class="shrink-0 text-xs text-muted" :datetime="conversation.last_message?.at">{{ when }}</time>
      </span>
      <span class="mt-0.5 flex items-center justify-between gap-2">
        <span class="truncate text-sm" :class="unread ? 'text-ink' : 'text-muted'" dir="auto">{{ preview }}</span>
        <UiBadge v-if="unread" tone="indigo" :count="unread" />
      </span>
      <span class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
        <span v-if="conversation.status === 'closed'" class="rounded-md bg-ink/[.06] px-1.5 py-0.5 text-muted">{{ t('inbox.closed') }}</span>
        <span v-if="assigneeName" class="inline-flex items-center gap-1 rounded-md bg-ink/[.06] px-1.5 py-0.5 text-muted"><UiIcon name="user" :size="12" />{{ assigneeName }}</span>
        <span v-if="conversation.window && !conversation.window.open && conversation.status !== 'closed'" class="rounded-md bg-warning-bg px-1.5 py-0.5 text-warning">{{ t('inbox.windowClosedShort') }}</span>
      </span>
      <span class="sr-only">{{ unread ? t('shell.unreadCount', { n: unread }) : '' }}</span>
    </span>
  </NuxtLink>
</template>
