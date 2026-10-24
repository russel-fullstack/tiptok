import axios, { AxiosError } from 'axios'
import type { ApiErrorBody } from '../types'

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000',
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
  timeout: 10_000,
})

/** Transforme n'importe quelle erreur en message lisible (401/403/404/422/429/réseau). */
export function errorMessage(e: unknown, fallback = 'Une erreur est survenue.'): string {
  if (!axios.isAxiosError(e)) return fallback
  const err = e as AxiosError<ApiErrorBody>
  if (!err.response) return 'Réseau indisponible. Vérifie ta connexion.'
  const { status, data } = err.response
  switch (status) {
    case 401: return 'Connecte-toi pour continuer.'
    case 403: return "Tu n'as pas le droit d'effectuer cette action."
    case 404: return 'Ressource introuvable.'
    case 419: return 'Session expirée. Recharge la page.'
    case 422: return data?.errors ? Object.values(data.errors)[0]?.[0] ?? fallback : data?.message ?? fallback
    case 429: return 'Trop de requêtes. Réessaie dans un instant.'
    default:  return data?.message ?? fallback
  }
}

export function fieldErrors(e: unknown): Record<string, string> {
  if (!axios.isAxiosError(e) || e.response?.status !== 422) return {}
  const errs = (e.response.data as ApiErrorBody).errors ?? {}
  return Object.fromEntries(Object.entries(errs).map(([k, v]) => [k, v[0]]))
}


