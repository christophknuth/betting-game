import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/services/api', () => ({
  default: { admin: { changeTippYearStatus: vi.fn() } }
}))

import TippYearStatusSelect from '@/components/TippYearStatusSelect.vue'
import api from '@/services/api'
import { useNotificationStore } from '@/stores/notifications'

/**
 * B-18: the one control that sets a tipp year's status, used by the list and
 * by the year's own page.
 *
 * The case worth pinning down is the refused one. The dropdown shows a status
 * the server has not accepted - and Vue will not put it back, because the
 * model never changed and from its side there is nothing to patch. A select
 * left standing on "laufend" for a year that is still planned is a lie about
 * the read model, so the revert is asserted here rather than trusted.
 */
describe('TippYearStatusSelect', () => {
  const YEAR = { tippYearId: 3, name: 'Tippjahr 2026', status: 'planned' }

  let pinia

  function mountSelect(year = YEAR) {
    return mount(TippYearStatusSelect, {
      props: { year },
      // The same instance the test reads from: a second pinia would give the
      // component a notification store nobody here can see.
      global: { plugins: [pinia] }
    })
  }

  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
    vi.clearAllMocks()
  })

  it('sends the chosen status and reports which year changed', async () => {
    api.admin.changeTippYearStatus.mockResolvedValue({ data: { commandId: 'c-1' } })

    const select = mountSelect()
    await select.find('select').setValue('running')
    await vi.waitFor(() => expect(select.emitted('changed')).toBeTruthy())

    expect(api.admin.changeTippYearStatus).toHaveBeenCalledWith(
      3,
      { status: 'running' },
      expect.any(String)
    )
    expect(select.emitted('changed')[0]).toEqual([3])
  })

  it('puts the dropdown back where the server refused the change', async () => {
    api.admin.changeTippYearStatus.mockRejectedValue(new Error('Ein anderes Tippjahr läuft bereits'))

    const select = mountSelect()
    await select.find('select').setValue('running')

    await vi.waitFor(() =>
      expect(select.find('select').element.value).toBe('planned')
    )
    expect(select.emitted('changed')).toBeUndefined()
  })

  it('announces the new status by name, not just "Angenommen"', async () => {
    api.admin.changeTippYearStatus.mockResolvedValue({ data: { commandId: 'c-1' } })

    const select = mountSelect()
    const notifications = useNotificationStore()

    await select.find('select').setValue('closed')
    await vi.waitFor(() => expect(select.emitted('changed')).toBeTruthy())

    expect(notifications.items.at(-1).message).toBe('Tippjahr 2026 ist jetzt abgeschlossen.')
  })
})
