<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'

const props = withDefaults(
  defineProps<{
    to?: RouteLocationRaw
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger' | 'light'
    size?: 'sm' | 'md' | 'lg'
    type?: 'button' | 'submit'
    loading?: boolean
    disabled?: boolean
    block?: boolean
  }>(),
  { to: undefined, variant: 'primary', size: 'md', type: 'button', loading: false, disabled: false, block: false },
)

const classes = computed(() => [
  'relative inline-flex select-none items-center justify-center gap-2 rounded-lg font-semibold whitespace-nowrap transition-colors duration-150',
  'disabled:cursor-not-allowed disabled:opacity-55 aria-disabled:pointer-events-none aria-disabled:opacity-55',
  { sm: 'h-9 px-3 text-sm', md: 'h-11 px-4 text-[15px]', lg: 'h-12 px-6 text-base' }[props.size],
  {
    primary: 'bg-indigo text-white hover:bg-indigo-strong',
    secondary: 'border border-line bg-white text-ink hover:bg-paper',
    ghost: 'text-ink hover:bg-ink/5',
    danger: 'bg-danger text-white hover:bg-[#931B13]',
    light: 'bg-white text-ink hover:bg-indigo-soft',
  }[props.variant],
  props.block ? 'w-full' : '',
])
</script>

<template>
  <NuxtLink v-if="to" :to="to" :class="classes" :aria-disabled="disabled || undefined">
    <slot />
  </NuxtLink>
  <button v-else :type="type" :class="classes" :disabled="disabled || loading" :aria-busy="loading || undefined">
    <UiSpinner v-if="loading" class="absolute" />
    <span class="inline-flex items-center gap-2" :class="{ invisible: loading }"><slot /></span>
  </button>
</template>
