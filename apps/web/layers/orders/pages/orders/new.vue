<script setup lang="ts">
import { addressBody, addressLooksComplete, draftTotals, emptyAddress, parsePounds, validateDraft, type DraftErrors, type DraftItem } from '../../utils/orders'
import { formatMoney } from '../../../inbox/utils/inbox'

// A new order typed by an agent (from a call or a chat). Needs orders.orders.create.
const { t, locale } = useI18n()
const router = useRouter()
const route = useRoute()
const toast = useToast()
const { can } = usePermissions()
const governorates = useGovernorates()
useHead({ title: () => `${t('create.title')} · ${t('brand.name')}` })

const phone = ref(typeof route.query.phone === 'string' ? route.query.phone : '')
const name = ref(typeof route.query.name === 'string' ? route.query.name : '')
const items = ref<DraftItem[]>([{ title: '', variant: '', quantity: '1', price: '' }])
const shipping = ref('')
const discount = ref('')
const method = ref<'cod' | 'prepaid'>('cod')
const note = ref('')
const address = ref(emptyAddress())

const errors = ref<DraftErrors>({})
const tried = ref(false)
const saving = ref(false)
const serverError = ref('')

// One key per draft: a retried submit (timeout, double click) can't create the order twice.
// Any edit makes it a different order, so it gets a new key.
let key = crypto.randomUUID()
watch([phone, name, items, shipping, discount, method, note, address], () => {
  key = crypto.randomUUID()
  if (tried.value) errors.value = validateDraft({ phone: phone.value, items: items.value, shipping: shipping.value, discount: discount.value })
}, { deep: true })

const totals = computed(() => draftTotals(items.value, shipping.value, discount.value, method.value))
const money = (amount: number) => formatMoney({ amount, currency: 'EGP' }, locale.value)
const err = (k: keyof DraftErrors) => (errors.value[k] ? t(`create.err.${errors.value[k]}`) : undefined)

function addItem() {
  items.value.push({ title: '', variant: '', quantity: '1', price: '' })
}
function removeItem(i: number) {
  items.value.splice(i, 1)
}

