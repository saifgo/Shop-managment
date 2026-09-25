import { apiClient } from '@/lib/api/client'
import { getAccessToken } from '@/lib/auth/storage'

export interface ProductionSummary {
  id: string
  reference: string
  status: string
  priority: string
  variant_id: string | null
  sku: string | null
  planned_quantity: string | null
  current_stage_id?: string | null
  current_stage_name?: string | null
  current_stage_status?: string | null
  planned_due?: string | null
  created_at: string
}

export interface ProductionStage {
  id: string
  execution_id: string
  sequence: number
  name: string
  status: string
  reconciliation_mode: string
  can_record_quantity: boolean
  can_record_loss: boolean
  input_quantity: string
  accepted_output_quantity: string
  loss_quantity: string
  started_at?: string | null
  completed_at?: string | null
  notes?: string | null
}

export interface ProductionDetail extends ProductionSummary {
  source_type?: string | null
  source_id?: string | null
  planned_start?: string | null
  notes?: string | null
  started_at?: string | null
  completed_at?: string | null
  cancelled_at?: string | null
  items: Array<{
    id: string
    variant_id: string
    sku: string
    product_name: string
    variant_name: string
    planned_quantity: string
    accepted_output_quantity: string
  }>
  stages: ProductionStage[]
  can_start: boolean
  can_pause: boolean
  can_cancel: boolean
}

export interface ProductionDemandRow {
  product_name: string
  variant_name: string
  sku: string
  variant_id: string
  ordered: string
  reserved: string
  backordered: string
  on_hand: string
  net_demand: string
  already_in_production: string
  to_produce: string
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

function token(): string | undefined {
  return getAccessToken() ?? undefined
}

export interface CreateProductionPayload {
  variant_id: string
  planned_quantity: string
  priority?: string
  plan?: boolean
  planned_due?: string
  notes?: string
  source_type?: string
  source_id?: string
}

export const productionApi = {
  list(page = 1, status?: string) {
    const params = new URLSearchParams({ page: String(page), per_page: '100' })
    if (status) params.set('status', status)

    return apiClient.get<Paginated<ProductionSummary>>(`/api/productions?${params}`, token())
  },

  get(id: string) {
    return apiClient.get<ProductionDetail>(`/api/productions/${id}`, token())
  },

  create(payload: CreateProductionPayload) {
    return apiClient.post<ProductionDetail>('/api/productions', payload, token(), {
      'Idempotency-Key': crypto.randomUUID(),
    })
  },

  start(id: string) {
    return apiClient.post<ProductionDetail>(`/api/productions/${id}/start`, undefined, token())
  },

  pause(id: string) {
    return apiClient.post<ProductionDetail>(`/api/productions/${id}/pause`, undefined, token())
  },

  cancel(id: string, reason?: string) {
    return apiClient.post<ProductionDetail>(`/api/productions/${id}/cancel`, { reason }, token())
  },

  startStage(productionId: string, stageId: string, inputQuantity?: string) {
    return apiClient.post<ProductionDetail>(
      `/api/productions/${productionId}/stages/${stageId}/start`,
      { input_quantity: inputQuantity },
      token(),
    )
  },

  completeStage(
    productionId: string,
    stageId: string,
    payload: {
      accepted_output_quantity: string
      loss_quantity?: string
      notes?: string
      losses?: Array<{ reason_code?: string; quantity: string; notes?: string }>
    },
  ) {
    return apiClient.post<ProductionDetail>(
      `/api/productions/${productionId}/stages/${stageId}/complete`,
      payload,
      token(),
    )
  },

  demand() {
    return apiClient.get<{ items: ProductionDemandRow[] }>('/api/production-demand', token())
  },
}
