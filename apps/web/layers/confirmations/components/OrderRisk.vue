<script setup lang="ts">
/** An order's refusal risk: the score, whether it passed the seller's threshold, and the top reasons. Hidden until scored. */
const props = defineProps<{ orderId: string; refreshKey?: number }>()
const { t, locale } = useI18n()

const { data } = useAsyncData(
  () => `order-risk:${props.orderId}:${props.refreshKey ?? 0}`,
  async () => {
    try {
      return (await call(useApi().GET('/orders/{order}/risk', { params: { ...inWorkspace(), path: { order: props.orderId } } }))).data ?? null
    } catch {
      return null // not scored yet, or risk isn't on for this workspace
    }
  },
  { watch: [() => props.orderId, () => props.refreshKey] },
)

const level = computed(() => {
  const s = data.value?.score ?? 0
  return data.value?.flagged ? 'high' : s >= 40 ? 'medium' : 'low'
})
const barClass = computed(() => ({ high: 'bg-danger', medium: 'bg-warning', low: 'bg-success' })[level.value])
const text = (r: { text: { ar: string; en: string } }) => (locale.value === 'ar' ? r.text.ar : r.text.en)
</script>

<template>
  <section v-if="data" :aria-label="t('orderRisk.title')">
    <div class="flex items-center justify-between gap-3">
      <h3 class="text-sm font-semibold text-muted">{{ t('orderRisk.title') }}</h3>
      <UiBadge :tone="level === 'high' ? 'danger' : level === 'medium' ? 'warning' : 'success'">{{ t(`orderRisk.level.${level}`) }}</UiBadge>
    </div>
    <div class="mt-3 flex items-center gap-3">
      <span class="text-3xl font-bold tabular-nums">{{ data.score }}</span>
      <div class="h-2 flex-1 overflow-hidden rounded-full bg-paper" role="meter" :aria-valuenow="data.score" aria-valuemin="0" aria-valuemax="100" :aria-label="t('orderRisk.title')">
        <div class="h-full rounded-full" :class="barClass" :style="{ width: `${data.score}%` }" />
      </div>
    </div>
    <p class="mt-1 text-sm text-muted">{{ data.flagged ? t('orderRisk.flagged', { n: data.threshold }) : t('orderRisk.threshold', { n: data.threshold }) }}</p>
    <ul v-if="data.top.length" class="mt-3 space-y-1.5 text-[15px]">
      <li v-for="r in data.top" :key="r.code" class="flex items-start justify-between gap-3">
        <span><bdi>{{ text(r) }}</bdi></span>
        <span class="shrink-0 font-semibold tabular-nums" :class="r.points > 0 ? 'text-danger' : 'text-success'" dir="ltr">{{ r.points > 0 ? '+' : '' }}{{ r.points }}</span>
      </li>
    </ul>
  </section>
</template>
