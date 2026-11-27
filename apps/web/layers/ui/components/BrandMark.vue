<script setup lang="ts">
/** The three-tick mark (brand book): light background, Ink, or Indigo (app icon treatment). */
const props = withDefaults(defineProps<{ tone?: 'light' | 'ink' | 'indigo'; size?: number }>(), { tone: 'light', size: 32 })
const palettes = {
  light: ['#C9C6D6', '#8C88A8', '#4F3BF0'],
  ink: ['#3F3C52', '#7D7998', '#B8F25B'],
  indigo: ['rgba(255,255,255,.42)', 'rgba(255,255,255,.78)', '#B8F25B'],
}
const paths = ['M3 19 L11 27 L27 9', 'M15 19 L23 27 L39 9', 'M27 19 L35 27 L51 9']
</script>

<template>
  <svg :width="size" :height="Math.round((size * 36) / 56)" viewBox="0 0 56 36" aria-hidden="true" class="shrink-0">
    <g fill="none" stroke-width="6" stroke-linecap="round" stroke-linejoin="round">
      <path v-for="(d, i) in paths" :key="i" :d="d" :stroke="palettes[props.tone][i]" />
    </g>
  </svg>
</template>
