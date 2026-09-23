import { useQuery } from '@tanstack/react-query'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { Skeleton } from '@/components/ui/skeleton'
import { lightweightFinanceApi } from '@/lib/api/lightweight-finance'

export function AdminLightweightFinancePage() {
  const cash = useQuery({ queryKey: ['admin', 'finance', 'cash'], queryFn: () => lightweightFinanceApi.cashPosition() })
  const receivables = useQuery({
    queryKey: ['admin', 'finance', 'receivables'],
    queryFn: () => lightweightFinanceApi.receivablesSummary(),
  })
  const payables = useQuery({
    queryKey: ['admin', 'finance', 'payables'],
    queryFn: () => lightweightFinanceApi.payablesSummary(),
  })
  const aging = useQuery({ queryKey: ['admin', 'finance', 'aging'], queryFn: () => lightweightFinanceApi.agingSummary() })
  const incomes = useQuery({ queryKey: ['admin', 'finance', 'incomes'], queryFn: () => lightweightFinanceApi.listIncomes() })
  const expenses = useQuery({
    queryKey: ['admin', 'finance', 'expenses'],
    queryFn: () => lightweightFinanceApi.listExpenses(),
  })
  const scheduled = useQuery({
    queryKey: ['admin', 'finance', 'scheduled'],
    queryFn: () => lightweightFinanceApi.listScheduled(),
  })

  const overviewError = cash.error || receivables.error || payables.error

  return (
    <section className="flex flex-col gap-6">
      <PageHeader
        title="Finance Overview"
        description="Lightweight income, expense, and projection tracking (not statutory GL)."
      />

      <QueryState isLoading={false} error={overviewError ? 'Failed to load finance overview.' : null}>
        <div className="grid grid-cols-1 gap-6 sm:grid-cols-3">
          <Metric
            label="Cash position"
            loading={cash.isLoading}
            amount={cash.data?.amount}
            currency={cash.data?.currency}
          />
          <Metric
            label="Receivables"
            loading={receivables.isLoading}
            amount={receivables.data?.total_receivable.amount}
            currency={receivables.data?.total_receivable.currency}
          />
          <Metric
            label="Payables"
            loading={payables.isLoading}
            amount={payables.data?.total_payable.amount}
            currency={payables.data?.total_payable.currency}
          />
        </div>
      </QueryState>

      {aging.data ? (
        <div className="flex flex-col gap-3">
          <h2 className="font-heading text-lg font-medium">Receivables aging</h2>
          <ul className="flex flex-col gap-2">
            {Object.entries(aging.data.receivables_aging).map(([bucket, value]) => (
              <li key={bucket} className="flex items-center justify-between gap-4">
                <span className="text-sm text-muted-foreground">{bucket}</span>
                <MoneyText amount={value.amount} currency={value.currency} />
              </li>
            ))}
          </ul>
        </div>
      ) : null}

      <div className="grid gap-6 lg:grid-cols-3">
        <LedgerList
          title="Income"
          isLoading={incomes.isLoading}
          isEmpty={(incomes.data?.items ?? []).length === 0}
          items={(incomes.data?.items ?? []).map((row) => ({
            id: row.id,
            label: row.source,
            amount: row.amount,
            meta: row.income_date,
          }))}
        />
        <LedgerList
          title="Expenses"
          isLoading={expenses.isLoading}
          isEmpty={(expenses.data?.items ?? []).length === 0}
          items={(expenses.data?.items ?? []).map((row) => ({
            id: row.id,
            label: row.payee,
            amount: row.amount,
            meta: row.expense_date,
          }))}
        />
        <LedgerList
          title="Scheduled transactions"
          isLoading={scheduled.isLoading}
          isEmpty={(scheduled.data?.items ?? []).length === 0}
          items={(scheduled.data?.items ?? []).map((row) => ({
            id: row.id,
            label: row.description,
            amount: row.amount,
            meta: row.recurrence,
          }))}
        />
      </div>
    </section>
  )
}

function Metric({
  label,
  loading,
  amount,
  currency,
}: {
  label: string
  loading: boolean
  amount?: string
  currency?: string
}) {
  return (
    <div className="flex flex-col gap-1">
      <p className="text-sm text-muted-foreground">{label}</p>
      {loading ? (
        <Skeleton className="h-8 w-28" />
      ) : amount && currency ? (
        <p className="text-2xl tabular-nums">
          <MoneyText amount={amount} currency={currency} />
        </p>
      ) : (
        <p className="text-2xl tabular-nums">—</p>
      )}
    </div>
  )
}

function LedgerList({
  title,
  isLoading,
  isEmpty,
  items,
}: {
  title: string
  isLoading: boolean
  isEmpty: boolean
  items: Array<{ id: string; label: string; amount: { amount: string; currency: string }; meta: string }>
}) {
  return (
    <div className="flex flex-col gap-3">
      <h2 className="font-heading text-lg font-medium">{title}</h2>
      <QueryState
        isLoading={isLoading}
        isEmpty={isEmpty}
        emptyTitle={`No ${title.toLowerCase()}`}
        emptyDescription="Nothing recorded yet."
      >
        <ul className="flex flex-col gap-2">
          {items.map((row) => (
            <li key={row.id} className="flex flex-col gap-0.5">
              <div className="flex items-center justify-between gap-4">
                <span className="truncate">{row.label}</span>
                <MoneyText amount={row.amount.amount} currency={row.amount.currency} />
              </div>
              <span className="text-sm text-muted-foreground">{row.meta}</span>
            </li>
          ))}
        </ul>
      </QueryState>
    </div>
  )
}
