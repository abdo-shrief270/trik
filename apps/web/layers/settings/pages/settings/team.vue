<script setup lang="ts">
import type { components } from '~/types/api'
import {
  canManageMember,
  choiceValue,
  parseChoice,
  roleChoices,
  statusTone,
  whatsappShare,
  type RoleName,
} from '../../utils/team'

type Invitation = components['schemas']['Invitation']
/** A member as this page uses it (the schema leaves every field optional). */
interface Member {
  user_id: string
  name: string | null
  email: string | null
  role: RoleName
  custom_role: { id: string; name: string } | null
}

const { t, locale } = useI18n()
const session = useSessionStore()
const toast = useToast()
const { can } = usePermissions()
useHead({ title: () => `${t('team.title')} · ${t('brand.name')}` })

const canManage = computed(() => can('workspace.members.manage'))
const me = computed(() => ({
  id: session.user?.id ?? '',
  role: session.workspace?.role as RoleName | undefined,
  canManage: canManage.value,
}))

const {
  data: members,
  refresh: refreshMembers,
  status: membersStatus,
} = useAsyncData('team-members', async (): Promise<Member[]> =>
  (
    (await call(useApi().GET('/workspace/members', { params: { ...inWorkspace(), query: { per_page: 100 } } }))).data ??
    []
  ).flatMap((m) =>
    m.user_id && m.role
      ? [
          {
            user_id: m.user_id,
            name: m.name ?? null,
            email: m.email ?? null,
            role: m.role as RoleName,
            custom_role:
              m.custom_role?.id && m.custom_role.name ? { id: m.custom_role.id, name: m.custom_role.name } : null,
          },
        ]
      : [],
  ),
)
const { data: invitations, refresh: refreshInvitations } = useAsyncData(
  'team-invitations',
  async () =>
    (await call(useApi().GET('/workspace/invitations', { params: { ...inWorkspace(), query: { per_page: 100 } } })))
      .data ?? [],
)
const { data: customRoles } = useAsyncData('team-roles', async () =>
  ((await call(useApi().GET('/workspace/roles', { params: inWorkspace() }))).data ?? []).flatMap((r) =>
    r.id && r.name ? [{ id: r.id, name: r.name }] : [],
  ),
)

const choices = computed(() => roleChoices(me.value.role, customRoles.value ?? [], (r) => t(`roles.${r}`)))
const roleLabel = (role: string, custom?: { name?: string | undefined } | null) =>
  role === 'custom' && custom?.name ? custom.name : t(`roles.${role}`)
const fmt = (iso: string | null | undefined) =>
  iso
    ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { dateStyle: 'medium' }).format(new Date(iso))
    : '—'

function fail(e: unknown) {
  toast.error(t(e instanceof ApiError ? problemMessageKey(e.problem) : 'errors.generic'))
}

// ---- invite
const inviteOpen = ref(false)
const invite = reactive({ email: '', choice: 'agent' })
const inviteError = ref<string | undefined>()
const inviting = ref(false)
const lastLink = ref<{ email: string; url: string } | null>(null)

function openInvite() {
  Object.assign(invite, { email: '', choice: 'agent' })
  inviteError.value = undefined
  lastLink.value = null
  inviteOpen.value = true
}

async function sendInvite() {
  const problem = checkEmail(invite.email)
  inviteError.value = problem ? t(`forms.${problem}`) : undefined
  if (problem) return
  inviting.value = true
  try {
    const res = await call(
      useApi().POST('/workspace/invitations', {
        params: { header: { ...inWorkspace().header, 'Idempotency-Key': crypto.randomUUID() } },
        body: { email: invite.email.trim(), ...parseChoice(invite.choice) },
      }),
    )
    lastLink.value = { email: res.data.email, url: res.data.url }
    toast.success(t('team.invited', { email: res.data.email }))
    await refreshInvitations()
  } catch (e) {
    if (e instanceof ApiError && e.field('email')) inviteError.value = t('forms.email')
    else fail(e)
  } finally {
    inviting.value = false
  }
}

async function copyLink(url: string) {
  try {
    await navigator.clipboard.writeText(url)
    toast.success(t('team.copied'))
  } catch {
    toast.error(t('errors.generic'))
  }
}
const shareText = computed(() => t('team.shareText', { workspace: session.workspace?.name ?? '' }))

