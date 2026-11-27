<script setup lang="ts">
usePageSeo('waitlist')
const { t } = useI18n()
const localePath = useLocalePath()
const route = useRoute()

const { form, errors, serverErrors, failure, sending, submit } = useWaitlist()
if (typeof route.query.email === 'string') form.email = route.query.email
// The API sends people here when a confirm or unsubscribe link is invalid or expired.
const linkExpired = computed(() => route.query.link === 'expired')

const errorFor = (f: WaitlistField) => serverErrors.value[f] ?? (errors.value[f] ? t(`waitlist.errors.${errors.value[f]}`) : '')

const fields = [
  { key: 'name' as const, type: 'text', autocomplete: 'name', dir: undefined },
  { key: 'store' as const, type: 'text', autocomplete: 'organization', dir: undefined },
  { key: 'email' as const, type: 'email', autocomplete: 'email', dir: 'ltr' },
  { key: 'phone' as const, type: 'tel', autocomplete: 'tel', dir: 'ltr' },
]

const formEl = ref<HTMLFormElement | null>(null)
async function onSubmit() {
  if (await submit()) {
    await navigateTo({ path: localePath('/waitlist/check-email'), query: { email: form.email.trim() } })
    return
  }
  // Move focus to the first field with a problem.
  await nextTick()
  formEl.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
}
</script>

<template>
  <section class="relative overflow-hidden px-5 pt-32 pb-24 sm:px-8 sm:pt-40">
    <div class="glow -start-40 top-20 size-[520px] bg-indigo/15" aria-hidden="true" />
    <div class="relative mx-auto grid max-w-7xl items-start gap-14 lg:grid-cols-[1fr_1.1fr]">
      <div class="lg:sticky lg:top-32">
        <p class="text-sm font-semibold tracking-[0.12em] text-indigo uppercase">{{ t('waitlist.eyebrow') }}</p>
        <h1 class="mt-4 font-display text-[clamp(40px,6vw,76px)] leading-[1.04] font-bold tracking-[-0.04em]">
          <SplitWords :text="t('waitlist.title')" />
        </h1>
        <p v-reveal="300" class="mt-6 max-w-lg text-xl leading-relaxed text-muted">{{ t('waitlist.sub') }}</p>
        <div v-reveal="400" class="mt-10 hidden max-w-[380px] lg:block">
          <HeroChat :tilt="false" />
        </div>
      </div>

      <form ref="formEl" v-reveal="150" novalidate class="rounded-[28px] border border-line bg-white p-6 shadow-[0_40px_100px_-60px_rgb(20_18_31/.5)] sm:p-10" @submit.prevent="onSubmit">
        <p v-if="linkExpired" role="status" class="mb-7 flex items-start gap-3 rounded-xl bg-warning-bg px-4 py-3 text-[15px] font-medium text-warning">
          <svg class="mt-0.5 size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
          {{ t('waitlist.expired') }}
        </p>
        <div class="grid gap-5 sm:grid-cols-2">
          <div v-for="f in fields" :key="f.key" class="flex flex-col gap-2">
            <label :for="`wl-${f.key}`" class="text-sm font-semibold">{{ t(`waitlist.${f.key}`) }}</label>
            <input
              :id="`wl-${f.key}`"
              v-model="form[f.key]"
              :type="f.type"
              :autocomplete="f.autocomplete"
              :dir="f.dir"
              :aria-invalid="!!errorFor(f.key)"
              :aria-describedby="errorFor(f.key) ? `wl-${f.key}-err` : undefined"
              class="h-13 rounded-xl border bg-paper px-4 text-base transition-[border-color,box-shadow] duration-300 outline-none focus:border-indigo focus:bg-white focus:shadow-[0_0_0_4px_rgb(79_59_240/.15)] rtl:text-right"
              :class="errorFor(f.key) ? 'border-danger' : 'border-line'"
            >
            <p v-if="errorFor(f.key)" :id="`wl-${f.key}-err`" class="text-sm text-danger">{{ errorFor(f.key) }}</p>
          </div>
        </div>

        <fieldset class="mt-7" :aria-describedby="errorFor('platform') ? 'wl-platform-err' : undefined">
          <legend class="text-sm font-semibold">{{ t('waitlist.platform') }}</legend>
          <div class="mt-3 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
            <label
              v-for="p in PLATFORMS"
              :key="p"
              class="relative flex h-13 cursor-pointer items-center justify-center rounded-xl border px-3 text-center text-sm font-semibold transition-all duration-300 has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-indigo/20"
              :class="form.platform === p ? 'scale-[1.02] border-indigo bg-indigo text-white' : 'border-line bg-paper hover:border-indigo/40'"
            >
              <input v-model="form.platform" type="radio" name="platform" :value="p" class="sr-only" :aria-invalid="!!errorFor('platform')">
              {{ t(`waitlist.platforms.${p}`) }}
            </label>
          </div>
          <p v-if="errorFor('platform')" id="wl-platform-err" class="mt-2 text-sm text-danger">{{ errorFor('platform') }}</p>
        </fieldset>

        <fieldset class="mt-7" :aria-describedby="errorFor('orders') ? 'wl-orders-err' : undefined">
          <legend class="text-sm font-semibold">{{ t('waitlist.orders') }}</legend>
          <div class="mt-3 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
            <label
              v-for="o in ORDER_BANDS"
              :key="o"
              class="relative flex h-13 cursor-pointer items-center justify-center rounded-xl border px-3 text-center text-sm font-semibold transition-all duration-300 has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-indigo/20"
              :class="form.orders === o ? 'scale-[1.02] border-ink bg-ink text-white' : 'border-line bg-paper hover:border-ink/30'"
            >
              <input v-model="form.orders" type="radio" name="orders" :value="o" class="sr-only" :aria-invalid="!!errorFor('orders')">
              <span dir="auto">{{ t(`waitlist.ordersOptions.${o}`) }}</span>
            </label>
          </div>
          <p v-if="errorFor('orders')" id="wl-orders-err" class="mt-2 text-sm text-danger">{{ errorFor('orders') }}</p>
        </fieldset>

        <label class="mt-7 flex cursor-pointer items-start gap-3">
          <input
            v-model="form.consent"
            type="checkbox"
            class="mt-0.5 size-5 shrink-0 accent-indigo"
            :aria-invalid="!!errorFor('consent')"
            :aria-describedby="errorFor('consent') ? 'wl-consent-err' : undefined"
          >
          <span class="text-[15px] leading-relaxed text-muted">{{ t('waitlist.consent') }}</span>
        </label>
        <p v-if="errorFor('consent')" id="wl-consent-err" class="mt-2 text-sm text-danger">{{ errorFor('consent') }}</p>

        <p v-if="failure" role="alert" class="mt-6 rounded-xl bg-danger-bg px-4 py-3 text-sm font-medium text-danger">{{ t(`waitlist.errors.${failure}`) }}</p>

        <UiButton type="submit" size="lg" class="mt-8 w-full" :disabled="sending" magnetic>
          <svg v-if="sending" class="size-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3" /><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
          {{ sending ? t('waitlist.sending') : t('waitlist.submit') }}
        </UiButton>
      </form>
    </div>
  </section>
</template>
