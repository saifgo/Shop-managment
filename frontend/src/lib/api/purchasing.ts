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
  is_active: boolean
}

export interface PurchaseOrderSummary {
  id: string
  reference: string
  status: string
  supplier_id: string
  supplier_name: string
  currency: string
  grand_total: { amount: string; currency: string }
  items?: Array<{
    id: string
    variant_id: string
    sku: string
    quantity_ordered: string
    quantity_received: string
    unit_price: { amount: string; currency: string }
  }>
}

async function parseJson<T>(response: Response): Promise<T> {
  if (!response.ok) throw new Error('Request failed')
  return (await response.json()) as T
}

export const purchasingApi = {
  listSuppliers: () =>
    fetch(`${base}/api/suppliers`, { headers: { Accept: 'application/json', ...authHeaders() } }).then((r) =>
      parseJson<{ items: SupplierSummary[] }>(r),
    ),

  createSupplier: (payload: { code: string; name: string; contact_email?: string }) =>
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

  createPurchaseOrder: (
    payload: {
      supplier_id: string
      currency: string
      items: Array<{ variant_id: string; quantity: string; unit_price: string }>
    },
    idempotencyKey: string,
  ) =>
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
