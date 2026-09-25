import { errorMessage, type MoneyAmount, type Paginated } from '@/lib/api/orders'

export interface DeliverySummary {
  id: string
  reference: string
  status: string
  order_id: string
  order_reference: string
  created_at: string
  dispatched_at?: string | null
  delivered_at?: string | null
  is_replacement?: boolean
  line_count?: number
  /** The active (not cancelled/credited) invoice for this delivery, if any. */
  invoice?: { id: string; document_number: string | null; status: string } | null
  /** Present when listing deliveries for one order. */
  lines?: DeliveryDetail['lines']
}

export interface DeliveryDetail extends DeliverySummary {
  notes?: string | null
  tracking_reference?: string | null
  lines: Array<{
    id: string
    order_item_id: string
    product_name: string
    variant_name: string
    sku: string
    quantity: string
  }>
}

export interface InvoiceDocument {
  id: string
  document_type: string
  status: string
  document_number?: string | null
  customer_id: string
  customer_display_name: string
  currency: string
  grand_total: MoneyAmount
  amount_paid: MoneyAmount
  amount_due: MoneyAmount
  is_posted: boolean
  issued_at?: string | null
  due_date?: string | null
  created_at: string
  lines: Array<{
    id: string
    description: string
    sku: string
    quantity: string
    line_total: MoneyAmount
  }>
}

export interface PaymentRecord {
  id: string
  reference: string
  customer_id: string
  amount: MoneyAmount
  allocated_amount: MoneyAmount
  unallocated_amount: MoneyAmount
  method: string
  status: string
  payment_date: string
  allocations: Array<{
    id: string
    invoice_id: string
    invoice_number?: string | null
    allocated_amount: MoneyAmount
  }>
}

function authHeaders(idempotencyKey?: string): Record<string, string> {
  const token = localStorage.getItem('tittawin.access_token') ?? undefined
  const headers: Record<string, string> = {}

  if (token) headers.Authorization = `Bearer ${token}`
  if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey

  return headers
}

const base = import.meta.env.VITE_API_BASE_URL ?? ''

export const deliveriesApi = {
  list(orderId?: string) {
    const query = orderId ? `?order_id=${orderId}` : ''
    return fetch(`${base}/api/deliveries${query}`, {
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then(async (response) => {
      if (!response.ok) throw new Error(await errorMessage(response, 'Failed to load deliveries'))
      return (await response.json()) as Paginated<DeliverySummary>
    })
  },

  get(id: string) {
    return fetch(`${base}/api/deliveries/${id}`, {
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then(async (response) => {
      if (!response.ok) throw new Error(await errorMessage(response, 'Failed to load delivery'))
      return (await response.json()) as DeliveryDetail
    })
  },

  createFromOrder(orderId: string, lines: Array<{ order_item_id: string; quantity: string }>, notes?: string) {
    return fetch(`${base}/api/orders/${orderId}/create-delivery`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders(crypto.randomUUID()) },
      body: JSON.stringify({ lines, notes }),
    }).then(async (response) => {
      if (!response.ok) throw new Error(await errorMessage(response, 'Failed to create delivery'))
      return (await response.json()) as DeliveryDetail
    })
  },

  transition(id: string, status: string) {
    return fetch(`${base}/api/deliveries/${id}/transition`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders() },
      body: JSON.stringify({ status }),
    }).then(async (response) => {
      if (!response.ok) throw new Error(await errorMessage(response, 'Failed to transition delivery'))
      return (await response.json()) as DeliveryDetail
    })
  },
}

export const invoicesApi = {
  list(page = 1) {
    return fetch(`${base}/api/invoices?page=${page}`, {
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then(async (response) => {
      if (!response.ok) throw new Error(await errorMessage(response, 'Failed to load invoices'))
      return (await response.json()) as Paginated<InvoiceDocument>
    })
  },

  get(id: string) {
    return fetch(`${base}/api/invoices/${id}`, {
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then(async (response) => {
      if (!response.ok) throw new Error(await errorMessage(response, 'Failed to load invoice'))
      return (await response.json()) as InvoiceDocument
    })
  },

  createFromDelivery(deliveryId: string) {
    return fetch(`${base}/api/invoices`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders(crypto.randomUUID()) },
      body: JSON.stringify({ delivery_id: deliveryId }),
    }).then(async (response) => {
      if (!response.ok) throw new Error(await errorMessage(response, 'Failed to create invoice'))
      return (await response.json()) as InvoiceDocument
    })
  },

  issue(id: string, dueDate?: string) {
    return fetch(`${base}/api/invoices/${id}/issue`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders() },
      body: JSON.stringify({ due_date: dueDate }),
    }).then(async (response) => {
      if (!response.ok) throw new Error(await errorMessage(response, 'Failed to issue invoice'))
      return (await response.json()) as InvoiceDocument
    })
  },
}

export const paymentsApi = {
  record(payload: {
    customer_id: string
    amount: string
    currency: string
    method: string
    payment_date: string
    notes?: string
  }) {
    return fetch(`${base}/api/payments`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders(crypto.randomUUID()) },
      body: JSON.stringify(payload),
    }).then(async (response) => {
      if (!response.ok) throw new Error(await errorMessage(response, 'Failed to record payment'))
      return (await response.json()) as PaymentRecord
    })
  },

  allocate(paymentId: string, allocations: Array<{ invoice_id: string; amount: string }>) {
    return fetch(`${base}/api/payments/${paymentId}/allocate`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders() },
      body: JSON.stringify({ allocations }),
    }).then(async (response) => {
      if (!response.ok) throw new Error(await errorMessage(response, 'Failed to allocate payment'))
      return (await response.json()) as PaymentRecord
    })
  },
}

export function formatMoney(money?: MoneyAmount): string {
  if (!money) return '—'
  return `${money.currency} ${Number(money.amount).toFixed(2)}`
}
