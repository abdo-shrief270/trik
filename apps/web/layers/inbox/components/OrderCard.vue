<script setup lang="ts">
import type { Order } from '../composables/useInbox'
import { formatMoney, orderTone, riskLevel } from '../utils/inbox'

const props = defineProps<{ order: Order; canConfirm: boolean; busy?: 'confirm' | 'cancel' | null; compact?: boolean }>()
const emit = defineEmits<{ confirm: [Order]; cancel: [Order] }>()
const { t, locale } = useI18n()

const money = (m: Order['total'] | undefined) => formatMoney(m, locale.value)
const risk = computed(() => (props.order.risk_score ?? null) as number | null)
const open = computed(() => ['new', 'confirming', 'awaiting_deposit'].includes(props.order.status))
const address = computed(() => {
  const a = props.order.address
  if (!a) return null
  const parts = [a.street, a.building && `${t('order.building')} ${a.building}`, a.floor && `${t('order.floor')} ${a.floor}`, a.area].filter(Boolean)
  return parts.length ? parts.join('، ') : a.raw
})
</script>

<template>
  <article class="rounded-2xl border border-line bg-white p-5" :aria-label="t('order.title', { n: order.number })">
    <header class="flex flex-wrap items-center justify-between gap-2">
      <h3 class="font-bold" dir="ltr">#{{ order.number }}</h3>
      <UiBadge :tone="orderTone(order.status)">
        <BrandMark v-if="order.status === 'confirmed'" tone="ink" :size="18" />{{ t(`order.status.${order.status}`) }}
      </UiBadge>
    </header>

    <p v-if="order.next_action === 'fix_address'" class="mt-3 flex items-start gap-2 rounded-lg bg-warning-bg px-3 py-2 text-sm text-warning">
      <UiIcon name="alert" :size="16" class="mt-0.5" />{{ t('order.next.fix_address') }}
    </p>

    <ul v-if="!compact && order.items?.length" class="mt-4 space-y-2.5">
      <li v-for="(item, i) in order.items" :key="i" class="flex items-start justify-between gap-3 text-[15px]">
        <span class="min-w-0">
          <span class="block font-medium" dir="auto">{{ item.title }}</span>
          <span class="text-sm text-muted"><template v-if="item.variant">{{ item.variant }} · </template>×{{ item.quantity }}</span>
        </span>
        <span class="shrink-0 tabular-nums" dir="ltr">{{ money(item.total) }}</span>
      </li>
    </ul>

    <dl class="mt-4 space-y-1.5 border-t border-line pt-3 text-[15px]">
      <div class="flex justify-between"><dt class="text-muted">{{ t('order.total') }}</dt><dd class="font-semibold tabular-nums" dir="ltr">{{ money(order.total) }}</dd></div>
      <div v-if="order.payment_method === 'cod'" class="flex justify-between"><dt class="text-muted">{{ t('order.cod') }}</dt><dd class="tabular-nums" dir="ltr">{{ money(order.cod_amount) }}</dd></div>
    </dl>

    <div v-if="risk !== null" class="mt-4">
      <div class="flex items-baseline justify-between text-[15px]">
        <span class="text-muted">{{ t('order.risk') }}</span>
        <span class="font-bold tabular-nums" :class="{ high: 'text-danger', medium: 'text-warning', low: 'text-success' }[riskLevel(risk)]">{{ risk }} / 100</span>
      </div>
      <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-line" role="meter" :aria-valuenow="risk" aria-valuemin="0" aria-valuemax="100" :aria-label="t('order.risk')">
        <div class="h-full rounded-full" :class="{ high: 'bg-danger', medium: 'bg-[#D97706]', low: 'bg-success' }[riskLevel(risk)]" :style="{ width: `${risk}%` }" />
      </div>
    </div>

    <div v-if="!compact && address" class="mt-4 border-t border-line pt-3 text-[15px]">
      <p class="text-sm text-muted">{{ t('order.address') }}</p>
      <p class="mt-1" dir="auto">{{ address }}</p>
    </div>

    <div v-if="canConfirm && open" class="mt-5 flex gap-2">
      <UiButton size="sm" class="flex-1" :loading="busy === 'confirm'" :disabled="!!busy" @click="emit('confirm', order)">{{ t('order.confirm') }}</UiButton>
      <UiButton size="sm" variant="secondary" :loading="busy === 'cancel'" :disabled="!!busy" @click="emit('cancel', order)">{{ t('order.cancel') }}</UiButton>
    </div>
  </article>
</template>
