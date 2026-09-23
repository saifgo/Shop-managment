function authHeaders(): Record<string, string> {
  const token = localStorage.getItem('tittawin.access_token') ?? undefined
  return token ? { Authorization: `Bearer ${token}` } : {}
}

const base = import.meta.env.VITE_API_BASE_URL ?? ''

export interface FinanceCategory {
  id: string
  type: string
  code: string
  name: string
}

export interface MoneyAmount {
  amount: string
  currency: string
}

async function parseJson<T>(response: Response): Promise<T> {
  if (!response.ok) throw new Error('Request failed')
  return (await response.json()) as T
}

export const lightweightFinanceApi = {
  listCategories: (type?: string) => {
    const query = type ? `?type=${type}` : ''
    return fetch(`${base}/api/finance/categories${query}`, {
      headers: { Accept: 'application/json', ...authHeaders() },
    }).then((r) => parseJson<{ items: FinanceCategory[] }>(r))
  },

  createCategory: (payload: { type: string; code: string; name: string }) =>
    fetch(`${base}/api/finance/categories`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders() },
      body: JSON.stringify(payload),
    }).then((r) => parseJson<FinanceCategory>(r)),

  listIncomes: () =>
    fetch(`${base}/api/finance/incomes`, { headers: { Accept: 'application/json', ...authHeaders() } }).then((r) =>
      parseJson<{ items: Array<{ id: string; source: string; amount: MoneyAmount; income_date: string }> }>(r),
    ),

  recordIncome: (payload: {
    category_id: string
    source: string
    amount: string
    currency: string
    income_date: string
    attachment_ref?: string
  }) =>
    fetch(`${base}/api/finance/incomes`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders() },
      body: JSON.stringify(payload),
    }).then((r) => parseJson(r)),

  listExpenses: () =>
    fetch(`${base}/api/finance/expenses`, { headers: { Accept: 'application/json', ...authHeaders() } }).then((r) =>
      parseJson<{ items: Array<{ id: string; payee: string; amount: MoneyAmount; expense_date: string }> }>(r),
    ),

  recordExpense: (payload: {
    category_id: string
    payee: string
    amount: string
    currency: string
    expense_date: string
    attachment_ref?: string
  }) =>
    fetch(`${base}/api/finance/expenses`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders() },
      body: JSON.stringify(payload),
    }).then((r) => parseJson(r)),

  listScheduled: () =>
    fetch(`${base}/api/finance/scheduled-transactions`, { headers: { Accept: 'application/json', ...authHeaders() } }).then(
      (r) =>
        parseJson<{
          items: Array<{
            id: string
            type: string
            description: string
            amount: MoneyAmount
            recurrence: string
            next_run_at: string
            is_active: boolean
          }>
        }>(r),
    ),

  createScheduled: (payload: {
    type: string
    category_id: string
    description: string
    amount: string
    currency: string
    recurrence: string
    next_run_at: string
  }) =>
    fetch(`${base}/api/finance/scheduled-transactions`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders() },
      body: JSON.stringify(payload),
    }).then((r) => parseJson(r)),

  cashPosition: () =>
    fetch(`${base}/api/finance/projections/cash-position`, { headers: { Accept: 'application/json', ...authHeaders() } }).then(
      (r) => parseJson<{ amount: string; currency: string; breakdown: Record<string, string> }>(r),
    ),

  receivablesSummary: () =>
    fetch(`${base}/api/finance/projections/receivables`, { headers: { Accept: 'application/json', ...authHeaders() } }).then(
      (r) => parseJson<{ total_receivable: MoneyAmount; overdue_receivable: MoneyAmount }>(r),
    ),

  payablesSummary: () =>
    fetch(`${base}/api/finance/projections/payables`, { headers: { Accept: 'application/json', ...authHeaders() } }).then((r) =>
      parseJson<{ total_payable: MoneyAmount }>(r),
    ),

  agingSummary: () =>
    fetch(`${base}/api/finance/projections/aging`, { headers: { Accept: 'application/json', ...authHeaders() } }).then((r) =>
      parseJson<{ receivables_aging: Record<string, MoneyAmount> }>(r),
    ),
}
