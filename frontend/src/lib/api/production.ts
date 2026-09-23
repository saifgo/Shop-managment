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

function authHeaders(): Record<string, string> {
  const token = localStorage.getItem('tittawin.access_token') ?? undefined

  return token ? { Authorization: `Bearer ${token}` } : {}
}

function apiBase(): string {
  return import.meta.env.VITE_API_BASE_URL ?? ''
}

export const productionApi = {
  list(page = 1, status?: string) {
    const params = new URLSearchParams({ page: String(page) })
    if (status) params.set('status', status)

    return fetch(`${apiBase()}/api/productions?${params}`, {
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then(async (response) => {
      if (!response.ok) throw new Error('Failed to load productions')
      return (await response.json()) as Paginated<ProductionSummary>
    })
  },

  get(id: string) {
    return fetch(`${apiBase()}/api/productions/${id}`, {
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then(async (response) => {
      if (!response.ok) throw new Error('Failed to load production')
      return (await response.json()) as ProductionDetail
    })
  },

  create(payload: {
    variant_id: string
    planned_quantity: string
    priority?: string
    plan?: boolean
    notes?: string
  }) {
    return fetch(`${apiBase()}/api/productions`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...authHeaders(),
      },
      body: JSON.stringify(payload),
    }).then(async (response) => {
      if (!response.ok) throw new Error('Failed to create production')
      return (await response.json()) as ProductionDetail
    })
  },

  start(id: string) {
    return fetch(`${apiBase()}/api/productions/${id}/start`, {
      method: 'POST',
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then(async (response) => {
      if (!response.ok) throw new Error('Failed to start production')
      return (await response.json()) as ProductionDetail
    })
  },

  pause(id: string) {
    return fetch(`${apiBase()}/api/productions/${id}/pause`, {
      method: 'POST',
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then(async (response) => {
      if (!response.ok) throw new Error('Failed to pause production')
      return (await response.json()) as ProductionDetail
    })
  },

  cancel(id: string, reason?: string) {
    return fetch(`${apiBase()}/api/productions/${id}/cancel`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...authHeaders(),
      },
      body: JSON.stringify({ reason }),
    }).then(async (response) => {
      if (!response.ok) throw new Error('Failed to cancel production')
      return (await response.json()) as ProductionDetail
    })
  },

  startStage(productionId: string, stageId: string, inputQuantity?: string) {
    return fetch(`${apiBase()}/api/productions/${productionId}/stages/${stageId}/start`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...authHeaders(),
      },
      body: JSON.stringify({ input_quantity: inputQuantity }),
    }).then(async (response) => {
      if (!response.ok) throw new Error('Failed to start stage')
      return (await response.json()) as ProductionDetail
    })
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
    return fetch(`${apiBase()}/api/productions/${productionId}/stages/${stageId}/complete`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...authHeaders(),
      },
      body: JSON.stringify(payload),
    }).then(async (response) => {
      if (!response.ok) throw new Error('Failed to complete stage')
      return (await response.json()) as ProductionDetail
    })
  },

  demand() {
    return fetch(`${apiBase()}/api/production-demand`, {
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then(async (response) => {
      if (!response.ok) throw new Error('Failed to load production demand')
      return (await response.json()) as { items: ProductionDemandRow[] }
    })
  },
}
