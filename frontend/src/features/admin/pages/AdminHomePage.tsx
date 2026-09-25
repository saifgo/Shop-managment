import type { ComponentType, ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import {
  AlertTriangleIcon,
  ArrowRightIcon,
  ClipboardCheckIcon,
  FactoryIcon,
  PackageIcon,
  PlusIcon,
  TruckIcon,
  Undo2Icon,
} from 'lucide-react'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { StatusBadge } from '@/components/StatusBadge'
import { Button, buttonVariants } from '@/components/ui/button'
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { PermissionGate } from '@/features/auth/components/PermissionGate'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { dashboardApi, type AdminDashboardSummary } from '@/lib/api/dashboard'
import { PERMISSIONS } from '@/lib/auth/permissions'
import { formatDate, formatQuantity } from '@/lib/format'
import { cn } from '@/lib/utils'

function greeting(): string {
  const hour = new Date().getHours()
  if (hour < 12) return 'Good morning'
  if (hour < 18) return 'Good afternoon'
  return 'Good evening'
}

export function AdminHomePage() {
  const { user } = useAuth()
  const { data, isLoading, error } = useQuery({
    queryKey: ['admin', 'dashboard'],
    queryFn: dashboardApi.admin,
    refetchInterval: 60_000,
  })

  return (
    <section className="flex flex-col gap-6">
      <PageHeader
        title={user ? `${greeting()}, ${user.first_name}` : 'Dashboard'}
        description="What needs your attention today."
        action={
          <>
            <PermissionGate permission={PERMISSIONS.catalogManage}>
              <Link to="/admin/catalog/new" className={buttonVariants({ variant: 'outline' })}>
                <PackageIcon data-icon="inline-start" />
                New product
              </Link>
            </PermissionGate>
            <PermissionGate permission={PERMISSIONS.salesOrdersManage}>
              <Button nativeButton={false} render={<Link to="/admin/orders/new" />}>
                <PlusIcon data-icon="inline-start" />
                New order
              </Button>
            </PermissionGate>
          </>
        }
      />

      <QueryState
        isLoading={isLoading}
        error={!isLoading && (error || !data) ? 'Failed to load dashboard.' : null}
        loadingSkeleton={<DashboardSkeleton />}
      >
        {data ? <Dashboard data={data} /> : null}
      </QueryState>
    </section>
  )
}

function Dashboard({ data }: { data: AdminDashboardSummary }) {
  return (
    <div className="flex flex-col gap-6">
      <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <ActionTile
          to="/admin/orders?queue=to-confirm"
          icon={ClipboardCheckIcon}
          count={data.orders_to_confirm}
          label="Orders to confirm"
          empty="No new orders waiting"
        />
        <ActionTile
          to="/admin/orders?queue=to-ship"
          icon={TruckIcon}
          count={data.orders_ready_to_deliver}
          label="Orders ready to ship"
          empty="Nothing to ship"
        />
        <ActionTile
          to="/admin/inventory"
          icon={AlertTriangleIcon}
          count={data.low_stock_variants}
          label="Items low on stock"
          empty="Stock levels look fine"
          tone="warning"
        />
        <ActionTile
          to="/admin/returns"
          icon={Undo2Icon}
          count={data.open_returns}
          label="Open returns"
          empty="No open returns"
        />
      </div>

      <dl className="grid grid-cols-2 gap-x-6 gap-y-5 rounded-xl border bg-card p-4 sm:p-5 lg:grid-cols-4">
        <Metric
          label="Sales this month"
          value={<MoneyText amount={data.sales_this_month.amount} currency={data.sales_this_month.currency} />}
          hint={`${data.sales_this_month.order_count} ${data.sales_this_month.order_count === 1 ? 'order' : 'orders'}`}
        />
        <Metric
          label="To collect"
          value={<MoneyText amount={data.total_receivable.amount} currency={data.total_receivable.currency} />}
          hint={
            Number(data.overdue_receivable.amount) > 0 ? (
              <span className="text-destructive">
                <MoneyText amount={data.overdue_receivable.amount} currency={data.overdue_receivable.currency} /> overdue
              </span>
            ) : (
              'Nothing overdue'
            )
          }
        />
        <Metric
          label="In production"
          value={data.active_production}
          hint={
            data.production_yield_pct != null ? `${Number(data.production_yield_pct).toFixed(1)}% yield` : 'No yield data yet'
          }
        />
        <Metric
          label="To make for orders"
          value={formatQuantity(data.backorder_demand)}
          hint={
            <Link to="/admin/demand" className="underline-offset-4 hover:underline">
              View demand
            </Link>
          }
        />
      </dl>

      <div className="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <Card>
          <CardHeader>
            <CardTitle>Recent orders</CardTitle>
            <CardAction>
              <Link to="/admin/orders" className={buttonVariants({ variant: 'ghost', size: 'sm' })}>
                All orders
                <ArrowRightIcon data-icon="inline-end" />
              </Link>
            </CardAction>
          </CardHeader>
          <CardContent>
            {data.recent_orders.length === 0 ? (
              <p className="text-sm text-muted-foreground">No orders yet.</p>
            ) : (
              <ul className="flex flex-col divide-y">
                {data.recent_orders.map((order) => (
                  <li key={order.id}>
                    <Link
                      to={`/admin/orders/${order.id}`}
                      className="-mx-2 flex items-center gap-3 rounded-md px-2 py-2.5 hover:bg-muted"
                    >
                      <span className="flex min-w-0 flex-1 flex-col">
                        <span className="truncate text-sm font-medium">{order.customer_name}</span>
                        <span className="text-xs text-muted-foreground">
                          {order.reference} · {formatDate(order.created_at)}
                        </span>
                      </span>
                      <StatusBadge status={order.status} className="hidden sm:inline-flex" />
                      <MoneyText
                        amount={order.grand_total.amount}
                        currency={order.grand_total.currency}
                        className="text-sm"
                      />
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Running low</CardTitle>
            <CardDescription>Available to sell, after reservations.</CardDescription>
            <CardAction>
              <PermissionGate permission={PERMISSIONS.productionView}>
                <Link to="/admin/production" className={buttonVariants({ variant: 'ghost', size: 'sm' })}>
                  <FactoryIcon data-icon="inline-start" />
                  Production
                </Link>
              </PermissionGate>
            </CardAction>
          </CardHeader>
          <CardContent>
            {data.low_stock_items.length === 0 ? (
              <p className="text-sm text-muted-foreground">Every active item has healthy stock.</p>
            ) : (
              <ul className="flex flex-col divide-y">
                {data.low_stock_items.map((item) => (
                  <li key={item.variant_id}>
                    <Link
                      to={`/admin/catalog/${item.product_id}`}
                      className="-mx-2 flex items-center gap-3 rounded-md px-2 py-2.5 hover:bg-muted"
                    >
                      <span className="flex min-w-0 flex-1 flex-col">
                        <span className="truncate text-sm font-medium">{item.product_name}</span>
                        <span className="text-xs text-muted-foreground">
                          {item.variant_name} · {item.sku}
                        </span>
                      </span>
                      <span
                        className={cn(
                          'text-sm tabular-nums',
                          Number(item.available_to_sell) <= 0 ? 'font-medium text-destructive' : 'text-amber-700',
                        )}
                      >
                        {formatQuantity(Math.max(0, Number(item.available_to_sell)))} left
                      </span>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}

function ActionTile({
  to,
  icon: Icon,
  count,
  label,
  empty,
  tone = 'default',
}: {
  to: string
  icon: ComponentType<{ className?: string }>
  count: number
  label: string
  empty: string
  tone?: 'default' | 'warning'
}) {
  const active = count > 0

  return (
    <Link
      to={to}
      className={cn(
        'group flex items-center gap-3 rounded-xl border bg-card p-3 transition-colors hover:border-foreground/30 sm:gap-4 sm:p-4',
        active && tone === 'warning' && 'border-amber-200 bg-amber-50/60',
      )}
    >
      <span
        className={cn(
          'hidden size-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground sm:flex',
          active && (tone === 'warning' ? 'bg-amber-100 text-amber-800' : 'bg-foreground text-background'),
        )}
      >
        <Icon className="size-5" />
      </span>
      <span className="flex min-w-0 flex-1 flex-col">
        <span className={cn('text-2xl leading-tight font-medium tabular-nums', !active && 'text-muted-foreground')}>
          {count}
        </span>
        <span className="line-clamp-2 text-sm text-muted-foreground">{active ? label : empty}</span>
      </span>
      <ArrowRightIcon className="size-4 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
    </Link>
  )
}

function Metric({ label, value, hint }: { label: string; value: ReactNode; hint?: ReactNode }) {
  return (
    <div className="flex min-w-0 flex-col gap-1">
      <dt className="text-sm text-muted-foreground">{label}</dt>
      <dd className="text-lg font-medium break-words tabular-nums sm:text-xl">{value}</dd>
      {hint ? <dd className="text-xs text-muted-foreground">{hint}</dd> : null}
    </div>
  )
}

function DashboardSkeleton() {
  return (
    <div className="flex flex-col gap-6">
      <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
        {Array.from({ length: 4 }, (_, index) => (
          <Skeleton key={index} className="h-[74px] rounded-xl" />
        ))}
      </div>
      <Skeleton className="h-28 rounded-xl" />
      <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
        <Skeleton className="h-72 rounded-xl" />
        <Skeleton className="h-72 rounded-xl" />
      </div>
    </div>
  )
}