async function submit() {
  tried.value = true
  serverError.value = ''
  errors.value = validateDraft({ phone: phone.value, items: items.value, shipping: shipping.value, discount: discount.value })
  if (Object.keys(errors.value).length) return
  saving.value = true
  const a = addressBody(address.value)
  const hasAddress = Object.values(a).some((v) => v !== null)
  try {
    const res = await call(useApi().POST('/orders', {
      params: { header: { ...inWorkspace().header, 'Idempotency-Key': key } },
      body: {
        customer_phone: phone.value.replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660)).replace(/[^\d+]/g, ''),
        customer_name: name.value.trim() || null,
        items: items.value.map((i) => ({ title: i.title.trim(), variant: i.variant.trim() || null, quantity: Number(i.quantity), unit_price: parsePounds(i.price) ?? 0 })),
        shipping: totals.value.shipping,
        discount: totals.value.discount,
        payment_method: method.value,
        note: note.value.trim() || null,
        ...(hasAddress ? { address: a } : {}),
      },
    }))
    toast.success(t('create.done', { n: res.data?.number ?? '' }))
    await router.push(res.data ? `/orders/${res.data.id}` : '/orders')
  } catch (e) {
    if (e instanceof ApiError) {
      const field = Object.keys(e.problem.errors ?? {})[0]
      serverError.value = field?.startsWith('customer_phone') ? t('create.err.phone') : t(problemMessageKey(e.problem))
    } else {
      serverError.value = t('errors.generic')
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="mx-auto w-full max-w-5xl px-8 py-8">
    <NuxtLink to="/orders" class="inline-flex items-center gap-1.5 text-sm font-semibold text-indigo"><UiIcon name="chevronRight" :size="16" class="rotate-180 rtl:rotate-0" />{{ t('nav.orders') }}</NuxtLink>
    <h1 class="mt-4 font-display text-4xl font-bold tracking-[-0.03em]">{{ t('create.title') }}</h1>
    <p class="mt-2 text-muted">{{ t('create.sub') }}</p>

    <UiEmptyState v-if="!can('orders.orders.create')" icon="orders" :title="t('create.denied')" />

    <form v-else class="mt-8 grid gap-6 lg:grid-cols-[1.5fr_1fr]" novalidate @submit.prevent="submit">
      <div class="space-y-6">
        <section class="rounded-2xl border border-line bg-white p-6">
          <h2 class="text-lg font-bold">{{ t('inbox.customer') }}</h2>
          <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <UiInput v-model="phone" type="tel" dir="ltr" autocomplete="off" required :label="t('create.phone')" :hint="t('create.phoneHint')" :error="err('phone')" />
            <UiInput v-model="name" dir="auto" :label="t('create.name')" />
          </div>
        </section>

        <section class="rounded-2xl border border-line bg-white p-6" :aria-label="t('orders.items')">
          <h2 class="text-lg font-bold">{{ t('orders.items') }}</h2>
          <ul class="mt-4 space-y-4">
            <li v-for="(item, i) in items" :key="i" class="grid gap-3 rounded-xl bg-paper p-4 sm:grid-cols-[minmax(0,1.6fr)_minmax(0,1.2fr)_4.5rem_7.5rem_2.25rem] sm:items-start">
              <UiInput v-model="item.title" dir="auto" required :label="t('orders.item')" :error="err(`item.${i}.title`)" />
              <UiInput v-model="item.variant" dir="auto" :label="t('create.variant')" />
              <UiInput v-model="item.quantity" dir="ltr" required :label="t('orders.qty')" :error="err(`item.${i}.quantity`)" />
              <UiInput v-model="item.price" dir="ltr" required :label="t('create.unitPrice')" :error="err(`item.${i}.price`)" />
              <UiButton v-if="items.length > 1" variant="ghost" size="sm" class="sm:mt-7 sm:px-0" :aria-label="t('create.removeItem', { n: i + 1 })" @click="removeItem(i)"><UiIcon name="x" :size="16" /></UiButton>
            </li>
          </ul>
          <UiButton variant="secondary" size="sm" class="mt-4" :disabled="items.length >= 100" @click="addItem"><UiIcon name="plus" :size="16" />{{ t('create.addItem') }}</UiButton>
        </section>

        <section class="rounded-2xl border border-line bg-white p-6">
          <h2 class="text-lg font-bold">{{ t('order.address') }} <span class="text-sm font-normal text-muted">{{ t('create.optional') }}</span></h2>
          <div class="mt-4 space-y-3">
            <UiSelect v-model="address.governorate" :label="t('board.governorate')" :options="[{ value: '', label: t('orders.chooseGovernorate') }, ...governorates.options.value]" />
            <div class="grid grid-cols-2 gap-3">
              <UiInput v-model="address.area" dir="auto" :label="t('orders.area')" />
              <UiInput v-model="address.street" dir="auto" :label="t('orders.street')" />
            </div>
            <div class="grid grid-cols-3 gap-3">
              <UiInput v-model="address.building" dir="auto" :label="t('order.building')" />
              <UiInput v-model="address.floor" dir="auto" :label="t('order.floor')" />
              <UiInput v-model="address.apartment" dir="auto" :label="t('orders.apartment')" />
            </div>
            <UiInput v-model="address.landmark" dir="auto" :label="t('orders.landmark')" />
            <p v-if="tried && !addressLooksComplete(address)" class="text-sm text-warning">{{ t('create.addressLater') }}</p>
          </div>
        </section>
      </div>

      <aside class="space-y-6 lg:sticky lg:top-6 lg:self-start">
        <section class="rounded-2xl border border-line bg-white p-6">
          <h2 class="text-lg font-bold">{{ t('create.payment') }}</h2>
          <fieldset class="mt-4 grid grid-cols-2 gap-2">
            <legend class="sr-only">{{ t('create.payment') }}</legend>
            <label v-for="m in (['cod', 'prepaid'] as const)" :key="m" class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2.5 text-[15px]" :class="method === m ? 'border-indigo bg-indigo/5 font-semibold' : 'border-line'">
              <input v-model="method" type="radio" :value="m" class="accent-indigo">{{ m === 'cod' ? t('order.cod') : t('board.prepaid') }}
            </label>
          </fieldset>
          <div class="mt-4 grid grid-cols-2 gap-3">
            <UiInput v-model="shipping" dir="ltr" :label="t('orders.shipping')" :error="err('shipping')" />
            <UiInput v-model="discount" dir="ltr" :label="t('orders.discount')" :error="err('discount')" />
          </div>
          <dl class="mt-5 space-y-1.5 border-t border-line pt-4 text-[15px]">
            <div class="flex justify-between"><dt class="text-muted">{{ t('orders.subtotal') }}</dt><dd class="tabular-nums" dir="ltr">{{ money(totals.subtotal) }}</dd></div>
            <div class="flex justify-between text-lg font-bold"><dt>{{ t('order.total') }}</dt><dd class="tabular-nums" dir="ltr">{{ money(totals.total) }}</dd></div>
            <div class="flex justify-between"><dt class="text-muted">{{ t('order.cod') }}</dt><dd class="tabular-nums" dir="ltr">{{ money(totals.cod) }}</dd></div>
          </dl>
        </section>

        <section class="rounded-2xl border border-line bg-white p-6">
          <label for="new-note" class="text-lg font-bold">{{ t('orders.note') }}</label>
          <textarea id="new-note" v-model="note" rows="3" maxlength="2000" dir="auto" class="mt-3 w-full rounded-lg border border-line px-3.5 py-2.5 text-[15px] outline-none focus:border-indigo" />
        </section>

        <p v-if="serverError" role="alert" class="rounded-xl bg-danger/10 px-4 py-3 text-[15px] text-danger">{{ serverError }}</p>
        <p v-else-if="tried && Object.keys(errors).length" role="alert" class="text-[15px] text-danger">{{ t('create.fixErrors') }}</p>
        <UiButton type="submit" class="w-full" :loading="saving">{{ t('create.submit') }}</UiButton>
      </aside>
    </form>
  </div>
</template>
