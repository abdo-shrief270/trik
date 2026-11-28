<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'

const props = withDefaults(
  defineProps<{
    to?: RouteLocationRaw
    variant?: 'primary' | 'dark' | 'light' | 'ghost' | 'ghost-dark'
    size?: 'md' | 'lg'
    type?: 'button' | 'submit'
    disabled?: boolean
    magnetic?: boolean
    arrow?: boolean
  }>(),
  { to: undefined, variant: 'primary', size: 'md', type: 'button', magnetic: false, arrow: false },
)

const { style, move, leave } = useMagnetic(0.2)

const classes = computed(() => [
  'group relative inline-flex select-none items-center justify-center gap-2 rounded-lg font-semibold whitespace-nowrap',
  'transition-[background-color,color,box-shadow,transform] duration-300 ease-out-expo disabled:cursor-not-allowed disabled:opacity-60',
  props.size === 'lg' ? 'h-14 px-7 text-base' : 'h-11 px-5 text-[15px]',
  {
    primary: 'bg-indigo text-white hover:bg-indigo-strong shadow-[0_10px_30px_-10px_rgb(79_59_240/.6)]',
    dark: 'bg-ink text-white hover:bg-ink-3',
    light: 'bg-white text-ink hover:bg-indigo-soft',
    ghost: 'border border-ink/15 text-ink hover:border-ink/40 hover:bg-white',
    'ghost-dark': 'border border-white/20 text-white hover:border-white/50 hover:bg-white/5',
  }[props.variant],
])
</script>

<template>
  <NuxtLink
    v-if="to"
    :to="to"
    :class="classes"
    :style="magnetic ? style : undefined"
    @pointermove="magnetic && move($event)"
    @pointerleave="magnetic && leave()"
  >
    <slot />
    <svg v-if="arrow" class="size-4 transition-transform duration-300 group-hover:translate-x-1 rtl:-scale-x-100 rtl:group-hover:-translate-x-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
  </NuxtLink>
  <button
    v-else
    :type="type"
    :disabled="disabled"
    :class="classes"
    :style="magnetic ? style : undefined"
    @pointermove="magnetic && move($event)"
    @pointerleave="magnetic && leave()"
  >
    <slot />
    <svg v-if="arrow" class="size-4 transition-transform duration-300 group-hover:translate-x-1 rtl:-scale-x-100 rtl:group-hover:-translate-x-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
  </button>
</template>
