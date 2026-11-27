<script setup lang="ts">
import type { BoardOrder } from '../composables/useBoard'
import { waiting, type Stage } from '../utils/board'
import { formatMoney, riskLevel } from '../../inbox/utils/inbox'

const props = defineProps<{
  rows: BoardOrder[]
  stage: Stage
  selected: Set<string>
  allSelected: boolean
  canConfirm: boolean
  busy: string | null
  governorate: (code?: string | null) => string | null
}>()
const emit = defineEmits<{ toggle: [string]; toggleAll: []; open: [BoardOrder]; confirm: [BoardOrder] }>()
const { t, locale } = useI18n()

const someSelected = computed(() => props.selected.size > 0 && !props.allSelected)
const actionable = computed(() => ['new', 'awaiting_customer', 'needs_call'].includes(props.stage))
const riskBar = { high: 'bg-danger', medium: 'bg-[#D97706]', low: 'bg-success' } as const
const tone = { muted: 'text-muted', warning: 'text-warning font-semibold', danger: 'text-danger font-semibold' } as const

function stepLabel(o: BoardOrder) {
  if (o.next_action === 'fix_address') return { text: t('order.next.fix_address'), cls: 'bg-warning-bg text-warning' }
  if (props.stage === 'needs_call') return { text: o.needs_call?.reason ? t(`board.callReason.${o.needs_call.reason}`, o.needs_call.reason) : t('board.stage.needs_call'), cls: 'bg-danger-bg text-danger' }
  return { text: t(`order.status.${o.status}`), cls: o.status === 'confirmed' ? 'bg-ink text-white' : 'bg-indigo-soft text-indigo-strong' }
}
const city = (o: BoardOrder) => props.governorate(o.address?.governorate) ?? o.address?.area ?? o.address?.raw ?? ''
</script>

<template>
  <div class="overflow-x-auto">
    <table class="w-full min-w-[880px] text-[15px]">
      <thead>
        <tr class="border-b border-line text-start text-[13px] text-muted">
          <th scope="col" class="w-12 py-3 ps-5">
            <input
              type="checkbox"
              class="size-4 accent-indigo"
              :checked="allSelected"
              :indeterminate="someSelected"
              :disabled="!rows.length"
              :aria-label="t('board.selectAll')"
              @change="emit('toggleAll')"
            >
          </th>
          <th scope="col" class="px-3 py-3 text-start font-medium">{{ t('board.col.order') }}</th>
          <th scope="col" class="px-3 py-3 text-start font-medium">{{ t('board.col.customer') }}</th>
          <th scope="col" class="px-3 py-3 text-end font-medium">{{ t('board.col.amount') }}</th>
          <th scope="col" class="px-3 py-3 text-start font-medium">{{ t('board.col.risk') }}</th>
          <th scope="col" class="px-3 py-3 text-start font-medium">{{ t('board.col.step') }}</th>
          <th scope="col" class="px-3 py-3 text-start font-medium">{{ t('board.col.waiting') }}</th>
          <th scope="col" class="py-3 pe-5"><span class="sr-only">{{ t('board.col.actions') }}</span></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="o in rows" :key="o.id" class="border-b border-line transition-colors" :class="selected.has(o.id) ? 'bg-indigo-soft/50' : 'hover:bg-paper/70'">
          <td class="py-3 ps-5">
            <input type="checkbox" class="size-4 accent-indigo" :checked="selected.has(o.id)" :aria-label="t('board.select', { n: o.number })" @change="emit('toggle', o.id)">
          </td>
          <td class="px-3 py-3">
            <button type="button" class="font-semibold text-indigo hover:underline" dir="ltr" @click="emit('open', o)">#{{ o.number }}</button>
            <div class="text-[13px] text-muted">{{ t(`board.source.${o.source ?? 'chat'}`) }}</div>
          </td>
          <td class="px-3 py-3">
            <div class="flex items-center gap-2.5">
              <UiAvatar :id="o.contact_id" :name="o.customer?.name || '?'" size="sm" />
              <div class="min-w-0">
                <div class="truncate font-medium" dir="auto">{{ o.customer?.name || formatPhone(o.customer?.phone) }}</div>
                <div class="truncate text-[13px] text-muted" dir="auto">{{ city(o) }}</div>
              </div>
            </div>
          </td>
          <td class="px-3 py-3 text-end tabular-nums" dir="ltr">
            <div class="font-medium">{{ formatMoney(o.total, locale) }}</div>
            <div v-if="o.payment_method === 'prepaid'" class="text-[13px] text-success">{{ t('board.prepaid') }}</div>
          </td>
          <td class="px-3 py-3">
            <div v-if="o.risk_score !== null && o.risk_score !== undefined" class="flex items-center gap-2">
              <span class="w-7 font-semibold tabular-nums">{{ o.risk_score }}</span>
              <span class="h-1.5 w-16 overflow-hidden rounded-full bg-line" aria-hidden="true"><span class="block h-full rounded-full" :class="riskBar[riskLevel(o.risk_score)]" :style="{ width: `${o.risk_score}%` }" /></span>
              <span class="text-[13px] text-muted">{{ t(`board.risk.${riskLevel(o.risk_score)}`) }}</span>
            </div>
            <span v-else class="text-muted">—</span>
          </td>
          <td class="px-3 py-3">
            <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[13px] font-semibold whitespace-nowrap" :class="stepLabel(o).cls">
              <BrandMark v-if="o.status === 'confirmed'" tone="ink" :size="18" />{{ stepLabel(o).text }}
            </span>
          </td>
          <td class="px-3 py-3 whitespace-nowrap" :class="tone[waiting(o.waiting_seconds).tone]">
            {{ waiting(o.waiting_seconds).parts.map((p) => t(`board.unit.${p.unit}`, { n: p.n })).join(' ') }}
          </td>
          <td class="py-3 pe-5 text-end">
            <UiButton
              v-if="actionable && canConfirm && o.next_action !== 'fix_address'"
              size="sm"
              variant="secondary"
              :loading="busy === o.id"
              :aria-label="t('board.confirmOne', { n: o.number })"
              @click="emit('confirm', o)"
            >
              <UiIcon name="check" :size="16" />{{ t('board.confirm') }}
            </UiButton>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
