import { errorMessage } from '@/lib/api/orders'

function authHeaders(idempotencyKey?: string): Record<string, string> {
  const token = localStorage.getItem('tittawin.access_token') ?? undefined
  const headers: Record<string, string> = {}
  if (token) headers.Authorization = `Bearer ${token}`
  if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey
  return headers
}

const base = import.meta.env.VITE_API_BASE_URL ?? ''

export interface ReturnItemPayload {
  order_item_id: string
  quantity: string
  delivery_line_id?: string
  reason?: string
}

export interface ReturnSummary {
  id: string
  reference: string
  status: string
  resolution: string | null
  order_id: string
  customer_id: string
  reason: string | null
  created_at: string
  resolved_at: string | null
  notes?: string | null
  credit_note_id?: string | null
  replacement_delivery_id?: string | null
  items?: Array<{
    id: string
    order_item_id: string
    sku: string
    product_name: string
    quantity: string
    condition: string | null
    reason: string | null
  }>
  events?: Array<{
    id: string
    event_type: string
    from_status: string | null
    to_status: string | null
    notes: string | null
    created_at: string
  }>
}

async function parseJson<T>(response: Response): Promise<T> {
  if (!response.ok) throw new Error(await errorMessage(response, 'Request failed'))
  return (await response.json()) as T
}

export const returnsApi = {
  list: (params?: { page?: number; order_id?: string; status?: string }) => {
    const search = new URLSearchParams()
    if (params?.page) search.set('page', String(params.page))
    if (params?.order_id) search.set('order_id', params.order_id)
    if (params?.status) search.set('status', params.status)
    const query = search.toString()
    return fetch(`${base}/api/returns${query ? `?${query}` : ''}`, {
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then((r) => parseJson<{ items: ReturnSummary[]; meta: { total: number } }>(r))
  },

  get: (id: string) =>
    fetch(`${base}/api/returns/${id}`, { headers: { Accept: 'application/json', ...authHeaders() } }).then((r) =>
      parseJson<ReturnSummary>(r),
    ),

  create: (payload: { order_id: string; reason?: string; notes?: string; items: ReturnItemPayload[] }, idempotencyKey: string) =>
    fetch(`${base}/api/returns`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders(idempotencyKey) },
      body: JSON.stringify(payload),
    }).then((r) => parseJson<ReturnSummary>(r)),

  approve: (id: string, notes?: string) =>
    fetch(`${base}/api/returns/${id}/approve`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders() },
      body: JSON.stringify({ notes }),
    }).then((r) => parseJson<ReturnSummary>(r)),

  receive: (id: string, notes?: string) =>
    fetch(`${base}/api/returns/${id}/receive`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders() },
      body: JSON.stringify({ notes }),
    }).then((r) => parseJson<ReturnSummary>(r)),

  inspect: (id: string, items: Array<{ return_item_id: string; condition: string; notes?: string }>, notes?: string) =>
    fetch(`${base}/api/returns/${id}/inspect`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders() },
      body: JSON.stringify({ items, notes }),
    }).then((r) => parseJson<ReturnSummary>(r)),

  resolve: (id: string, payload: { resolution: string; invoice_id?: string; notes?: string }, idempotencyKey: string) =>
    fetch(`${base}/api/returns/${id}/resolve`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders(idempotencyKey) },
      body: JSON.stringify(payload),
    }).then((r) => parseJson<ReturnSummary>(r)),
}
