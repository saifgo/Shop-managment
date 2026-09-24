import { apiClient } from '@/lib/api/client'
import { getAccessToken } from '@/lib/auth/storage'

export interface TaxSettings {
  /** Percentage at scale 4, e.g. "20.0000" for 20%. */
  default_tax_rate: string
  updated_at: string | null
}

export const TAX_SETTINGS_QUERY_KEY = ['settings', 'tax'] as const

function token(): string | undefined {
  return getAccessToken() ?? undefined
}

export const settingsApi = {
  getTax() {
    return apiClient.get<TaxSettings>('/api/settings/tax', token())
  },

  updateTax(defaultTaxRate: string) {
    return apiClient.put<TaxSettings>('/api/settings/tax', { default_tax_rate: defaultTaxRate }, token())
  },
}

/** "20.0000" -> "20", "7.5000" -> "7.5" for form inputs and labels. */
export function formatTaxRate(rate: string): string {
  return String(Number(rate))
}
