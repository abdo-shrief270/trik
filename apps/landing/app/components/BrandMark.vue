<script setup lang="ts">
/**
 * The three-tick mark (brand book, logo). On light: grey, grey, indigo. On ink: dark, mid, lime.
 * `draw` animates the ticks in one after another; `ticks` shows only the first N (sent / read / Trik'd).
 */
const props = withDefaults(defineProps<{ tone?: 'light' | 'ink' | 'indigo'; draw?: boolean; ticks?: 1 | 2 | 3; size?: number }>(), {
  tone: 'light',
  draw: false,
  ticks: 3,
  size: 40,
})

// On Indigo: white ticks at two strengths and the lime third tick, as on the app icon.
const palettes = {
  light: ['#C9C6D6', '#8C88A8', '#4F3BF0'],
  ink: ['#3F3C52', '#7D7998', '#B8F25B'],
  indigo: ['rgba(255,255,255,.42)', 'rgba(255,255,255,.78)', '#B8F25B'],
}
const colors = computed(() => palettes[props.tone])
const paths = ['M3 19 L11 27 L27 9', 'M15 19 L23 27 L39 9', 'M27 19 L35 27 L51 9']
</script>

<template>
  <svg
    :width="size"
    :height="Math.round((size * 36) / 56)"
    viewBox="0 0 56 36"
    aria-hidden="true"
    :class="{ 'tick-draw': draw }"
    class="shrink-0"
  >
    <g fill="none" stroke-width="6" stroke-linecap="round" stroke-linejoin="round">
      <path v-for="(d, i) in paths.slice(0, ticks)" :key="i" :d="d" :stroke="colors[i]" />
    </g>
  </svg>
</template>
