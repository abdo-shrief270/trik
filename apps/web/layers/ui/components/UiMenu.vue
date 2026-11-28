<script setup lang="ts">
/**
 * A dropdown: the trigger slot gets `toggle` and the ARIA props; items are any [role="menuitem"] in the panel.
 * Arrow keys move, Esc closes and returns focus, a click outside closes it.
 */
const props = withDefaults(defineProps<{ label: string; placement?: 'start' | 'end' | 'side'; width?: string }>(), { placement: 'start', width: 'w-72' })
const open = ref(false)
const root = ref<HTMLElement | null>(null)
const panel = ref<HTMLElement | null>(null)
const id = useId()
const triggerAttrs = computed(() => ({ 'aria-haspopup': 'menu' as const, 'aria-expanded': open.value, 'aria-controls': id }))

function items(): HTMLElement[] {
  return [...(panel.value?.querySelectorAll<HTMLElement>('[role="menuitem"]:not([disabled])') ?? [])]
}

async function toggle() {
  open.value = !open.value
  if (open.value) {
    await nextTick()
    items()[0]?.focus()
  }
}

function close(refocus = true) {
  open.value = false
  if (refocus) root.value?.querySelector<HTMLElement>('[aria-haspopup]')?.focus()
}

function onKey(e: KeyboardEvent) {
  const list = items()
  const i = list.indexOf(document.activeElement as HTMLElement)
  if (e.key === 'Escape') { e.preventDefault(); close() }
  else if (e.key === 'ArrowDown') { e.preventDefault(); list[(i + 1) % list.length]?.focus() }
  else if (e.key === 'ArrowUp') { e.preventDefault(); list[(i - 1 + list.length) % list.length]?.focus() }
  else if (e.key === 'Tab') close(false)
}

function onDocClick(e: MouseEvent) {
  if (open.value && root.value && !root.value.contains(e.target as Node)) close(false)
}
onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))

defineExpose({ close })
</script>

<template>
  <div ref="root" class="relative">
    <slot name="trigger" :toggle="toggle" :open="open" :attrs="triggerAttrs" />
    <Transition enter-active-class="transition duration-150 ease-out" enter-from-class="opacity-0 scale-95" leave-active-class="transition duration-100" leave-to-class="opacity-0">
      <div
        v-if="open"
        :id="id"
        ref="panel"
        role="menu"
        :aria-label="props.label"
        class="absolute z-50 rounded-xl border border-line bg-white p-1.5 text-ink shadow-xl"
        :class="[
          props.width,
          props.placement === 'side' ? 'start-full top-0 ms-2 origin-top-left rtl:origin-top-right' : 'top-full mt-2',
          props.placement === 'end' ? 'end-0' : props.placement === 'start' ? 'start-0' : '',
        ]"
        @keydown="onKey"
      >
        <slot :close="close" />
      </div>
    </Transition>
  </div>
</template>
