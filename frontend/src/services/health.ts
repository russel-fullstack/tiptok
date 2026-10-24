import { api } from './api'

export interface HealthResponse {
  success: boolean
  application: string
  version: string
  message: string
}

export async function checkApiHealth(): Promise<HealthResponse> {
  const { data } = await api.get<HealthResponse>('/api/v1/health')
  return data
}