<script setup lang="ts">
/**
 * The hero's live chat: an order is confirmed in WhatsApp, step by step, then the loop restarts.
 * Steps: 0 empty · 1 order card · 2 store typing · 3 message sent (1 tick) · 4 read (2 ticks)
 *        5 customer typing · 6 customer confirms · 7 Trik'd (3 ticks, card confirmed).
 */
const props = withDefaults(defineProps<{ tilt?: boolean; loop?: boolean }>(), { tilt: true, loop: true })

const { t } = useI18n()
const reduced = useReducedMotion()
const root = ref<HTMLElement | null>(null)
// Starts with the order card already in (also on the server render), so the first paint is never an empty chat.
const step = ref(1)

// ms spent on each step before the next: Trik'd about 4.5 s after the chat comes into view, then a long hold.
const TIMELINE = [400, 600, 1000, 650, 750, 900, 650, 4800]
let timer: ReturnType<typeof setTimeout> | undefined
const visible = ref(false)

function schedule() {
  clearTimeout(timer)
  if (!visible.value) return
  timer = setTimeout(() => {
    if (step.value >= 7) {
      if (!props.loop) return
      step.value = 0
    } else {
      step.value++
    }
    schedule()
  }, TIMELINE[step.value])
}

useIntersectionObserver(root, ([entry]) => {
  visible.value = !!entry?.isIntersecting
  if (reduced.value) {
    step.value = 7
    return
  }
  if (visible.value) schedule()
  else clearTimeout(timer)
})
const docVisible = useDocumentVisibility()
watch(docVisible, (v) => (v === 'visible' ? schedule() : clearTimeout(timer)))
onBeforeUnmount(() => clearTimeout(timer))

const ticks = computed<1 | 2 | 3>(() => (step.value >= 7 ? 3 : step.value >= 4 ? 2 : 1))
const confirmed = computed(() => step.value >= 7)

// Gentle 3D tilt toward the pointer.
const tiltStyle = ref<Record<string, string>>({})
function onMove(e: PointerEvent) {
  if (!props.tilt || reduced.value || e.pointerType !== 'mouse' || !root.value) return
  const r = root.value.getBoundingClientRect()
  const x = (e.clientX - r.left) / r.width - 0.5
  const y = (e.clientY - r.top) / r.height - 0.5
  tiltStyle.value = { transform: `perspective(1200px) rotateY(${x * 8}deg) rotateX(${-y * 8}deg)` }
}
function onLeave() {
  tiltStyle.value = { transform: 'perspective(1200px) rotateY(0) rotateX(0)' }
}
</script>

<template>
  <div ref="root" class="relative" :data-step="step" @pointermove="onMove" @pointerleave="onLeave">
    <div
      class="relative mx-auto w-full max-w-[420px] overflow-hidden rounded-[28px] border border-white/10 bg-white text-ink shadow-[0_40px_120px_-30px_rgb(79_59_240/.55)] transition-transform duration-700 ease-out-expo will-change-transform"
      :style="tiltStyle"
      role="img"
      :aria-label="t('chat.label')"
    >
      <!-- Conversation header -->
      <div class="flex items-center gap-3 border-b border-line px-5 py-4">
        <div class="grid size-11 place-items-center rounded-full bg-[#F6E3D7] text-lg font-bold text-[#8A4B22]">{{ t('chat.name').charAt(0) }}</div>
        <div class="min-w-0 flex-1">
          <div class="font-semibold">{{ t('chat.name') }}</div>
          <div class="truncate text-[13px] text-muted">
            <span v-if="step === 5" class="text-indigo">{{ t('chat.typing') }}…</span>
            <span v-else>{{ t('chat.meta') }}</span>
          </div>
        </div>
      </div>

      <div class="flex min-h-[440px] flex-col gap-3 bg-paper p-4 sm:p-5">
        <!-- Order card -->
        <Transition enter-active-class="transition duration-700 ease-out-expo" enter-from-class="opacity-0 -translate-y-3 scale-95">
          <div
            v-if="step >= 1"
            class="rounded-2xl border p-4 transition-colors duration-500"
            :class="confirmed ? 'border-ink bg-ink text-white' : 'border-indigo/20 bg-indigo-soft'"
          >
            <div class="flex items-center justify-between gap-3">
              <div class="flex items-center gap-2 font-semibold">
                <BrandMark :tone="confirmed ? 'ink' : 'light'" :size="26" />
                <span class="whitespace-nowrap">{{ t('chat.order') }}</span>
              </div>
              <span
                class="rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap transition-colors duration-500"
                :class="confirmed ? 'bg-lime text-ink' : 'bg-warning-bg text-warning'"
              >
                {{ confirmed ? t('chat.confirmed') : t('chat.pending') }}
              </span>
            </div>
          </div>
        </Transition>

        <!-- Store typing -->
        <div v-if="step === 2" class="typing flex w-16 items-center justify-center gap-1 self-end rounded-2xl bg-indigo-soft px-4 py-3 text-indigo">
          <span /><span /><span />
        </div>

        <!-- Store message -->
        <Transition enter-active-class="transition duration-500 ease-out-expo" enter-from-class="opacity-0 translate-y-3">
          <div v-if="step >= 3" class="max-w-[88%] self-end rounded-2xl rounded-ee-md bg-indigo-soft px-4 py-3 text-[15px] leading-relaxed">
            {{ t('chat.message') }}
            <div class="mt-3 grid grid-cols-2 gap-2">
              <span
                class="rounded-lg border py-2 text-center text-sm font-semibold transition-all duration-300"
                :class="step >= 6 ? 'scale-95 border-indigo bg-indigo text-white' : 'border-mist bg-white text-indigo-strong'"
              >{{ t('chat.confirm') }}</span>
              <span class="rounded-lg border border-mist bg-white py-2 text-center text-sm font-semibold text-indigo-strong">{{ t('chat.edit') }}</span>
            </div>
            <div class="mt-2 flex items-center justify-end gap-1.5 text-xs text-muted" dir="ltr">
              <span>10:30</span>
              <BrandMark :key="ticks" :ticks="ticks" :size="22" draw />
            </div>
          </div>
        </Transition>

        <!-- Customer typing -->
        <div v-if="step === 5" class="typing flex w-16 items-center justify-center gap-1 self-start rounded-2xl border border-line bg-white px-4 py-3 text-muted">
          <span /><span /><span />
        </div>

        <!-- Customer reply -->
        <Transition enter-active-class="transition duration-500 ease-out-expo" enter-from-class="opacity-0 translate-y-3">
          <div v-if="step >= 6" class="self-start rounded-2xl rounded-es-md border border-line bg-white px-4 py-2.5 text-[15px]">
            {{ t('chat.reply') }}
            <span class="ms-3 text-xs text-muted" dir="ltr">10:42</span>
          </div>
        </Transition>

        <!-- Trik'd -->
        <Transition enter-active-class="transition duration-700 ease-out-expo" enter-from-class="opacity-0 scale-75">
          <div v-if="confirmed" class="mt-1 inline-flex items-center gap-2.5 self-center rounded-full bg-ink py-2 ps-3.5 pe-4 text-sm font-semibold text-white">
            <BrandMark tone="ink" :size="30" draw />
            {{ t('chat.trikd') }}
          </div>
        </Transition>
      </div>
    </div>
  </div>
</template>
