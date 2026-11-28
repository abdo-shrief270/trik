<script setup lang="ts">
import type { PropType } from 'vue'

defineProps({
  icon: { type: String as PropType<IconName>, default: 'inbox' },
  title: { type: String, required: true },
  body: { type: String, default: undefined },
})
</script>

<template>
  <div class="flex flex-col items-center justify-center px-6 py-16 text-center">
    <span class="grid size-14 place-items-center rounded-2xl bg-indigo-soft text-indigo"><UiIcon :name="icon" :size="26" /></span>
    <h2 class="mt-5 text-lg font-bold">{{ title }}</h2>
    <p v-if="body" class="mt-2 max-w-md leading-relaxed text-muted">{{ body }}</p>
    <div v-if="$slots.default" class="mt-6 flex flex-wrap justify-center gap-2"><slot /></div>
  </div>
</template>
