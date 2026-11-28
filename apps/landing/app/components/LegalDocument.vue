<script setup lang="ts">
/**
 * A legal page (privacy policy, terms) from the copy in i18n/locales: `legal.<doc>.sections`, each a title with
 * paragraphs and bullet items. The version and date change whenever the text does (#175).
 */
const props = defineProps<{ doc: 'privacy' | 'terms'; updated: string; version: string }>()

interface Section { title: string; paragraphs: string[]; items: string[] }

const { t, locale } = useI18n()
const { list } = useCopy()
const sections = computed(() => list<Section>(`legal.${props.doc}.sections`))
const date = computed(() =>
  new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { dateStyle: 'long', timeZone: 'UTC' }).format(new Date(`${props.updated}T00:00:00Z`)),
)
const anchor = (i: number) => `section-${i + 1}`
const { startLink } = useStart()
const contact = computed(() => startLink())
</script>

<template>
  <div>
    <section class="px-5 pt-36 pb-10 sm:px-8 sm:pt-44">
      <div class="mx-auto max-w-4xl">
        <p class="text-sm font-semibold tracking-[0.12em] text-indigo uppercase">{{ t(`legal.${doc}.eyebrow`) }}</p>
        <h1 class="mt-4 font-display text-[clamp(40px,6vw,72px)] leading-[1.05] font-bold tracking-[-0.04em]">{{ t(`legal.${doc}.title`) }}</h1>
        <p class="mt-6 max-w-2xl text-xl leading-relaxed text-muted">{{ t(`legal.${doc}.intro`) }}</p>
        <p class="mt-6 text-sm text-muted">
          <time :datetime="updated">{{ t('legal.updated', { date }) }}</time>
          <span aria-hidden="true"> · </span>
          <span>{{ t('legal.version', { v: version }) }}</span>
        </p>
      </div>
    </section>

    <section class="px-5 pb-24 sm:px-8">
      <div class="mx-auto grid max-w-4xl gap-12 lg:max-w-6xl lg:grid-cols-[240px_1fr]">
        <!-- the contents sit beside the text on wide screens; on phones they'd push it off the first screen -->
        <nav :aria-label="t('legal.contents')" class="hidden lg:sticky lg:top-28 lg:block lg:self-start">
          <h2 class="text-sm font-semibold text-muted">{{ t('legal.contents') }}</h2>
          <ol class="mt-4 space-y-2 text-[15px]">
            <li v-for="(s, i) in sections" :key="i">
              <a :href="`#${anchor(i)}`" class="text-muted transition-colors hover:text-ink">{{ s.title }}</a>
            </li>
          </ol>
        </nav>

        <article class="max-w-3xl">
          <section v-for="(s, i) in sections" :id="anchor(i)" :key="i" class="scroll-mt-28 border-t border-line py-8 first:border-t-0 first:pt-0">
            <h2 class="font-display text-2xl font-bold tracking-[-0.02em]">{{ s.title }}</h2>
            <p v-for="(p, j) in s.paragraphs" :key="`p${j}`" class="mt-4 text-lg leading-relaxed">{{ p }}</p>
            <ul v-if="s.items.length" class="mt-4 list-disc space-y-3 ps-6 text-lg leading-relaxed marker:text-indigo">
              <li v-for="(item, j) in s.items" :key="`i${j}`">{{ item }}</li>
            </ul>
          </section>

          <aside class="mt-8 rounded-3xl bg-paper-2 p-8">
            <h2 class="font-display text-2xl font-bold">{{ t('legal.questions') }}</h2>
            <p class="mt-2 text-lg text-muted">{{ t('legal.questionsBody') }}</p>
            <UiButton :to="contact.to" :target="contact.external ? '_blank' : undefined" :rel="contact.external ? 'noopener' : undefined" class="mt-6">
              {{ t('legal.ask') }}
            </UiButton>
          </aside>
        </article>
      </div>
    </section>
  </div>
</template>
