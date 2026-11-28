<script setup lang="ts">
const props = withDefaults(defineProps<{ name: string; id?: string; size?: 'sm' | 'md' | 'lg'; square?: boolean }>(), { id: undefined, size: 'md', square: false })
const letters = computed(() => initials(props.name))
const color = computed(() => tint(props.id ?? props.name))
</script>

<template>
  <span
    class="inline-grid shrink-0 place-items-center font-bold select-none"
    :class="[color, square ? 'rounded-xl' : 'rounded-full', { sm: 'size-8 text-xs', md: 'size-10 text-sm', lg: 'size-12 text-base' }[size]]"
    aria-hidden="true"
  >{{ letters }}</span>
</template>
