<script setup lang="ts">
/** Renders text word by word, each word sliding up after the one before it. Screen readers get the plain text. */
const props = withDefaults(defineProps<{ text: string; delay?: number; stagger?: number }>(), { delay: 0, stagger: 70 })
const words = computed(() => props.text.split(/\s+/).filter(Boolean))
</script>

<template>
  <span>
    <span class="sr-only">{{ text }}</span>
    <span aria-hidden="true">
      <template v-for="(w, i) in words" :key="i">
        <span class="split-word"><span :style="{ '--d': `${delay + i * stagger}ms` }">{{ w }}</span></span>{{ i < words.length - 1 ? ' ' : '' }}
      </template>
    </span>
  </span>
</template>
