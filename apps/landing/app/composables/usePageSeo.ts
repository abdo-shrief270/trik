type SeoKey = 'home' | 'features' | 'how' | 'pricing'

/** Title, description and social tags for a page, in the current language. */
export function usePageSeo(key: SeoKey, override?: { title?: string; description?: string }) {
  const { t, locale } = useI18n()
  const title = computed(() => override?.title ?? t(`meta.${key}.title`))
  const description = computed(() => override?.description ?? t(`meta.${key}.description`))
  useSeoMeta({
    title,
    description,
    ogTitle: title,
    ogDescription: description,
    ogType: 'website',
    ogSiteName: 'Trik',
    ogLocale: computed(() => (locale.value === 'ar' ? 'ar_EG' : 'en_US')),
    twitterCard: 'summary_large_image',
  })
}
