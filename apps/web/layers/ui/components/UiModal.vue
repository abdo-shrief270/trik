<script setup lang="ts">
/**
 * A modal (or a side drawer with `side`). Built on <dialog>: the browser traps focus, Esc closes it, and the
 * rest of the page is inert. Focus returns to whatever opened it.
 */
const props = withDefaults(defineProps<{ title: string; side?: boolean; size?: 'sm' | 'md' | 'lg' }>(), { side: false, size: 'md' })
const open = defineModel<boolean>({ default: false })
const { t } = useI18n()
const dialog = ref<HTMLDialogElement | null>(null)
const titleId = useId()

watch(open, (v) => {
  const d = dialog.value
  if (!d) return
  if (v && !d.open) d.showModal()
  if (!v && d.open) d.close()
}, { flush: 'post' })
onMounted(() => open.value && dialog.value?.showModal())

function onBackdrop(e: MouseEvent) {
  if (e.target === dialog.value) open.value = false
}
</script>

<template>
  <dialog
    ref="dialog"
    :aria-labelledby="titleId"
    class="m-0 max-h-none max-w-none bg-transparent p-0 backdrop:bg-ink/45 backdrop:backdrop-blur-[2px]"
    :class="props.side ? 'ms-auto h-dvh w-full max-w-md' : 'm-auto w-[calc(100%-2rem)]'"
    @close="open = false"
    @click="onBackdrop"
  >
    <div
      class="flex flex-col bg-white shadow-2xl"
      :class="props.side ? 'h-full' : ['max-h-[85dvh] rounded-2xl', { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-3xl' }[props.size], 'mx-auto']"
    >
      <header class="flex items-center justify-between gap-4 border-b border-line px-6 py-4">
        <h2 :id="titleId" class="text-lg font-bold">{{ title }}</h2>
        <button type="button" class="grid size-9 place-items-center rounded-lg text-muted hover:bg-paper hover:text-ink" :aria-label="t('ui.close')" @click="open = false">
          <UiIcon name="x" :size="18" />
        </button>
      </header>
      <div class="flex-1 overflow-y-auto px-6 py-5"><slot /></div>
      <footer v-if="$slots.footer" class="flex justify-end gap-2 border-t border-line px-6 py-4"><slot name="footer" /></footer>
    </div>
  </dialog>
</template>
