import { apiClient } from '@/lib/api/client'
import { getAccessToken } from '@/lib/auth/storage'

export interface ProductionProduct {
  item_id: string
  variant_id: string
  sku: string
  product_name: string
  variant_name: string
  planned_quantity: string
}

export interface ProductionSummary {
  id: string
  reference: string
  status: string
  priority: string
  /** First product only — use `products` for multi-product orders. */
  variant_id: string | null
  /** First product only — use `products` for multi-product orders. */
  sku: string | null
  item_count: number
  products: ProductionProduct[]
  /** Total planned quantity across all products. */
  planned_quantity: string | null
  current_stage_id?: string | null
  current_stage_name?: string | null
  current_stage_status?: string | null
  planned_due?: string | null
  created_at: string
}

export interface ProductionLoss {
  id: string
  item_id: string | null
  reason_code: string | null
  reason_label: string | null
  quantity: string
  notes: string | null
}

/** Quantities for one product at one stage. */
export interface ProductionStageLine {
  id: string
  item_id: string
  variant_id: string
  sku: string
  product_name: string
  variant_name: string
  input_quantity: string
  accepted_output_quantity: string
  loss_quantity: string
  losses: ProductionLoss[]
}

export type ReconciliationMode = 'STRICT' | 'FLEXIBLE' | 'CONVERSION'

export interface ProductionStage {
  id: string
  execution_id: string
  sequence: number
  name: string
  status: string
  reconciliation_mode: ReconciliationMode
  can_record_quantity: boolean
  can_record_loss: boolean
  input_quantity: string
  accepted_output_quantity: string
  loss_quantity: string
  performed_by?: string | null
  performed_by_name?: string | null
  started_at?: string | null
  completed_at?: string | null
  notes?: string | null
  lines: ProductionStageLine[]
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
    /** Units still moving through the stages (0 once completed or cancelled). */
    in_process_quantity: string
    loss_quantity: string
    accepted_output_quantity: string
  }>
  stages: ProductionStage[]
  can_plan: boolean
  can_start: boolean
  can_pause: boolean
  can_resume: boolean
  can_cancel: boolean
}

/** A configured workflow stage (Settings). */
export interface ProductionStageConfig {
  id: string
  sequence: number
  name: string
  reconciliation_mode: ReconciliationMode
  can_record_quantity: boolean
  can_record_loss: boolean
  is_active: boolean
}

export interface ProductionLossReason {
  id: string
  code: string
  label: string
  is_active: boolean
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
  items: Array<{ variant_id: string; planned_quantity: string }>
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

  /** DRAFT -> PLANNED: the quantities start counting as "already in production". */
  plan(id: string) {
    return apiClient.post<ProductionDetail>(`/api/productions/${id}/plan`, undefined, token())
  },

  start(id: string) {
    return apiClient.post<ProductionDetail>(`/api/productions/${id}/start`, undefined, token())
  },

  pause(id: string) {
    return apiClient.post<ProductionDetail>(`/api/productions/${id}/pause`, undefined, token())
  },

  resume(id: string) {
    return apiClient.post<ProductionDetail>(`/api/productions/${id}/resume`, undefined, token())
  },

  cancel(id: string, reason?: string) {
    return apiClient.post<ProductionDetail>(`/api/productions/${id}/cancel`, { reason }, token())
  },

  /** Inputs default to the planned quantity / previous stage's accepted output per product. */
  startStage(productionId: string, stageId: string, items?: Array<{ item_id: string; input_quantity: string }>) {
    return apiClient.post<ProductionDetail>(
      `/api/productions/${productionId}/stages/${stageId}/start`,
      { items },
      token(),
    )
  },

  completeStage(
    productionId: string,
    stageId: string,
    payload: {
      notes?: string
      items: Array<{
        item_id: string
        accepted_output_quantity: string
        loss_quantity?: string
        /** Required when loss_quantity > 0: one entry per reason, summing to the loss. */
        losses?: Array<{ loss_reason_id?: string; reason_code?: string; quantity: string; notes?: string }>
      }>
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

export const PRODUCTION_STAGES_QUERY_KEY = ['admin', 'production', 'config', 'stages'] as const
export const LOSS_REASONS_QUERY_KEY = ['admin', 'production', 'config', 'loss-reasons'] as const

/** Workflow configuration: stages and loss reasons. Defaults are created on first use. */
export const productionConfigApi = {
  listStages() {
    return apiClient.get<{ items: ProductionStageConfig[] }>('/api/production/stages', token())
  },

  createStage(payload: {
    name: string
    reconciliation_mode?: ReconciliationMode
    can_record_quantity?: boolean
    can_record_loss?: boolean
  }) {
    return apiClient.post<ProductionStageConfig>('/api/production/stages', payload, token())
  },

  updateStage(
    id: string,
    payload: Partial<Pick<ProductionStageConfig, 'name' | 'reconciliation_mode' | 'can_record_quantity' | 'can_record_loss' | 'is_active'>>,
  ) {
    return apiClient.patch<ProductionStageConfig>(`/api/production/stages/${id}`, payload, token())
  },

  /** Every stage id, in the new order. Running productions keep their original order. */
  reorderStages(stageIds: string[]) {
    return apiClient.put<{ items: ProductionStageConfig[] }>('/api/production/stages/order', { stage_ids: stageIds }, token())
  },

  listLossReasons() {
    return apiClient.get<{ items: ProductionLossReason[] }>('/api/production/loss-reasons', token())
  },

  createLossReason(payload: { label: string; code?: string }) {
    return apiClient.post<ProductionLossReason>('/api/production/loss-reasons', payload, token())
  },

  updateLossReason(id: string, payload: Partial<Pick<ProductionLossReason, 'label' | 'is_active'>>) {
    return apiClient.patch<ProductionLossReason>(`/api/production/loss-reasons/${id}`, payload, token())
  },
}
