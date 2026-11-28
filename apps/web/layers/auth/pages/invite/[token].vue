<script setup lang="ts">
// A team invitation link (#36), opened from the email or WhatsApp. Public: the token is the secret.
definePageMeta({ layout: 'auth', auth: 'public' })
const { t } = useI18n()
const route = useRoute()
const session = useSessionStore()
useHead({
  title: () => `${t('invite.title')} · ${t('brand.name')}`,
  meta: [{ name: 'referrer', content: 'no-referrer' }],
})

const token = computed(() => String(route.params.token ?? ''))
const here = computed(() => `/invite/${token.value}`)

const { data: invitation, error } = useAsyncData(
  `invite-${token.value}`,
  async () =>
    (await call(useApi().GET('/invitations/{token}', { params: { path: { token: token.value } } }), { quiet401: true }))
      .data,
)

const mine = computed(
  () =>
    !!session.user &&
    !!invitation.value &&
    (session.user.email ?? '').toLowerCase() === invitation.value.email.toLowerCase(),
)
// the link was used by this address: it's their workspace now
const alreadyIn = computed(() => mine.value && invitation.value?.status === 'accepted')
const role = computed(() =>
  invitation.value?.role === 'custom' && invitation.value.custom_role
    ? invitation.value.custom_role
    : t(`roles.${invitation.value?.role ?? 'agent'}`),
)

const joining = ref(false)
const failure = ref('')
async function join() {
  failure.value = ''
  joining.value = true
  try {
    const res = await call(useApi().POST('/invitations/{token}/accept', { params: { path: { token: token.value } } }))
    await session.load()
    if (res.data.workspace?.id) session.selectWorkspace(res.data.workspace.id)
    await navigateTo('/')
  } catch (e) {
    failure.value = t(e instanceof ApiError ? inviteProblemKey(e.problem) : 'errors.generic')
  } finally {
    joining.value = false
  }
}

function inviteProblemKey(p: ApiProblem): string {
  return p.code.startsWith('invitation-') ? `invite.errors.${p.code}` : problemMessageKey(p)
}

async function switchAccount() {
  await session.signOut()
  await navigateTo({ path: '/sign-in', query: { next: here.value } })
}
</script>

<template>
  <div>
    <div v-if="!invitation && !error" class="space-y-4">
      <UiSkeleton class="h-10 w-3/4" /><UiSkeleton class="h-24" />
    </div>

    <div v-else-if="error || !invitation">
      <h1 class="font-display text-4xl font-bold tracking-[-0.03em]">{{ t('invite.notFoundTitle') }}</h1>
      <p class="mt-3 text-muted">{{ t('invite.notFoundBody') }}</p>
      <UiButton to="/" size="lg" class="mt-8">{{ t('invite.home') }}</UiButton>
    </div>

    <div v-else>
      <p class="text-sm font-semibold tracking-wide text-indigo uppercase">{{ t('invite.kicker') }}</p>
      <h1 class="mt-2 font-display text-4xl font-bold tracking-[-0.03em]">
        {{ t('invite.join', { workspace: invitation.workspace.name }) }}
      </h1>
      <p class="mt-3 text-muted">
        {{
          invitation.inviter
            ? t('invite.byWhom', { name: invitation.inviter.name, role })
            : t('invite.asRole', { role })
        }}
      </p>

      <p
        v-if="failure"
        role="alert"
        class="mt-6 flex items-start gap-2 rounded-xl bg-danger-bg px-4 py-3 text-[15px] text-danger"
      >
        <UiIcon name="alert" :size="18" class="mt-0.5" />{{ failure }}
      </p>

      <template v-if="alreadyIn">
        <p class="mt-6 text-[15px]">{{ t('invite.alreadyIn') }}</p>
        <UiButton to="/" size="lg" class="mt-6">{{ t('invite.open') }}</UiButton>
      </template>

      <div v-else-if="invitation.status !== 'pending'" class="mt-6 rounded-xl bg-warning-bg px-4 py-3 text-[15px]">
        {{ t(`invite.closed.${invitation.status}`) }}
      </div>

      <template v-else-if="mine">
        <UiButton size="lg" block class="mt-8" :loading="joining" @click="join">{{
          t('invite.accept', { workspace: invitation.workspace.name })
        }}</UiButton>
      </template>

      <template v-else-if="session.user">
        <p class="mt-6 rounded-xl bg-warning-bg px-4 py-3 text-[15px]">
          {{ t('invite.otherAccount', { invited: invitation.email, current: session.user.email }) }}
        </p>
        <UiButton variant="secondary" size="lg" block class="mt-6" @click="switchAccount">{{
          t('invite.switchAccount')
        }}</UiButton>
      </template>

      <template v-else>
        <p class="mt-6 text-[15px]">
          {{ t('invite.forEmail') }} <strong dir="ltr">{{ invitation.email }}</strong>
        </p>
        <div class="mt-8 space-y-3">
          <UiButton :to="{ path: '/sign-up', query: { invitation: token, email: invitation.email } }" size="lg" block>{{
            t('invite.createAccount')
          }}</UiButton>
          <UiButton :to="{ path: '/sign-in', query: { next: here } }" variant="secondary" size="lg" block>{{
            t('invite.haveAccount')
          }}</UiButton>
        </div>
      </template>
    </div>
  </div>
</template>
