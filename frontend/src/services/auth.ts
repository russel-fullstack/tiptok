import { api } from './api'
import type { AuthUser } from '../types'

export interface RegisterPayload {
  name: string
  username: string
  email: string
  password: string
  password_confirmation: string
}

export async function getCurrentUser(): Promise<AuthUser> {
  const { data } = await api.get<AuthUser>('/api/user')
  return data
}

export async function login(payload: { email: string; password: string }): Promise<AuthUser> {
  await api.get('/sanctum/csrf-cookie')
  await api.post('/login', payload)
  return getCurrentUser()
}

export async function register(payload: RegisterPayload): Promise<AuthUser> {
  await api.get('/sanctum/csrf-cookie')
  await api.post('/register', payload)
  return getCurrentUser()
}

export async function logout(): Promise<void> {
  await api.post('/logout')
}

export async function forgotPassword(email: string): Promise<void> {
  await api.get('/sanctum/csrf-cookie')
  await api.post('/forgot-password', { email })
}

export async function resetPassword(payload: {
  token: string; email: string; password: string; password_confirmation: string
}): Promise<void> {
  await api.get('/sanctum/csrf-cookie')
  await api.post('/reset-password', payload)
}

export async function resendVerification(): Promise<void> {
  await api.post('/email/verification-notification')
}