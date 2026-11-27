<script setup lang="ts">
/** A status chip or a count. Lime ("ink" tone on a dark chip) is only for a confirmed order: never on light. */
const props = withDefaults(defineProps<{ tone?: 'neutral' | 'indigo' | 'success' | 'warning' | 'danger' | 'ink'; count?: number; max?: number }>(), {
  tone: 'neutral',
  count: undefined,
  max: 99,
})
const text = computed(() => (props.count !== undefined && props.count > props.max ? `${props.max}+` : props.count))
</script>

<template>
  <span
    class="inline-flex items-center gap-1 rounded-full font-semibold whitespace-nowrap"
    :class="[
      count !== undefined ? 'h-5 min-w-5 justify-center px-1.5 text-[11px] tabular-nums' : 'px-2.5 py-0.5 text-[13px]',
      {
        neutral: 'bg-ink/[.06] text-ink',
        indigo: 'bg-indigo text-white',
        success: 'bg-success-bg text-success',
        warning: 'bg-warning-bg text-warning',
        danger: 'bg-danger-bg text-danger',
        ink: 'bg-ink text-white',
      }[tone],
    ]"
  >
    <template v-if="count !== undefined">{{ text }}</template>
    <slot v-else />
  </span>
</template>
