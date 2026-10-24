
import { api } from './api'

/**
 * Données nécessaires à l'inscription.
 */
export interface RegisterData {
  name: string
  username: string
  email: string
  password: string
  password_confirmation: string
}

/**
 * Données nécessaires à la connexion.
 */
export interface LoginData {
  email: string
  password: string
}

/**
 * Structure d'un utilisateur authentifié.
 */
export interface AuthUser {
  id: number
  name: string
  username: string
  email: string
  avatar_url: string | null
  bio: string | null
  email_verified_at: string | null
}

/**
 * Initialise le cookie CSRF de Laravel Sanctum.
 */
export async function getCsrfCookie(): Promise<void> {
  await api.get('/sanctum/csrf-cookie')
}

/**
 * Inscrit un nouvel utilisateur.
 */
export async function register(data: RegisterData): Promise<void> {
  await getCsrfCookie()
  await api.post('/register', data)
}

/**
 * Connecte un utilisateur existant.
 */
export async function login(data: LoginData): Promise<void> {
  await getCsrfCookie()
  await api.post('/login', data)
}

/**
 * Récupère les informations de l'utilisateur connecté.
 */
export async function getCurrentUser(): Promise<AuthUser> {
  const response = await api.get<AuthUser>('/api/user')
  return response.data
}

/**
 * Déconnecte l'utilisateur.
 */
export async function logout(): Promise<void> {
  await api.post('/logout')
}
