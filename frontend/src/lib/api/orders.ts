export interface MoneyAmount {
  amount: string
  currency: string
}

export interface CartLine {
  variant_id: string
  product_id: string
  product_name: string
  variant_name: string
  sku: string
  quantity: string
  unit_price: MoneyAmount & { source?: string }
  line_total: MoneyAmount
  availability: {
    available_to_sell: string
    confirmed_demand: string
  }
  will_backorder: boolean
  blocked: boolean
}

export interface CartValidation {
  valid: boolean
  currency: string
  lines: CartLine[]
  subtotal: MoneyAmount
  tax_total: MoneyAmount
  grand_total: MoneyAmount
}

export interface OrderSummary {
  id: string
  reference: string
  status: string
  customer_id: string
  customer_name: string
  currency: string
  grand_total: MoneyAmount
  created_at: string
  confirmed_at?: string | null
}

export interface OrderDetail extends OrderSummary {
  notes?: string | null
  subtotal: MoneyAmount
  tax_total: MoneyAmount
  discount_total: MoneyAmount
  submitted_at?: string | null
  items: Array<{
    id: string
    variant_id: string
    product_name: string
    variant_name: string
    sku: string
    quantity_ordered: string
    quantity_reserved: string
    quantity_backordered: string
    quantity_delivered: string
    line_status: string
    line_total: MoneyAmount
  }>
  status_history: Array<{
    from_status: string
    to_status: string
    reason?: string | null
    created_at: string
  }>
  can_confirm: boolean
  can_cancel: boolean
  can_reserve: boolean
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

export interface DemandRow {
  product_name: string
  variant_name: string
  sku: string
  customer_name?: string
  ordered: string
  reserved: string
  backordered: string
  ready: string
  delivered: string
  on_hand?: string
  net_demand?: string
  to_produce?: string
}

function authHeaders(idempotencyKey?: string): Record<string, string> {
  const token = localStorage.getItem('tittawin.access_token') ?? undefined
  const headers: Record<string, string> = {}

  if (token) {
    headers.Authorization = `Bearer ${token}`
  }

  if (idempotencyKey) {
    headers['Idempotency-Key'] = idempotencyKey
  }

  return headers
}

/** Reads the API's `{ error: { message } }` body, falling back to a generic message. */
export async function errorMessage(response: Response, fallback: string): Promise<string> {
  try {
    const body = (await response.json()) as { error?: { message?: string } }
    return body.error?.message ?? fallback
  } catch {
    return fallback
  }
}

export const ordersApi = {
  validateCart(items: Array<{ variant_id: string; quantity: string }>, customerId?: string) {
    return fetch(`${import.meta.env.VITE_API_BASE_URL ?? ''}/api/cart/validate`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders() },
      body: JSON.stringify({ items, customer_id: customerId }),
    }).then(async (response) => {
      if (!response.ok) throw new Error(await errorMessage(response, 'Cart validation failed'))
      return (await response.json()) as CartValidation
    })
  },

  /** `customerId` is required for admin users and ignored for portal users. */
  createOrder(
    items: Array<{ variant_id: string; quantity: string }>,
    notes?: string,
    customerId?: string,
    idempotencyKey: string = crypto.randomUUID(),
  ) {
    return fetch(`${import.meta.env.VITE_API_BASE_URL ?? ''}/api/orders`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...authHeaders(idempotencyKey),
      },
      body: JSON.stringify({ items, notes, customer_id: customerId }),
    }).then(async (response) => {
      if (!response.ok) throw new Error(await errorMessage(response, 'Order creation failed'))
      return (await response.json()) as OrderDetail
    })
  },

  listOrders(page = 1) {
    return fetch(`${import.meta.env.VITE_API_BASE_URL ?? ''}/api/orders?page=${page}`, {
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then(async (response) => {
      if (!response.ok) throw new Error('Failed to load orders')
      return (await response.json()) as Paginated<OrderSummary>
    })
  },

  getOrder(id: string) {
    return fetch(`${import.meta.env.VITE_API_BASE_URL ?? ''}/api/orders/${id}`, {
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then(async (response) => {
      if (!response.ok) throw new Error('Failed to load order')
      return (await response.json()) as OrderDetail
    })
  },

  confirmOrder(id: string) {
    return fetch(`${import.meta.env.VITE_API_BASE_URL ?? ''}/api/orders/${id}/confirm`, {
      method: 'POST',
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then(async (response) => {
      if (!response.ok) throw new Error(await errorMessage(response, 'Confirm failed'))
      return (await response.json()) as OrderDetail
    })
  },

  cancelOrder(id: string, reason?: string) {
    return fetch(`${import.meta.env.VITE_API_BASE_URL ?? ''}/api/orders/${id}/cancel`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders() },
      body: JSON.stringify({ reason }),
    }).then(async (response) => {
      if (!response.ok) throw new Error('Cancel failed')
      return (await response.json()) as OrderDetail
    })
  },

  reserveOrder(id: string) {
    return fetch(`${import.meta.env.VITE_API_BASE_URL ?? ''}/api/orders/${id}/reserve`, {
      method: 'POST',
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then(async (response) => {
      if (!response.ok) throw new Error('Reserve failed')
      return (await response.json()) as OrderDetail
    })
  },
}

export const demandApi = {
  byCustomer() {
    return fetch(`${import.meta.env.VITE_API_BASE_URL ?? ''}/api/demand/by-customer`, {
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then(async (response) => {
      if (!response.ok) throw new Error('Failed to load demand')
      return (await response.json()) as { items: DemandRow[] }
    })
  },

  byProduct() {
    return fetch(`${import.meta.env.VITE_API_BASE_URL ?? ''}/api/demand/by-product`, {
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then(async (response) => {
      if (!response.ok) throw new Error('Failed to load demand')
      return (await response.json()) as { items: DemandRow[] }
    })
  },
}

export function formatOrderMoney(money?: MoneyAmount): string {
  if (!money) return '—'
  return `${money.currency} ${Number(money.amount).toFixed(2)}`
}
