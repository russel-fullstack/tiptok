import { defineStore } from 'pinia'
import { ref } from 'vue'
import * as authService from '../services/auth'
import { errorMessage } from '../services/api'
import type { RegisterPayload } from '../services/auth'
import type { AuthUser } from '../types'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null)
  const initialized = ref(false)
  const loading = ref(false)
  const error = ref('')

  async function fetchUser() {
    try {
      user.value = await authService.getCurrentUser()
    } catch {
      user.value = null
    } finally {
      initialized.value = true
    }
  }

  async function login(email: string, password: string) {
    loading.value = true
    error.value = ''
    try {
      user.value = await authService.login({ email, password })
    } catch (e) {
      error.value = errorMessage(e, 'Connexion impossible.')
      throw e
    } finally {
      loading.value = false
    }
  }

  async function register(payload: RegisterPayload) {
    loading.value = true
    error.value = ''
    try {
      user.value = await authService.register(payload)
    } catch (e) {
      error.value = errorMessage(e, 'Inscription impossible.')
      throw e
    } finally {
      loading.value = false
    }
  }

  async function logout() {
    try {
      await authService.logout()
    } finally {
      user.value = null
    }
  }

  function setUser(next: AuthUser) {   // utilisé après modification du profil (ch. 12)
    user.value = next
  }

  return { user, initialized, loading, error, fetchUser, login, register, logout, setUser }
})