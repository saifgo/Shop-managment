import type { ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { buttonVariants } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { dashboardApi, type AdminDashboardSummary } from '@/lib/api/dashboard'

export function AdminHomePage() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['admin', 'dashboard'],
    queryFn: dashboardApi.admin,
  })

  return (
    <section className="flex flex-col gap-6">
      <PageHeader
        title="Operations dashboard"
        action={
          <Link to="/admin/reports" className={buttonVariants({ variant: 'outline' })}>
            Reports
          </Link>
        }
      />

      <QueryState
        isLoading={isLoading}
        error={!isLoading && (error || !data) ? 'Failed to load dashboard.' : null}
        loadingSkeleton={<DashboardMetricsSkeleton />}
      >
        {data ? <DashboardMetrics data={data} /> : null}
      </QueryState>
    </section>
  )
}

function DashboardMetrics({ data }: { data: AdminDashboardSummary }) {
  return (
    <dl className="flex flex-wrap gap-x-8 gap-y-6">
      <Metric label="Pending orders" value={data.pending_orders} />
      <Metric label="Backorder demand" value={data.backorder_demand} />
      <Metric label="Active production" value={data.active_production} />
      <Metric
        label="Production yield"
        value={data.production_yield_pct != null ? `${data.production_yield_pct}%` : '—'}
      />
      <Metric label="Pending deliveries" value={data.pending_deliveries} />
      <Metric
        label="Receivables"
        value={
          <MoneyText
            amount={data.total_receivable.amount}
            currency={data.total_receivable.currency}
          />
        }
      />
      <Metric
        label="Overdue receivables"
        value={
          <MoneyText
            amount={data.overdue_receivable.amount}
            currency={data.overdue_receivable.currency}
          />
        }
      />
      <Metric label="Low stock variants" value={data.low_stock_variants} />
    </dl>
  )
}

function Metric({
  label,
  value,
}: {
  label: string
  value: ReactNode
}) {
  return (
    <div className="flex min-w-28 flex-col gap-1">
      <dt className="text-sm text-muted-foreground">{label}</dt>
      <dd className="text-2xl tabular-nums">{value}</dd>
    </div>
  )
}

function DashboardMetricsSkeleton() {
  return (
    <div className="flex flex-wrap gap-x-8 gap-y-6">
      {Array.from({ length: 8 }, (_, index) => (
        <div key={index} className="flex min-w-28 flex-col gap-2">
          <Skeleton className="h-4 w-24" />
          <Skeleton className="h-8 w-16" />
        </div>
      ))}
    </div>
  )
}
