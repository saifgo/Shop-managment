import { apiClient } from '@/lib/api/client'
import { getAccessToken } from '@/lib/auth/storage'

export interface ReportMoney {
  amount: string
  currency: string
}

export interface SalesReport {
  order_count: number
  revenue: ReportMoney
}

export interface MarginReport {
  revenue: ReportMoney
  cost: ReportMoney
  margin: ReportMoney
  margin_pct: string | null
  /** Units shipped with no known cost: the margin is overstated by their true cost. */
  uncosted_units: string
  note: string
}

export interface StockReportItem {
  variant_id: string
  sku: string
  product_name: string
  kind: 'finished_good' | 'raw_material'
  unit: string
  physical_on_hand: string
  reserved: string
  available_to_sell: string
  reorder_level: string | null
  average_cost: string
  stock_value: string
  is_low_stock: boolean
}

export interface StockReport {
  items: StockReportItem[]
  low_stock_count: number
  total_stock_value: string
  finished_goods_value: string
  raw_materials_value: string
  currency: string
}

export interface YieldStageRow {
  stage: string
  sequence: number
  input: string
  accepted: string
  loss: string
  loss_pct: string | null
}

export interface YieldReasonRow {
  reason_code: string
  reason_label: string
  quantity: string
}

export interface ProductionYieldReport {
  order_count: number
  stage_count: number
  /** Pieces put into the orders that completed in the period. */
  input_total: string
  /** Good pieces that came out of the last stage. */
  output_total: string
  loss_total: string
  /** End to end: output over input, not an average of stage yields. */
  yield_pct: string | null
  by_stage: YieldStageRow[]
  by_reason: YieldReasonRow[]
}

export type AgingBuckets = Record<string, ReportMoney>

function token(): string | undefined {
  return getAccessToken() ?? undefined
}

function withRange(path: string, from?: string, to?: string): string {
  const params = new URLSearchParams()
  if (from) params.set('from', from)
  if (to) params.set('to', to)
  const query = params.toString()
  return `${path}${query ? `?${query}` : ''}`
}

export const reportsApi = {
  sales: (from?: string, to?: string) => apiClient.get<SalesReport>(withRange('/api/reports/sales', from, to), token()),
  margin: (from?: string, to?: string) => apiClient.get<MarginReport>(withRange('/api/reports/margin', from, to), token()),
  stock: () => apiClient.get<StockReport>('/api/reports/stock', token()),
  productionYield: (from?: string, to?: string) =>
    apiClient.get<ProductionYieldReport>(withRange('/api/reports/production-yield', from, to), token()),
  receivablesAging: () => apiClient.get<{ receivables_aging: AgingBuckets }>('/api/reports/receivables-aging', token()),
  payablesAging: () => apiClient.get<{ payables_aging: AgingBuckets }>('/api/reports/payables-aging', token()),
  invoicedSales: (from?: string, to?: string) =>
    apiClient.get<{ invoice_count: number; total_invoiced: ReportMoney }>(withRange('/api/reports/invoiced-sales', from, to), token()),
}
