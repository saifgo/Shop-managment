import { apiClient } from './client'

export interface HealthResponse {
  status: string
  service: string
  version: string
  timestamp?: string
}

export function fetchHealth() {
  return apiClient.get<HealthResponse>('/api/health')
}
