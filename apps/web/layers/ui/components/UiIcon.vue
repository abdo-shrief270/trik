<script setup lang="ts">
import type { PropType } from 'vue'

/** Stroke icons (layers/ui/utils/icons.ts). Decorative by default; pass `label` when the icon alone carries meaning. */
const props = defineProps({
  name: { type: String as PropType<IconName>, required: true },
  size: { type: Number, default: 20 },
  label: { type: String, default: undefined },
})
</script>

<template>
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    class="shrink-0"
    :role="props.label ? 'img' : undefined"
    :aria-label="props.label"
    :aria-hidden="props.label ? undefined : 'true'"
  >
    <path :d="ICONS[props.name]" />
  </svg>
</template>
