/**
 * Where "Start on WhatsApp" goes: a chat with Trik's business number (NUXT_PUBLIC_WHATSAPP_NUMBER), with a
 * prefilled message in the visitor's language. `context` (a plan name, a feature) is added to the message.
 * Until a number is configured, the buttons fall back to How it works, so they never lead nowhere.
 */
export function useStart() {
  const { t } = useI18n()
  const localePath = useLocalePath()
  const config = useRuntimeConfig()

  function startLink(context?: string): { to: string; external: boolean } {
    const text = context ? t('whatsapp.messageAbout', { topic: context }) : t('whatsapp.message')
    const link = whatsappLink(config.public.whatsappNumber, text)

    return link ? { to: link, external: true } : { to: localePath('/how-it-works'), external: false }
  }

  return { startLink }
}
