<script setup lang="ts">
import type { components } from '~/types/api'
import { addressBody, addressForm, addressLooksComplete, type AddressForm } from '../../utils/orders'
import { formatMoney, orderTone } from '../../../inbox/utils/inbox'

type Order = components['schemas']['Order']

const { t, locale } = useI18n()
const route = useRoute()
const toast = useToast()
const session = useSessionStore()
const { can } = usePermissions()
const governorates = useGovernorates()
const id = computed(() => String(route.params.id))

const { data: order, status, error, refresh } = useAsyncData(
  () => `order:${id.value}`,
  async () => (await call(useApi().GET('/orders/{order}', { params: { ...inWorkspace(), path: { order: id.value } } }))).data ?? null,
  { watch: [id, () => session.workspaceId] },
)
useHead({ title: () => `${order.value ? `#${order.value.number}` : t('nav.orders')} · ${t('brand.name')}` })

const { data: members } = useAsyncData(
  () => `members:${session.workspaceId}`,
  async () => {
    try {
      return (await call(useApi().GET('/workspace/members', { params: inWorkspace() }))).data ?? []
    } catch {
      return []
    }
  },
  { default: () => [] },
)

const canUpdate = computed(() => can('orders.orders.update'))
const canConfirm = computed(() => can('orders.orders.confirm'))
const isOpen = computed(() => !!order.value && ['new', 'confirming', 'awaiting_deposit'].includes(order.value.status))
const activityKey = ref(0)
const money = (m?: Order['total']) => formatMoney(m, locale.value)

function explain(e: unknown) {
  if (!(e instanceof ApiError)) return t('errors.generic')
  for (const k of [`orders.errors.${e.code}`, `inbox.errors.${e.code}`]) if (t(k) !== k) return t(k)
  return t(problemMessageKey(e.problem))
}

/** PATCH /orders/{order} and show the result. */
async function patch(body: components['schemas']['Address'] extends never ? never : Record<string, unknown>, done: string) {
  try {
    const res = await call(useApi().PATCH('/orders/{order}', { params: { ...inWorkspace(), path: { order: id.value } }, body }))
    if (res.data) order.value = res.data
    activityKey.value++
    toast.success(done)
    return true
  } catch (e) {
    toast.error(explain(e))
    return false
  }
}

// Address
const editing = ref(false)
const address = ref<AddressForm>(addressForm(null))
const savingAddress = ref(false)
function startEdit() {
  address.value = addressForm(order.value?.address)
  editing.value = true
}
async function saveAddress() {
  savingAddress.value = true
  // A fixed, complete address clears the "needs fix" flag (the API records address_fixed).
  const body: Record<string, unknown> = { address: addressBody(address.value) }
  if (order.value?.address?.needs_fix && addressLooksComplete(address.value)) body.address_needs_fix = false
  if (await patch(body, t('orders.addressSaved'))) editing.value = false
  savingAddress.value = false
}
const addressLines = computed(() => {
  const a = order.value?.address
  if (!a) return []
  return [
    a.line || [a.street, a.building && `${t('order.building')} ${a.building}`, a.floor && `${t('order.floor')} ${a.floor}`, a.apartment && `${t('orders.apartment')} ${a.apartment}`].filter(Boolean).join('، '),
    [a.area, governorates.nameOf(a.governorate)].filter(Boolean).join('، '),
    a.landmark ? `${t('orders.landmark')}: ${a.landmark}` : '',
  ].filter(Boolean)
})

// Note
const note = ref('')
watch(() => order.value?.note, (n) => (note.value = n ?? ''), { immediate: true })
const savingNote = ref(false)
async function saveNote() {
  savingNote.value = true
  await patch({ note: note.value.trim() || null }, t('orders.noteSaved'))
  savingNote.value = false
}

// Owner
const owner = computed({
  get: () => order.value?.owner_id ?? '',
  set: (v: string) => void patch({ owner_id: v || null }, v ? t('orders.ownerSet') : t('orders.ownerCleared')),
})
const ownerOptions = computed(() => [{ value: '', label: t('board.ownerNone') }, ...members.value.map((m) => ({ value: m.user_id ?? '', label: m.user_id === session.user?.id ? t('inbox.youName') : m.name ?? '' }))])

// Confirm / cancel
const busy = ref<'confirm' | 'cancel' | null>(null)
const cancelOpen = ref(false)
const reason = ref('')
async function act(action: 'confirm' | 'cancel') {
  if (!order.value) return
  busy.value = action
  cancelOpen.value = false
  try {
    const p = { ...inWorkspace(), path: { order: order.value.id } }
    const res = action === 'confirm'
      ? await call(useApi().POST('/orders/{order}/confirm', { params: p }))
      : await call(useApi().POST('/orders/{order}/cancel', { params: p, body: reason.value.trim() ? { reason: reason.value.trim() } : {} }))
    if ((res as { data?: Order }).data) order.value = (res as { data: Order }).data
    activityKey.value++
    toast.success(t(action === 'confirm' ? 'order.confirmedDone' : 'order.cancelledDone', { n: order.value.number }))
  } catch (e) {
    toast.error(explain(e))
  } finally {
    busy.value = null
    reason.value = ''
  }
}
</script>

<template>
  <div class="mx-auto w-full max-w-6xl px-8 py-8">
    <NuxtLink to="/orders" class="inline-flex items-center gap-1.5 text-sm font-semibold text-indigo"><UiIcon name="chevronRight" :size="16" class="rotate-180 rtl:rotate-0" />{{ t('nav.orders') }}</NuxtLink>

    <UiSkeleton v-if="status === 'pending' && !order" class="mt-6 h-64 w-full" />
    <UiEmptyState v-else-if="error || !order" icon="orders" :title="t('orders.notFound')" :body="error ? t(problemMessageKey((error as ApiError).problem ?? { status: 0, code: 'network' })) : ''">
      <UiButton to="/orders" variant="secondary" size="sm">{{ t('orders.back') }}</UiButton>
    </UiEmptyState>

    <template v-else>
      <!-- Header -->
      <header class="mt-4 flex flex-wrap items-center gap-3">
        <h1 class="font-display text-4xl font-bold tracking-[-0.03em]" dir="ltr">#{{ order.number }}</h1>
        <UiBadge :tone="orderTone(order.status)"><BrandMark v-if="order.status === 'confirmed'" tone="ink" :size="18" />{{ t(`order.status.${order.status}`) }}</UiBadge>
        <span class="text-muted">{{ t(`board.source.${order.source ?? 'chat'}`) }} · {{ shortTime(order.placed_at, locale) }} {{ clockTime(order.placed_at, locale) }}</span>
        <span class="flex-1" />
        <template v-if="canConfirm && isOpen">
          <UiButton :loading="busy === 'confirm'" :disabled="!!busy" @click="act('confirm')"><UiIcon name="check" :size="18" />{{ t('order.confirm') }}</UiButton>
          <UiButton variant="secondary" class="!text-danger" :disabled="!!busy" @click="cancelOpen = true">{{ t('order.cancel') }}</UiButton>
        </template>
      </header>

      <p v-if="order.address?.needs_fix" class="mt-5 flex items-start gap-2 rounded-xl bg-warning-bg px-4 py-3 text-[15px] text-warning">
        <UiIcon name="alert" :size="18" class="mt-0.5" />{{ t('orders.fixBanner') }}
      </p>

      <div class="mt-6 grid gap-6 lg:grid-cols-[1.5fr_1fr]">
        <div class="space-y-6">
          <!-- Items -->
          <section class="rounded-2xl border border-line bg-white" :aria-label="t('orders.items')">
            <h2 class="px-6 pt-5 text-lg font-bold">{{ t('orders.items') }}</h2>
            <table class="mt-3 w-full text-[15px]">
              <thead><tr class="border-y border-line text-[13px] text-muted"><th scope="col" class="px-6 py-2.5 text-start font-medium">{{ t('orders.item') }}</th><th scope="col" class="px-3 py-2.5 text-end font-medium">{{ t('orders.qty') }}</th><th scope="col" class="px-3 py-2.5 text-end font-medium">{{ t('orders.price') }}</th><th scope="col" class="px-6 py-2.5 text-end font-medium">{{ t('order.total') }}</th></tr></thead>
              <tbody>
                <tr v-for="(i, n) in order.items" :key="n" class="border-b border-line">
                  <td class="px-6 py-3"><div class="font-medium" dir="auto">{{ i.title }}</div><div v-if="i.variant || i.sku" class="text-[13px] text-muted">{{ [i.variant, i.sku].filter(Boolean).join(' · ') }}</div></td>
                  <td class="px-3 py-3 text-end tabular-nums">{{ i.quantity }}</td>
                  <td class="px-3 py-3 text-end tabular-nums" dir="ltr">{{ money(i.unit_price) }}</td>
                  <td class="px-6 py-3 text-end font-medium tabular-nums" dir="ltr">{{ money(i.total) }}</td>
                </tr>
              </tbody>
            </table>
            <dl class="space-y-1.5 px-6 py-4 text-[15px]">
              <div class="flex justify-between"><dt class="text-muted">{{ t('orders.subtotal') }}</dt><dd class="tabular-nums" dir="ltr">{{ money(order.subtotal) }}</dd></div>
              <div class="flex justify-between"><dt class="text-muted">{{ t('orders.shipping') }}</dt><dd class="tabular-nums" dir="ltr">{{ money(order.shipping) }}</dd></div>
              <div v-if="order.discount?.amount" class="flex justify-between"><dt class="text-muted">{{ t('orders.discount') }}</dt><dd class="tabular-nums" dir="ltr">−{{ money(order.discount) }}</dd></div>
              <div class="flex justify-between border-t border-line pt-2 text-lg font-bold"><dt>{{ t('order.total') }}</dt><dd class="tabular-nums" dir="ltr">{{ money(order.total) }}</dd></div>
              <div class="flex justify-between"><dt class="text-muted">{{ order.payment_method === 'cod' ? t('order.cod') : t('board.prepaid') }}</dt><dd class="tabular-nums" dir="ltr">{{ money(order.cod_amount) }}</dd></div>
            </dl>
          </section>

          <!-- Note -->
          <section class="rounded-2xl border border-line bg-white p-6" :aria-label="t('orders.note')">
            <label for="order-note" class="text-lg font-bold">{{ t('orders.note') }}</label>
            <p class="mt-1 text-sm text-muted">{{ t('orders.noteHint') }}</p>
            <textarea id="order-note" v-model="note" rows="3" maxlength="2000" dir="auto" :disabled="!canUpdate" class="mt-3 w-full rounded-lg border border-line px-3.5 py-2.5 text-[15px] outline-none focus:border-indigo disabled:bg-paper" />
            <div v-if="canUpdate" class="mt-3 flex justify-end">
              <UiButton size="sm" variant="secondary" :loading="savingNote" :disabled="note === (order.note ?? '')" @click="saveNote">{{ t('orders.saveNote') }}</UiButton>
            </div>
          </section>
        </div>

        <div class="space-y-6">
          <!-- Customer -->
          <section class="rounded-2xl border border-line bg-white p-6" :aria-label="t('inbox.customer')">
            <div class="flex items-center gap-3">
              <UiAvatar :id="order.contact_id" :name="order.customer?.name || '?'" size="lg" />
              <div class="min-w-0">
                <p class="truncate text-lg font-bold" dir="auto">{{ order.customer?.name || formatPhone(order.customer?.phone) }}</p>
                <p class="text-sm text-muted" dir="ltr">{{ formatPhone(order.customer?.phone) }}</p>
              </div>
            </div>
            <div class="mt-4"><UiSelect v-model="owner" :label="t('orders.owner')" :options="ownerOptions" :class="{ 'pointer-events-none opacity-60': !canUpdate }" /></div>
          </section>

          <!-- Address -->
          <section class="rounded-2xl border border-line bg-white p-6" :aria-label="t('order.address')">
            <div class="flex items-center justify-between gap-3">
              <h2 class="text-lg font-bold">{{ t('order.address') }}</h2>
              <UiBadge v-if="order.address?.needs_fix" tone="warning">{{ t('orders.fixAddress') }}</UiBadge>
              <UiBadge v-else-if="order.address?.fixed_at" tone="success">{{ t('orders.fixed') }}</UiBadge>
            </div>

            <form v-if="editing" class="mt-4 space-y-3" @submit.prevent="saveAddress">
              <UiSelect v-model="address.governorate" :label="t('board.governorate')" :options="[{ value: '', label: t('orders.chooseGovernorate') }, ...governorates.options.value]" />
              <div class="grid grid-cols-2 gap-3">
                <UiInput v-model="address.area" :label="t('orders.area')" />
                <UiInput v-model="address.street" :label="t('orders.street')" />
              </div>
              <div class="grid grid-cols-3 gap-3">
                <UiInput v-model="address.building" :label="t('order.building')" />
                <UiInput v-model="address.floor" :label="t('order.floor')" />
                <UiInput v-model="address.apartment" :label="t('orders.apartment')" />
              </div>
              <UiInput v-model="address.landmark" :label="t('orders.landmark')" />
              <UiInput v-model="address.raw" :label="t('orders.raw')" :hint="t('orders.rawHint')" />
              <div class="flex justify-end gap-2 pt-1">
                <UiButton variant="ghost" size="sm" @click="editing = false">{{ t('orders.discard') }}</UiButton>
                <UiButton type="submit" size="sm" :loading="savingAddress">{{ t('orders.saveAddress') }}</UiButton>
              </div>
            </form>
            <template v-else>
              <div v-if="addressLines.length" class="mt-3 space-y-0.5 text-[15px]">
                <p v-for="(l, i) in addressLines" :key="i" :class="i ? 'text-muted' : ''" dir="auto">{{ l }}</p>
              </div>
              <p v-else class="mt-3 text-muted">{{ t('orders.noAddress') }}</p>
              <p v-if="order.address?.raw && order.address?.line" class="mt-3 rounded-lg bg-paper px-3 py-2 text-sm text-muted" dir="auto">{{ t('orders.asWritten') }}: {{ order.address.raw }}</p>
              <div v-if="canUpdate" class="mt-4 flex flex-wrap gap-2">
                <UiButton size="sm" variant="secondary" @click="startEdit"><UiIcon name="rules" :size="15" />{{ t('orders.editAddress') }}</UiButton>
                <UiButton v-if="!order.address?.needs_fix" size="sm" variant="ghost" @click="patch({ address_needs_fix: true }, t('orders.flagged'))">{{ t('orders.flag') }}</UiButton>
                <UiButton v-else size="sm" variant="ghost" @click="patch({ address_needs_fix: false }, t('orders.unflagged'))">{{ t('orders.unflag') }}</UiButton>
              </div>
            </template>
          </section>

          <section class="rounded-2xl border border-line bg-white p-6">
            <OrderActivity :order-id="order.id" :refresh-key="activityKey" />
          </section>
        </div>
      </div>

      <UiModal v-model="cancelOpen" size="sm" :title="t('order.cancelTitle', { n: order.number })">
        <p class="text-[15px] leading-relaxed text-muted">{{ t('order.cancelBody') }}</p>
        <div class="mt-4"><UiInput v-model="reason" :label="t('board.reason')" :hint="t('board.reasonHint')" /></div>
        <template #footer>
          <UiButton variant="secondary" @click="cancelOpen = false">{{ t('order.keep') }}</UiButton>
          <UiButton variant="danger" @click="act('cancel')">{{ t('order.cancelYes') }}</UiButton>
        </template>
      </UiModal>
    </template>
  </div>
</template>
