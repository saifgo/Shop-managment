import { apiClient } from '@/lib/api/client'
import { getAccessToken } from '@/lib/auth/storage'

export interface StockRow {
  variant_id: string
  product_name: string
  variant_name: string
  sku: string
  location_id: string
  location_code: string
  location_name: string
  physical_on_hand: string
  reserved: string
  available_to_sell: string
  confirmed_demand: string
  net_production_demand: string
}

export interface StockMovement {
  id: string
  variant_id: string
  sku: string
  location_id: string
  movement_type: string
  quantity_delta: string
  reserved_delta: string
  source_type: string
  source_id: string
  reference?: string | null
  notes?: string | null
  created_at: string
}

export interface VariantAvailability {
  physical_on_hand: string
  reserved: string
  available_to_sell: string
  confirmed_demand: string
  net_production_demand: string
}

export interface StockAdjustmentResult {
  adjustment_id: string
  movement: StockMovement
}

export interface Paginated<T> {
  items: T[]
  meta: {
    page: number
    per_page: number
    total: number
    total_pages: number
  }
}

function authHeaders(): Record<string, string> {
  const token = localStorage.getItem('tittawin.access_token') ?? undefined

  return token ? { Authorization: `Bearer ${token}` } : {}
}

export const inventoryApi = {
  listStock(page = 1, variantId?: string) {
    const params = new URLSearchParams({ page: String(page) })
    if (variantId) params.set('variant_id', variantId)

    return fetch(`${import.meta.env.VITE_API_BASE_URL ?? ''}/api/inventory/stock?${params}`, {
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then(async (response) => {
      if (!response.ok) throw new Error('Failed to load stock')
      return (await response.json()) as Paginated<StockRow>
    })
  },

  listMovements(page = 1, variantId?: string) {
    const params = new URLSearchParams({ page: String(page) })
    if (variantId) params.set('variant_id', variantId)

    return fetch(`${import.meta.env.VITE_API_BASE_URL ?? ''}/api/inventory/movements?${params}`, {
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then(async (response) => {
      if (!response.ok) throw new Error('Failed to load movements')
      return (await response.json()) as Paginated<StockMovement>
    })
  },

  availability(variantId: string, locationId?: string) {
    const params = new URLSearchParams({ variant_id: variantId })
    if (locationId) params.set('location_id', locationId)

    return apiClient.get<VariantAvailability>(
      `/api/inventory/availability?${params}`,
      getAccessToken() ?? undefined,
    )
  },

  /** Positive delta adds stock, negative removes it. Location defaults to the company's default location. */
  adjust(payload: { variant_id: string; quantity_delta: string; reason: string; location_id?: string }) {
    return apiClient.post<StockAdjustmentResult>(
      '/api/inventory/adjustments',
      payload,
      getAccessToken() ?? undefined,
    )
  },
}
