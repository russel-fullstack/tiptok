import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import LoginView from '../LoginView.vue'
import { useAuthStore } from '../../../stores/auth'

const push = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push }),
  useRoute: () => ({ query: {} }),
  RouterLink: { template: '<a><slot /></a>' },
}))

describe('LoginView', () => {
  beforeEach(() => { setActivePinia(createPinia()); push.mockClear() })

  it('affiche les champs de connexion', () => {
    const wrapper = mount(LoginView)
    expect(wrapper.find('input[type="email"]').exists()).toBe(true)
    expect(wrapper.find('input[type="password"]').exists()).toBe(true)
  })

  it('affiche l\'erreur du store sans perdre la saisie', async () => {
    const auth = useAuthStore()
    auth.login = vi.fn().mockRejectedValue(new Error('x'))
    auth.error = 'Identifiants invalides.'
    const wrapper = mount(LoginView)
    await wrapper.find('input[type="email"]').setValue('a@b.c')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').text()).toContain('Identifiants invalides.')
    expect((wrapper.find('input[type="email"]').element as HTMLInputElement).value).toBe('a@b.c')
  })

  it('redirige après une connexion réussie', async () => {
    const auth = useAuthStore()
    auth.login = vi.fn().mockResolvedValue(undefined)
    const wrapper = mount(LoginView)
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(push).toHaveBeenCalledWith('/')
  })
})
