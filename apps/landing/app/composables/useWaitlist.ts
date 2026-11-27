/** Payload field → form field, for mapping the API's 422 `errors` back onto the form. */
const SERVER_FIELDS: Record<string, WaitlistField> = {
  name: 'name',
  store_name: 'store',
  email: 'email',
  phone: 'phone',
  platform: 'platform',
  monthly_orders: 'orders',
  consent: 'consent',
}

/**
 * The waitlist form: validation in the browser, then POST {apiBase}/waitlist (double opt-in: the API
 * emails a confirmation link). Server field errors (RFC 9457 problem+json, `errors`) come back per field.
 */
export function useWaitlist() {
  const { locale } = useI18n()
  const config = useRuntimeConfig()

  const form = reactive<WaitlistForm>(emptyWaitlistForm())
  const errors = ref<WaitlistErrors>({})
  const serverErrors = ref<Partial<Record<WaitlistField, string>>>({})
  const failure = ref<'network' | 'server' | null>(null)
  const sending = ref(false)
  const touched = ref(false)

  // Re-check live once the visitor has tried to submit.
  watch(form, () => {
    if (touched.value) errors.value = validateWaitlist(form)
    serverErrors.value = {}
  })

  async function submit(): Promise<boolean> {
    touched.value = true
    failure.value = null
    errors.value = validateWaitlist(form)
    if (Object.keys(errors.value).length) return false

    sending.value = true
    try {
      await $fetch('/waitlist', {
        baseURL: config.public.apiBase,
        method: 'POST',
        body: waitlistPayload(form, locale.value),
        headers: { 'Idempotency-Key': crypto.randomUUID(), Accept: 'application/json' },
      })
      return true
    } catch (e: unknown) {
      const err = e as { statusCode?: number; data?: { errors?: Record<string, string[]> } }
      if (err.statusCode === 422 && err.data?.errors) {
        const mapped: Partial<Record<WaitlistField, string>> = {}
        for (const [key, messages] of Object.entries(err.data.errors)) {
          const field = SERVER_FIELDS[key]
          if (field && messages[0]) mapped[field] = messages[0]
        }
        serverErrors.value = mapped
        if (!Object.keys(mapped).length) failure.value = 'server'
      } else {
        failure.value = err.statusCode ? 'server' : 'network'
      }
      return false
    } finally {
      sending.value = false
    }
  }

  return { form, errors, serverErrors, failure, sending, submit }
}