// ---- invitations
const revoking = ref<string | null>(null)
async function revoke(i: Invitation) {
  revoking.value = i.id
  try {
    await call(
      useApi().DELETE('/workspace/invitations/{invitation}', {
        params: { ...inWorkspace(), path: { invitation: i.id } },
      }),
    )
    toast.success(t('team.revoked', { email: i.email }))
    await refreshInvitations()
  } catch (e) {
    fail(e)
  } finally {
    revoking.value = null
  }
}
const resending = ref<string | null>(null)
async function resend(i: Invitation) {
  resending.value = i.id
  try {
    await call(
      useApi().POST('/workspace/invitations', {
        params: { header: { ...inWorkspace().header, 'Idempotency-Key': crypto.randomUUID() } },
        body: { email: i.email, role: i.role, custom_role_id: i.custom_role?.id ?? null },
      }),
    )
    toast.success(t('team.invited', { email: i.email }))
    await refreshInvitations()
  } catch (e) {
    fail(e)
  } finally {
    resending.value = null
  }
}

// ---- members
const saving = ref<string | null>(null)
async function changeRole(m: Member, value: string) {
  saving.value = m.user_id
  try {
    await call(
      useApi().PATCH('/workspace/members/{user}', {
        params: { ...inWorkspace(), path: { user: m.user_id } },
        body: parseChoice(value),
      }),
    )
    toast.success(t('team.roleChanged', { name: m.name ?? m.email ?? '' }))
  } catch (e) {
    fail(e)
  } finally {
    saving.value = null
    await refreshMembers()
  }
}

