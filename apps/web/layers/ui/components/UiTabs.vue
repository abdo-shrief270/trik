<script setup lang="ts">
/** Tabs with the ARIA pattern: arrow keys move between tabs (mirrored in RTL), Home/End jump. */
const props = defineProps<{ tabs: { id: string; label: string; count?: number }[]; label: string }>()
const active = defineModel<string>({ required: true })
const refs = ref<HTMLButtonElement[]>([])

function onKey(e: KeyboardEvent, i: number) {
  const rtl = document.documentElement.dir === 'rtl'
  const step = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1 }[e.key]
  let to = -1
  if (step) to = (i + step + props.tabs.length) % props.tabs.length
  if (e.key === 'Home') to = 0
  if (e.key === 'End') to = props.tabs.length - 1
  if (to < 0) return
  e.preventDefault()
  active.value = props.tabs[to]!.id
  refs.value[to]?.focus()
}
</script>

<template>
  <div role="tablist" :aria-label="label" class="flex gap-1 border-b border-line">
    <button
      v-for="(tab, i) in tabs"
      :id="`tab-${tab.id}`"
      :key="tab.id"
      ref="refs"
      type="button"
      role="tab"
      :aria-selected="active === tab.id"
      :aria-controls="`panel-${tab.id}`"
      :tabindex="active === tab.id ? 0 : -1"
      class="relative -mb-px flex items-center gap-2 border-b-2 px-3 py-2.5 text-[15px] font-semibold transition-colors"
      :class="active === tab.id ? 'border-indigo text-ink' : 'border-transparent text-muted hover:text-ink'"
      @click="active = tab.id"
      @keydown="onKey($event, i)"
    >
      {{ tab.label }}
      <UiBadge v-if="tab.count !== undefined" :count="tab.count" />
    </button>
  </div>
</template>
