<script setup lang="ts">
/** The site's main call to action: opens a WhatsApp chat with Trik (same look as UiButton). */
const props = withDefaults(
  defineProps<{
    context?: string
    variant?: 'primary' | 'dark' | 'light' | 'ghost' | 'ghost-dark'
    size?: 'md' | 'lg'
    magnetic?: boolean
    arrow?: boolean
  }>(),
  { context: undefined, variant: 'primary', size: 'md', magnetic: false, arrow: false },
)

const { t } = useI18n()
const { startLink } = useStart()
const link = computed(() => startLink(props.context))
</script>

<template>
  <UiButton
    :to="link.to"
    :variant="variant"
    :size="size"
    :magnetic="magnetic"
    :arrow="arrow"
    :target="link.external ? '_blank' : undefined"
    :rel="link.external ? 'noopener' : undefined"
  >
    <slot>{{ t('cta.start') }}</slot>
  </UiButton>
</template>
