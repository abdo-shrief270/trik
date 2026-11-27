<script setup lang="ts">
defineProps<{ slug: FeatureSlug }>()
</script>

<template>
  <HeroChat v-if="slug === 'confirmation'" />
  <VisualRisk v-else-if="slug === 'risk'" />
  <VisualInbox v-else-if="slug === 'inbox'" />
  <VisualStores v-else />
</template>
