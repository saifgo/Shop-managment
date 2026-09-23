function authHeaders(): Record<string, string> {
  const token = localStorage.getItem('tittawin.access_token') ?? undefined
  const headers: Record<string, string> = {}
  if (token) headers.Authorization = `Bearer ${token}`
  return headers
}

const base = import.meta.env.VITE_API_BASE_URL ?? ''

export interface AdminDashboardSummary {
  pending_orders: number
  backorder_demand: string
  active_production: number
  production_yield_pct: string | null
  pending_deliveries: number
  total_receivable: { amount: string; currency: string }
  overdue_receivable: { amount: string; currency: string }
  low_stock_variants: number
}

export interface PortalDashboardSummary {
  active_orders: number
  outstanding_balance: { amount: string; currency: string }
  recent_invoices: Array<{
    id: string
    document_number: string | null
    status: string
    grand_total: { amount: string; currency: string }
    amount_due: { amount: string; currency: string }
    is_posted: boolean
    issued_at: string | null
  }>
  recent_orders: Array<{
    id: string
    reference: string
    status: string
    grand_total: { amount: string; currency: string }
    created_at: string
  }>
  delivery_status: Array<{
    id: string
    reference: string
    status: string
    order_id: string
    order_reference: string
    created_at: string
    delivered_at: string | null
  }>
}

export const dashboardApi = {
  admin: () =>
    fetch(`${base}/api/dashboard/admin`, {
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then(async (response) => {
      if (!response.ok) throw new Error('Failed to load admin dashboard')
      return (await response.json()) as AdminDashboardSummary
    }),

  portal: () =>
    fetch(`${base}/api/dashboard/portal`, {
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then(async (response) => {
      if (!response.ok) throw new Error('Failed to load portal dashboard')
      return (await response.json()) as PortalDashboardSummary
    }),
}
