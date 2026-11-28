<script setup lang="ts">
/** Until the email is verified the API refuses to connect a WhatsApp number, so say so up front. */
const session = useSessionStore()
const { t } = useI18n()
const toast = useToast()
const sending = ref(false)
const sent = ref(false)

async function resend() {
  sending.value = true
  try {
    await session.resendVerification()
    sent.value = true
    toast.success(t('verify.sent', { email: session.user?.email ?? '' }))
  } catch (e) {
    toast.error(t(e instanceof ApiError ? problemMessageKey(e.problem) : 'errors.generic'))
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <div v-if="session.user && !session.user.email_verified" class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-warning/20 bg-warning-bg px-6 py-3 text-[15px] text-warning">
    <UiIcon name="mail" :size="18" />
    <p class="flex-1">{{ t('verify.banner', { email: session.user.email }) }}</p>
    <UiButton size="sm" variant="secondary" :loading="sending" :disabled="sent" @click="resend">
      {{ sent ? t('verify.sentShort') : t('verify.resend') }}
    </UiButton>
  </div>
</template>
