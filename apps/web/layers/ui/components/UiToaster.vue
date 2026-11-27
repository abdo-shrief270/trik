<script setup lang="ts">
const { toasts, dismiss } = useToast()
const { t } = useI18n()
const icon = { success: 'checkCircle', error: 'alert', info: 'info' } as const
</script>

<template>
  <div class="pointer-events-none fixed inset-x-0 bottom-4 z-[100] flex flex-col items-center gap-2 px-4 sm:items-end sm:pe-6" role="status" aria-live="polite">
    <TransitionGroup enter-active-class="transition duration-300 ease-out-expo" enter-from-class="opacity-0 translate-y-3" leave-active-class="transition duration-200" leave-to-class="opacity-0">
      <div
        v-for="toast in toasts"
        :key="toast.id"
        class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl bg-ink px-4 py-3 text-[15px] text-white shadow-xl"
        :role="toast.tone === 'error' ? 'alert' : undefined"
      >
        <UiIcon :name="icon[toast.tone]" :size="18" class="mt-0.5" :class="toast.tone === 'error' ? 'text-[#FF8A80]' : toast.tone === 'success' ? 'text-lime' : 'text-mist'" />
        <p class="flex-1">{{ toast.message }}</p>
        <button type="button" class="-me-1 text-mist hover:text-white" :aria-label="t('ui.close')" @click="dismiss(toast.id)">
          <UiIcon name="x" :size="16" />
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>
