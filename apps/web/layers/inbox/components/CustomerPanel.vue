<script setup lang="ts">
import type { Conversation, Order } from '../composables/useInbox'

const props = defineProps<{
  conversation: Conversation
  orders: Order[] | null
  ordersHidden: boolean
  canConfirm: boolean
  busyOrder: { id: string; action: 'confirm' | 'cancel' } | null
}>()
const emit = defineEmits<{ orderAction: [Order, 'confirm' | 'cancel'] }>()
const { t } = useI18n()

const name = computed(() => props.conversation.contact?.name || formatPhone(props.conversation.contact?.phone))
const stats = computed(() => {
  const list = props.orders ?? []
  return {
    total: list.length,
    delivered: list.filter((o) => o.status === 'delivered').length,
    refused: list.filter((o) => o.status === 'returned').length,
  }
})
</script>

<template>
  <aside class="hidden w-[340px] shrink-0 overflow-y-auto border-s border-line bg-white xl:block" :aria-label="t('inbox.customer')">
    <section class="border-b border-line p-5">
      <div class="flex items-center gap-3">
        <UiAvatar :id="conversation.contact?.id" :name="name" size="lg" />
        <div class="min-w-0">
          <p class="truncate text-lg font-bold" dir="auto">{{ name }}</p>
          <p class="text-sm text-muted" dir="ltr">{{ formatPhone(conversation.contact?.phone) }}</p>
        </div>
      </div>
      <dl v-if="!ordersHidden" class="mt-5 grid grid-cols-3 gap-2 text-center">
        <div class="rounded-xl bg-paper px-2 py-3"><dd class="text-xl font-bold tabular-nums">{{ stats.total }}</dd><dt class="text-xs text-muted">{{ t('inbox.stats.orders') }}</dt></div>
        <div class="rounded-xl bg-paper px-2 py-3"><dd class="text-xl font-bold tabular-nums">{{ stats.delivered }}</dd><dt class="text-xs text-muted">{{ t('inbox.stats.delivered') }}</dt></div>
        <div class="rounded-xl px-2 py-3" :class="stats.refused ? 'bg-danger-bg text-danger' : 'bg-paper'"><dd class="text-xl font-bold tabular-nums">{{ stats.refused }}</dd><dt class="text-xs" :class="stats.refused ? '' : 'text-muted'">{{ t('inbox.stats.refused') }}</dt></div>
      </dl>
    </section>

    <section class="space-y-3 p-5" :aria-label="t('inbox.orders')">
      <h2 class="text-sm font-semibold text-muted">{{ t('inbox.orders') }}</h2>
      <p v-if="ordersHidden" class="text-[15px] text-muted">{{ t('inbox.ordersHidden') }}</p>
      <template v-else-if="orders === null">
        <UiSkeleton class="h-40 w-full" />
      </template>
      <p v-else-if="!orders.length" class="text-[15px] text-muted">{{ t('inbox.noOrders') }}</p>
      <OrderCard
        v-for="(o, i) in orders"
        :key="o.id"
        :order="o"
        :can-confirm="canConfirm"
        :compact="i > 0"
        :busy="busyOrder?.id === o.id ? busyOrder.action : null"
        @confirm="emit('orderAction', $event, 'confirm')"
        @cancel="emit('orderAction', $event, 'cancel')"
      />
    </section>
  </aside>
</template>
