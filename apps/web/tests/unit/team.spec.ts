import { describe, expect, it } from 'vitest'
import {
  canManageMember,
  choiceValue,
  parseChoice,
  roleChoices,
  statusTone,
  whatsappShare,
} from '../../layers/settings/utils/team'

const label = (r: string) => r.toUpperCase()

describe('roleChoices', () => {
  it('offers owner only to owners, and lists custom roles by name', () => {
    const custom = [{ id: 'c1', name: 'Packer' }]
    expect(roleChoices('owner', custom, label).map((c) => c.value)).toEqual(['agent', 'admin', 'owner', 'custom:c1'])
    expect(roleChoices('admin', custom, label).map((c) => c.value)).toEqual(['agent', 'admin', 'custom:c1'])
    expect(roleChoices(undefined, [], label).map((c) => c.value)).toEqual(['agent', 'admin'])
    expect(roleChoices('owner', custom, label).at(-1)).toMatchObject({
      role: 'custom',
      customRoleId: 'c1',
      label: 'Packer',
    })
  })
})

describe('choices round-trip to what the API takes', () => {
  it('splits custom roles', () => {
    expect(parseChoice('agent')).toEqual({ role: 'agent', custom_role_id: null })
    expect(parseChoice('custom:0190-abc')).toEqual({ role: 'custom', custom_role_id: '0190-abc' })
    expect(choiceValue('custom', '0190-abc')).toBe('custom:0190-abc')
    expect(choiceValue('admin', null)).toBe('admin')
    expect(choiceValue('custom', null)).toBe('custom')
  })
})

describe('canManageMember', () => {
  const owner = { id: 'me', role: 'owner' as const, canManage: true }
  const admin = { id: 'me', role: 'admin' as const, canManage: true }
  it('mirrors the API: never yourself, owners only by owners, nothing without the permission', () => {
    expect(canManageMember(owner, { user_id: 'me', role: 'owner' })).toBe(false)
    expect(canManageMember(owner, { user_id: 'x', role: 'owner' })).toBe(true)
    expect(canManageMember(admin, { user_id: 'x', role: 'owner' })).toBe(false)
    expect(canManageMember(admin, { user_id: 'x', role: 'agent' })).toBe(true)
    expect(canManageMember({ ...admin, canManage: false }, { user_id: 'x', role: 'agent' })).toBe(false)
  })
})

describe('invitation helpers', () => {
  it('picks a tone per status', () => {
    expect(statusTone('pending')).toBe('neutral')
    expect(statusTone('expired')).toBe('warning')
    expect(statusTone('accepted')).toBe('success')
    expect(statusTone('revoked')).toBe('danger')
  })

  it('builds a WhatsApp share link with the text and the link encoded', () => {
    const link = whatsappShare('انضم لفريق نور على تريك:', 'https://app.trik.chat/invite/abc_-123')
    expect(link.startsWith('https://wa.me/?text=')).toBe(true)
    expect(decodeURIComponent(link.slice('https://wa.me/?text='.length))).toBe(
      'انضم لفريق نور على تريك:\nhttps://app.trik.chat/invite/abc_-123',
    )
  })
})
