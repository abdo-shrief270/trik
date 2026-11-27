<script setup lang="ts">
const { t, locale } = useI18n()
const { list } = useCopy()
const reduced = useReducedMotion()
const lang = computed(() => (locale.value === 'ar' ? 'ar' : 'en'))

const snippets = computed(() => list('visual.inbox.snippets'))
const agents = computed(() => list('visual.inbox.agents'))

interface Chat { id: number; order: number; snippet: number; agent: number; unread: number }
const chats = ref<Chat[]>(SAMPLE_ORDERS.slice(0, 5).map((o, i) => ({ id: o.id, order: i, snippet: i, agent: i % 3, unread: i < 2 ? i + 1 : 0 })))
let n = 5

// A new message arrives: its chat jumps to the top with one more unread.
function arrive() {
  const i = Math.floor(Math.random() * chats.value.length)
  const [chat] = chats.value.splice(i, 1)
  chat!.unread += 1
  chat!.snippet = n++ % snippets.value.length
  chats.value.unshift(chat!)
}

const root = ref<HTMLElement | null>(null)
const inView = ref(false)
useIntersectionObserver(root, ([e]) => (inView.value = !!e?.isIntersecting))
const { pause, resume } = useIntervalFn(arrive, 2200, { immediate: false })
watch([inView, reduced], ([v, r]) => (v && !r ? resume() : pause()), { immediate: true })

const avatarTint = ['bg-[#F6E3D7] text-[#8A4B22]', 'bg-indigo-soft text-indigo-strong', 'bg-success-bg text-success']
</script>

<template>
  <div ref="root" class="overflow-hidden rounded-[28px] border border-line bg-white shadow-[0_40px_100px_-50px_rgb(20_18_31/.45)]">
    <div class="flex items-center gap-2 border-b border-line p-3">
      <span class="rounded-lg bg-ink px-3 py-1.5 text-sm font-semibold text-white">{{ t('visual.inbox.all') }}</span>
      <span class="rounded-lg px-3 py-1.5 text-sm font-medium text-muted">{{ t('visual.inbox.mine') }}</span>
    </div>
    <TransitionGroup tag="ul" move-class="transition-transform duration-700 ease-out-expo" class="divide-y divide-line">
      <li v-for="c in chats" :key="c.id" class="flex items-center gap-3 bg-white px-4 py-3.5">
        <span class="grid size-11 shrink-0 place-items-center rounded-full font-bold" :class="avatarTint[c.order % 3]">{{ SAMPLE_ORDERS[c.order]!.name[lang].charAt(0) }}</span>
        <div class="min-w-0 flex-1">
          <div class="flex items-center justify-between gap-2">
            <span class="truncate font-semibold">{{ SAMPLE_ORDERS[c.order]!.name[lang] }}</span>
            <span class="shrink-0 text-xs text-muted">{{ agents[c.agent] }}</span>
          </div>
          <div class="mt-0.5 flex items-center justify-between gap-2">
            <Transition mode="out-in" enter-active-class="transition duration-300" enter-from-class="opacity-0" leave-active-class="transition duration-150" leave-to-class="opacity-0">
              <span :key="c.snippet" class="truncate text-sm text-muted">{{ snippets[c.snippet] }}</span>
            </Transition>
            <Transition enter-active-class="transition duration-300 ease-out-expo" enter-from-class="scale-0">
              <span v-if="c.unread" :key="c.unread" class="grid h-5 min-w-5 shrink-0 animate-[word-up_.4s_var(--ease-out-expo)] place-items-center rounded-full bg-indigo px-1.5 text-xs font-bold text-white tabular-nums">{{ c.unread }}</span>
            </Transition>
          </div>
        </div>
      </li>
    </TransitionGroup>
    <div class="flex items-center gap-2 border-t border-line bg-paper px-4 py-3 text-sm text-warning">
      <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
      {{ t('visual.inbox.window') }}
    </div>
  </div>
</template>
