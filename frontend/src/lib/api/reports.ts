function authHeaders(): Record<string, string> {
  const token = localStorage.getItem('tittawin.access_token') ?? undefined
  const headers: Record<string, string> = {}
  if (token) headers.Authorization = `Bearer ${token}`
  return headers
}

const base = import.meta.env.VITE_API_BASE_URL ?? ''

function withRange(path: string, from?: string, to?: string): string {
  const params = new URLSearchParams()
  if (from) params.set('from', from)
  if (to) params.set('to', to)
  const query = params.toString()
  return `${base}${path}${query ? `?${query}` : ''}`
}

async function fetchReport<T>(url: string): Promise<T> {
  const response = await fetch(url, {
    headers: { Accept: 'application/json', ...authHeaders() },
  })
  if (!response.ok) throw new Error('Failed to load report')
  return (await response.json()) as T
}

export const reportsApi = {
  sales: (from?: string, to?: string) => fetchReport(withRange('/api/reports/sales', from, to)),
  margin: (from?: string, to?: string) => fetchReport(withRange('/api/reports/margin', from, to)),
  stock: () => fetchReport(`${base}/api/reports/stock`),
  productionYield: (from?: string, to?: string) => fetchReport(withRange('/api/reports/production-yield', from, to)),
  receivablesAging: () => fetchReport(`${base}/api/reports/receivables-aging`),
  payablesAging: () => fetchReport(`${base}/api/reports/payables-aging`),
  invoicedSales: (from?: string, to?: string) => fetchReport(withRange('/api/reports/invoiced-sales', from, to)),
}
