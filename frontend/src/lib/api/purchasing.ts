import { errorMessage } from '@/lib/api/orders'

function authHeaders(idempotencyKey?: string): Record<string, string> {
  const token = localStorage.getItem('tittawin.access_token') ?? undefined
  const headers: Record<string, string> = {}
  if (token) headers.Authorization = `Bearer ${token}`
  if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey
  return headers
}

const base = import.meta.env.VITE_API_BASE_URL ?? ''

export interface SupplierSummary {
  id: string
  code: string
  name: string
  contact_email: string | null
  contact_phone: string | null
  address: string | null
  tax_id: string | null
  is_active: boolean
}

export interface CreateSupplierInput {
  code: string
  name: string
  contact_email?: string
  contact_phone?: string
  address?: string
  tax_id?: string
}

export interface PurchaseOrderItem {
  id: string
  variant_id: string
  sku: string
  product_name: string
  variant_name: string
  quantity_ordered: string
  quantity_received: string
  unit_price: { amount: string; currency: string }
  line_total: { amount: string; currency: string }
}

export interface PurchaseOrderSummary {
  id: string
  reference: string
  status: string
  supplier_id: string
  supplier_name: string
  currency: string
  grand_total: { amount: string; currency: string }
  created_at: string
  expected_at?: string | null
  notes?: string | null
  items?: PurchaseOrderItem[]
}

export interface CreatePurchaseOrderInput {
  supplier_id: string
  currency: string
  expected_at?: string
  notes?: string
  items: Array<{ variant_id: string; quantity: string; unit_price: string }>
}

async function parseJson<T>(response: Response): Promise<T> {
  if (!response.ok) throw new Error(await errorMessage(response, 'Request failed'))
  return (await response.json()) as T
}

export const purchasingApi = {
  listSuppliers: (params: { page?: number; per_page?: number } = {}) => {
    const query = new URLSearchParams()
    if (params.page) query.set('page', String(params.page))
    if (params.per_page) query.set('per_page', String(params.per_page))
    const qs = query.toString()

    return fetch(`${base}/api/suppliers${qs ? `?${qs}` : ''}`, {
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then((r) => parseJson<{ items: SupplierSummary[]; meta: { total: number } }>(r))
  },

  createSupplier: (payload: CreateSupplierInput) =>
    fetch(`${base}/api/suppliers`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders() },
      body: JSON.stringify(payload),
    }).then((r) => parseJson<SupplierSummary>(r)),

  listPurchaseOrders: (params?: { supplier_id?: string }) => {
    const query = params?.supplier_id ? `?supplier_id=${params.supplier_id}` : ''
    return fetch(`${base}/api/purchase-orders${query}`, {
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then((r) => parseJson<{ items: PurchaseOrderSummary[] }>(r))
  },

  getPurchaseOrder: (id: string) =>
    fetch(`${base}/api/purchase-orders/${id}`, { headers: { Accept: 'application/json', ...authHeaders() } }).then((r) =>
      parseJson<PurchaseOrderSummary>(r),
    ),

  createPurchaseOrder: (payload: CreatePurchaseOrderInput, idempotencyKey: string) =>
    fetch(`${base}/api/purchase-orders`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders(idempotencyKey) },
      body: JSON.stringify(payload),
    }).then((r) => parseJson<PurchaseOrderSummary>(r)),

  receivePurchaseOrder: (
    id: string,
    lines: Array<{ purchase_order_item_id: string; quantity: string }>,
    idempotencyKey: string,
  ) =>
    fetch(`${base}/api/purchase-orders/${id}/receive`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders(idempotencyKey) },
      body: JSON.stringify({ lines }),
    }).then((r) => parseJson<{ id: string; reference: string }>(r)),

  supplierBalance: (supplierId: string) =>
    fetch(`${base}/api/suppliers/${supplierId}/balance`, { headers: { Accept: 'application/json', ...authHeaders() } }).then(
      (r) => parseJson<{ amount: string; currency: string }>(r),
    ),
}
