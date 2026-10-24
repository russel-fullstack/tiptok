import { defineStore } from 'pinia'
import { ref } from 'vue'

import {
  getCurrentUser,
  login as loginRequest,
  logout as logoutRequest,
  register as registerRequest,
} from '../services/auth'

import type {
  AuthUser,
  LoginData,
  RegisterData,
} from '../services/auth'

export const useAuthStore = defineStore('auth', () => {
  // Utilisateur actuellement connecté
  const user = ref<AuthUser | null>(null)

  // Indique si la vérification initiale est terminée
  const initialized = ref(false)

  // Indique si une opération est en cours
  const loading = ref(false)

  // Message d'erreur à afficher dans les vues
  const error = ref('')

  // Vérifier la session Laravel
  async function fetchUser(): Promise<void> {
    try {
      user.value = await getCurrentUser()
    } catch {
      user.value = null
    } finally {
      initialized.value = true
    }
  }

  // Connecter un utilisateur
  async function login(data: LoginData): Promise<void> {
    loading.value = true
    error.value = ''

    try {
      await loginRequest(data)
      await fetchUser()

      if (!user.value) {
        throw new Error('Utilisateur non récupéré après connexion.')
      }
    } catch (e: unknown) {
      error.value = 'Connexion impossible. Vérifie tes identifiants.'
      throw e
    } finally {
      loading.value = false
    }
  }

  // Inscrire un nouvel utilisateur
  async function register(data: RegisterData): Promise<void> {
    loading.value = true
    error.value = ''

    try {
      await registerRequest(data)
      await fetchUser()

      if (!user.value) {
        throw new Error(
          'Compte créé, mais session non récupérée. Vérifie la configuration Sanctum.'
        )
      }
    } catch (e: unknown) {
      error.value =
        "Inscription impossible. Vérifie les informations saisies."
      throw e
    } finally {
      loading.value = false
    }
  }

  // Déconnecter l'utilisateur
  async function logout(): Promise<void> {
    loading.value = true
    error.value = ''

    try {
      await logoutRequest()
    } catch (e: unknown) {
      error.value = 'La déconnexion côté serveur a échoué.'
      throw e
    } finally {
      user.value = null
      initialized.value = true
      loading.value = false
    }
  }

  return {
    user,
    initialized,
    loading,
    error,
    fetchUser,
    login,
    register,
    logout,
  }
})