<script setup lang="ts">
import type { BoardOrder } from '../composables/useBoard'

/** One order from the board: the card, where its WhatsApp confirmation stands, and its timeline. */
const props = defineProps<{ order: BoardOrder | null; canConfirm: boolean; busy: 'confirm' | 'cancel' | null }>()
const emit = defineEmits<{ close: []; confirm: [BoardOrder]; cancel: [BoardOrder] }>()
const { t } = useI18n()

const open = computed({ get: () => !!props.order, set: (v) => !v && emit('close') })
</script>

<template>
  <UiModal v-model="open" side :title="order ? t('order.title', { n: `#${order.number}` }) : ''">
    <template v-if="order">
      <OrderCard :order="order" :can-confirm="canConfirm" :busy="busy" @confirm="emit('confirm', order)" @cancel="emit('cancel', order)" />
      <UiButton :to="`/orders/${order.id}`" variant="ghost" size="sm" class="mt-3">{{ t('orders.openPage') }}<UiIcon name="chevronRight" :size="16" class="rtl:rotate-180" /></UiButton>
      <OrderRisk :order-id="order.id" class="mt-6 rounded-xl border border-line p-4" />
      <OrderActivity :order-id="order.id" class="mt-6" />
    </template>
  </UiModal>
</template>
