<script setup lang="ts">
import type { Conversation } from '../composables/useInbox'
import { windowLeft } from '../utils/inbox'

const props = defineProps<{ conversation: Conversation; canReply: boolean }>()
const emit = defineEmits<{ send: [string] }>()
const { t } = useI18n()

const MAX = 4096
const text = ref('')
const box = ref<HTMLTextAreaElement | null>(null)

// The window countdown ticks every 30 s.
const now = ref(Date.now())
let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => (timer = setInterval(() => (now.value = Date.now()), 30_000)))
onBeforeUnmount(() => clearInterval(timer))

const open = computed(() => !!props.conversation.window?.open && !!windowLeft(props.conversation.window?.closes_at, now.value))
const left = computed(() => windowLeft(props.conversation.window?.closes_at, now.value))
const free = computed(() => props.conversation.window?.free_until && Date.parse(props.conversation.window.free_until) > now.value)
const disabled = computed(() => !props.canReply || !open.value)
const tooLong = computed(() => text.value.length > MAX)

function submit() {
  const value = text.value.trim()
  if (!value || disabled.value || tooLong.value) return
  emit('send', value)
  text.value = ''
  nextTick(resize)
}

function onKey(e: KeyboardEvent) {
  // Enter sends, Shift+Enter is a new line. IME composition (e.g. some keyboards) never sends.
  if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
    e.preventDefault()
    submit()
  }
}

function resize() {
  const el = box.value
  if (!el) return
  el.style.height = 'auto'
  el.style.height = `${Math.min(el.scrollHeight, 200)}px`
}

watch(() => props.conversation.id, () => {
  text.value = ''
  nextTick(resize)
})
defineExpose({ focus: () => box.value?.focus() })
</script>

<template>
  <div class="border-t border-line bg-white px-5 pt-3 pb-4">
    <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-sm">
      <span class="font-semibold">{{ t('inbox.reply') }}</span>
      <span v-if="open && left" class="flex items-center gap-1.5 text-muted">
        <UiIcon name="info" :size="15" />
        {{ t('inbox.windowLeft', { h: left.hours, m: String(left.minutes).padStart(2, '0') }) }}
        <UiBadge v-if="free" tone="success">{{ t('inbox.freeEntry') }}</UiBadge>
      </span>
    </div>

    <p v-if="!canReply" class="rounded-xl bg-paper px-4 py-3 text-[15px] text-muted">{{ t('inbox.readOnly') }}</p>
    <p v-else-if="!open" class="flex items-start gap-2 rounded-xl bg-warning-bg px-4 py-3 text-[15px] text-warning">
      <UiIcon name="alert" :size="18" class="mt-0.5" />{{ t('inbox.windowClosed') }}
    </p>

    <form v-else class="rounded-2xl border border-line focus-within:border-indigo focus-within:shadow-[0_0_0_4px_rgb(79_59_240/.12)]" @submit.prevent="submit">
      <label for="reply" class="sr-only">{{ t('inbox.replyLabel') }}</label>
      <textarea
        id="reply"
        ref="box"
        v-model="text"
        rows="2"
        dir="auto"
        :placeholder="t('inbox.placeholder')"
        :aria-invalid="tooLong || undefined"
        class="block max-h-[200px] w-full resize-none rounded-t-2xl bg-transparent px-4 pt-3 text-[15px] leading-relaxed outline-none"
        @input="resize"
        @keydown="onKey"
      />
      <div class="flex items-center justify-between gap-3 px-3 pb-2.5">
        <span class="text-xs" :class="tooLong ? 'font-semibold text-danger' : 'text-muted'" aria-live="polite">
          <template v-if="text.length > MAX - 400">{{ text.length }} / {{ MAX }}</template>
          <template v-else>{{ t('inbox.enterHint') }}</template>
        </span>
        <UiButton type="submit" size="sm" :disabled="!text.trim() || tooLong">{{ t('inbox.send') }}</UiButton>
      </div>
    </form>
  </div>
</template>
