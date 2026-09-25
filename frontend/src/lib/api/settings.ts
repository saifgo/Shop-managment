import { apiClient } from '@/lib/api/client'
import { getAccessToken } from '@/lib/auth/storage'

export interface TaxSettings {
  /** Percentage at scale 4, e.g. "20.0000" for 20%. */
  default_tax_rate: string
  updated_at: string | null
}

export const TAX_SETTINGS_QUERY_KEY = ['settings', 'tax'] as const

/** Seller details printed on generated documents (invoices, quotes, delivery notes…). */
export interface CompanyProfile {
  name: string | null
  phone: string | null
  email: string | null
  /** Matricule fiscal. */
  tax_id: string | null
  address: string | null
  /** Heading above the account number, e.g. "Relevé d'identité postale". */
  bank_label: string
  bank_account: string | null
  /** Timbre fiscal added to each new invoice in stamp_duty_currency, amount at scale 4. */
  stamp_duty: string
  stamp_duty_currency: string
  /** data: URI of the stamp/signature image, or null. */
  stamp_image: string | null
}

export type CompanyProfileInput = Omit<CompanyProfile, 'stamp_duty_currency' | 'stamp_image'>

export const COMPANY_PROFILE_QUERY_KEY = ['settings', 'company'] as const

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

  getCompany() {
    return apiClient.get<CompanyProfile>('/api/settings/company', token())
  },

  updateCompany(input: CompanyProfileInput) {
    return apiClient.put<CompanyProfile>('/api/settings/company', input, token())
  },

  uploadStamp(file: File) {
    const body = new FormData()
    body.append('file', file)
    return apiClient.post<CompanyProfile>('/api/settings/company/stamp', body, token())
  },

  deleteStamp() {
    return apiClient.delete<CompanyProfile>('/api/settings/company/stamp', token())
  },
}

/** "20.0000" -> "20", "7.5000" -> "7.5" for form inputs and labels. */
export function formatTaxRate(rate: string): string {
  return String(Number(rate))
}
