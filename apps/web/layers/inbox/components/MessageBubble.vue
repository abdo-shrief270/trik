<script setup lang="ts">
import type { Message } from '../composables/useInbox'
import { messageView } from '../utils/inbox'

const props = defineProps<{ message: Message; senderName?: string | null }>()
const emit = defineEmits<{ retry: [Message]; discard: [Message] }>()
const { t, locale } = useI18n()

const out = computed(() => props.message.direction === 'out')
const view = computed(() => messageView(props.message.type ?? 'text', props.message.content as Record<string, unknown> | null))
const time = computed(() => clockTime(props.message.created_at, locale.value))
const failed = computed(() => props.message.status === 'failed' || props.message.local?.state === 'failed')
const sending = computed(() => props.message.local?.state === 'sending')
// WhatsApp-style ticks for what we sent: one (sent), two (delivered), two in indigo (read).
const ticks = computed(() => ({ queued: 0, sent: 1, delivered: 2, read: 2 })[props.message.status as string] ?? 0)
const statusLabel = computed(() => (failed.value ? t('inbox.status.failed') : sending.value ? t('inbox.status.sending') : t(`inbox.status.${props.message.status ?? 'queued'}`)))
</script>

<template>
  <div class="flex" :class="out ? 'justify-end' : 'justify-start'">
    <div class="max-w-[min(34rem,85%)]">
      <p v-if="out && senderName" class="mb-1 text-end text-xs text-muted">{{ senderName }}</p>
      <div
        class="rounded-2xl px-4 py-2.5 text-[15px] leading-relaxed"
        :class="[
          out ? 'rounded-ee-md bg-indigo-soft' : 'rounded-es-md border border-line bg-white',
          failed ? 'ring-2 ring-danger/40' : '',
          sending ? 'opacity-70' : '',
        ]"
      >
        <p v-if="view.kind === 'template'" class="mb-1 flex items-center gap-1.5 text-xs font-semibold text-indigo-strong">
          <UiIcon name="rules" :size="14" />{{ t('inbox.kind.template') }}<span v-if="view.template" dir="ltr">· {{ view.template }}</span>
        </p>
        <p v-if="view.kind !== 'text' && view.kind !== 'buttons' && view.kind !== 'template' && view.kind !== 'reply'" class="flex items-center gap-2 text-muted">
          <UiIcon :name="view.kind === 'location' ? 'globe' : 'link'" :size="16" />{{ t(`inbox.kind.${view.kind}`) }}
        </p>
        <p v-if="view.kind === 'reply'" class="mb-1 text-xs font-semibold text-indigo-strong">{{ t('inbox.kind.reply') }}</p>
        <p v-if="view.text || view.caption" class="break-words whitespace-pre-wrap" dir="auto">{{ view.text ?? view.caption }}</p>
        <div v-if="view.buttons.length" class="mt-2.5 flex flex-wrap gap-1.5">
          <span v-for="b in view.buttons" :key="b" class="rounded-lg border border-indigo/25 bg-white px-3 py-1 text-sm font-semibold text-indigo-strong">{{ b }}</span>
        </div>
        <p class="mt-1 flex items-center justify-end gap-1.5 text-xs text-muted" dir="ltr">
          <time :datetime="message.created_at">{{ time }}</time>
          <template v-if="out">
            <UiSpinner v-if="sending" class="!size-3" />
            <UiIcon v-else-if="failed" name="alert" :size="14" class="text-danger" />
            <span v-else-if="ticks" class="inline-flex" :class="message.status === 'read' ? 'text-indigo' : ''" aria-hidden="true">
              <UiIcon name="check" :size="14" /><UiIcon v-if="ticks > 1" name="check" :size="14" class="-ms-2.5" />
            </span>
            <span class="sr-only">{{ statusLabel }}</span>
          </template>
        </p>
      </div>
      <p v-if="failed" class="mt-1.5 flex items-center justify-end gap-3 text-sm text-danger">
        {{ t('inbox.notSent') }}
        <button v-if="message.local" type="button" class="font-semibold underline" @click="emit('retry', message)">{{ t('inbox.retry') }}</button>
        <button v-if="message.local" type="button" class="text-muted underline" @click="emit('discard', message)">{{ t('inbox.discard') }}</button>
      </p>
    </div>
  </div>
</template>