const removing = ref<Member | null>(null)
const removeOpen = computed({
  get: () => removing.value !== null,
  set: (v) => {
    if (!v) removing.value = null
  },
})
const removeBusy = ref(false)
async function confirmRemove() {
  if (!removing.value) return
  removeBusy.value = true
  try {
    await call(
      useApi().DELETE('/workspace/members/{user}', {
        params: { ...inWorkspace(), path: { user: removing.value.user_id } },
      }),
    )
    toast.success(t('team.removed', { name: removing.value.name ?? removing.value.email ?? '' }))
    removing.value = null
    await refreshMembers()
  } catch (e) {
    fail(e)
  } finally {
    removeBusy.value = false
  }
}
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-8 py-10">
    <h1 class="mb-6 font-display text-4xl font-bold tracking-[-0.03em]">{{ t('nav.settings') }}</h1>
    <SettingsNav />

    <section class="rounded-2xl border border-line bg-white" aria-labelledby="team">
      <header class="flex flex-wrap items-start justify-between gap-4 px-6 pt-6">
        <div>
          <h2 id="team" class="text-lg font-bold">{{ t('team.title') }}</h2>
          <p class="mt-1 text-[15px] text-muted">{{ t('team.sub') }}</p>
        </div>
        <UiButton v-if="canManage" @click="openInvite"
          ><UiIcon name="plus" :size="18" />{{ t('team.invite') }}</UiButton
        >
      </header>

      <div v-if="membersStatus === 'pending' && !members" class="space-y-3 p-6">
        <UiSkeleton v-for="n in 3" :key="n" class="h-12" />
      </div>
      <ul v-else class="mt-4 divide-y divide-line">
        <li v-for="m in members ?? []" :key="m.user_id" class="flex flex-wrap items-center gap-3 px-6 py-4">
          <UiAvatar :name="m.name ?? m.email ?? '?'" />
          <div class="min-w-0 flex-1">
            <p class="truncate font-semibold">
              {{ m.name ?? '—' }}
              <span v-if="m.user_id === me.id" class="ms-1 text-sm font-normal text-muted">({{ t('team.you') }})</span>
            </p>
            <p class="truncate text-sm text-muted" dir="ltr">{{ m.email }}</p>
          </div>
          <div v-if="canManageMember(me, m)" class="w-44">
            <UiSelect
              :model-value="choiceValue(m.role, m.custom_role?.id)"
              :label="t('team.roleOf', { name: m.name ?? m.email ?? '' })"
              class="[&_label]:sr-only"
              :options="choices"
              @update:model-value="(v: string) => changeRole(m, v)"
            />
          </div>
          <UiBadge v-else>{{ roleLabel(m.role, m.custom_role) }}</UiBadge>
          <UiButton
            v-if="canManageMember(me, m)"
            variant="ghost"
            size="sm"
            :aria-label="t('team.removeOf', { name: m.name ?? m.email ?? '' })"
            @click="removing = m"
          >
            <UiIcon name="trash" :size="18" />
          </UiButton>
        </li>
      </ul>
    </section>

    <section
      v-if="(invitations ?? []).length"
      class="mt-6 rounded-2xl border border-line bg-white"
      aria-labelledby="invitations"
    >
      <header class="px-6 pt-6">
        <h2 id="invitations" class="text-lg font-bold">{{ t('team.invitations') }}</h2>
        <p class="mt-1 text-[15px] text-muted">{{ t('team.invitationsSub') }}</p>
      </header>
      <ul class="mt-4 divide-y divide-line">
        <li v-for="i in invitations ?? []" :key="i.id" class="flex flex-wrap items-center gap-3 px-6 py-4">
          <div class="min-w-0 flex-1">
            <p class="truncate font-semibold" dir="ltr">{{ i.email }}</p>
            <p class="text-sm text-muted">
              {{ roleLabel(i.role, i.custom_role) }} ·
              {{
                i.status === 'expired'
                  ? t('team.expiredOn', { date: fmt(i.expires_at) })
                  : t('team.expiresOn', { date: fmt(i.expires_at) })
              }}
              <template v-if="i.invited_by"> · {{ t('team.invitedBy', { name: i.invited_by.name }) }}</template>
            </p>
          </div>
          <UiBadge :tone="statusTone(i.status)">{{ t(`team.status.${i.status}`) }}</UiBadge>
          <template v-if="canManage">
            <UiButton variant="secondary" size="sm" :loading="resending === i.id" @click="resend(i)">{{
              t('team.resend')
            }}</UiButton>
            <UiButton variant="ghost" size="sm" :loading="revoking === i.id" @click="revoke(i)">{{
              t('team.revoke')
            }}</UiButton>
          </template>
        </li>
      </ul>
    </section>

    <UiModal v-model="inviteOpen" :title="t('team.inviteTitle')">
      <div v-if="lastLink" class="space-y-4">
        <p class="flex items-start gap-2 rounded-xl bg-success-bg px-4 py-3 text-[15px] text-success" role="status">
          <UiIcon name="check" :size="18" class="mt-0.5" />{{ t('team.sentTo', { email: lastLink.email }) }}
        </p>
        <p class="text-[15px] text-muted">{{ t('team.linkHint') }}</p>
        <p class="rounded-lg border border-line bg-paper px-3 py-2 font-mono text-sm break-all" dir="ltr">
          {{ lastLink.url }}
        </p>
        <div class="flex flex-wrap gap-2">
          <UiButton variant="secondary" @click="copyLink(lastLink.url)"
            ><UiIcon name="copy" :size="18" />{{ t('team.copy') }}</UiButton
          >
          <UiButton
            variant="secondary"
            :to="whatsappShare(shareText, lastLink.url)"
            target="_blank"
            rel="noopener noreferrer"
            >{{ t('team.shareWhatsapp') }}</UiButton
          >
        </div>
      </div>
      <form v-else id="invite-form" class="space-y-5" novalidate @submit.prevent="sendInvite">
        <UiInput
          v-model="invite.email"
          :label="t('auth.email')"
          type="email"
          autocomplete="off"
          dir="ltr"
          :error="inviteError"
        />
        <UiSelect v-model="invite.choice" :label="t('team.role')" :hint="t('team.roleHint')" :options="choices" />
      </form>
      <template #footer>
        <template v-if="lastLink">
          <UiButton variant="ghost" @click="lastLink = null">{{ t('team.inviteAnother') }}</UiButton>
          <UiButton @click="inviteOpen = false">{{ t('team.done') }}</UiButton>
        </template>
        <template v-else>
          <UiButton variant="ghost" @click="inviteOpen = false">{{ t('ui.cancel') }}</UiButton>
          <UiButton type="submit" form="invite-form" :loading="inviting">{{ t('team.send') }}</UiButton>
        </template>
      </template>
    </UiModal>

    <UiModal
      v-model="removeOpen"
      size="sm"
      :title="t('team.removeTitle', { name: removing?.name ?? removing?.email ?? '' })"
    >
      <p class="text-[15px]">{{ t('team.removeBody') }}</p>
      <template #footer>
        <UiButton variant="ghost" @click="removing = null">{{ t('ui.cancel') }}</UiButton>
        <UiButton variant="danger" :loading="removeBusy" @click="confirmRemove">{{ t('team.remove') }}</UiButton>
      </template>
    </UiModal>
  </div>
</template>
