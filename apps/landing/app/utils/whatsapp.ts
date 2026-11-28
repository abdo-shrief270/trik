/**
 * A click-to-chat link (https://wa.me/<number>?text=…). The number is digits with the country code
 * (e.g. 201012345678; "+", spaces and dashes are ignored). Returns null when no valid number is configured.
 *
 * Takes a number too: Nuxt parses an all-digit env value (NUXT_PUBLIC_WHATSAPP_NUMBER) into a number.
 */
export function whatsappLink(number: string | number | undefined | null, text?: string): string | null {
  const digits = String(number ?? '').replace(/[\s+\-()]/g, '')
  if (!/^[1-9]\d{7,14}$/.test(digits)) return null

  return `https://wa.me/${digits}${text ? `?text=${encodeURIComponent(text)}` : ''}`
}
