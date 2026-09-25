import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { ArrowRightIcon, FileTextIcon, PackageIcon, TruckIcon, WalletIcon } from 'lucide-react'
import type { ComponentType, ReactNode } from 'react'
import { MoneyText } from '@/components/MoneyText'
import { QueryState } from '@/components/QueryState'
import { StatusBadge } from '@/components/StatusBadge'
import { Button, buttonVariants } from '@/components/ui/button'
import { Card, CardAction, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { ProductCard } from '@/features/portal/components/ProductCard'
import { useCart } from '@/features/portal/context/CartContext'
import { catalogApi } from '@/lib/api/catalog'
import { dashboardApi } from '@/lib/api/dashboard'
import { invoicesApi } from '@/lib/api/finance'
import { formatDate, formatQuantity } from '@/lib/format'

export function PortalHomePage() {
  const { user } = useAuth()
  const { itemCount } = useCart()
  const { data, isLoading, error } = useQuery({
    queryKey: ['portal', 'dashboard'],
    queryFn: dashboardApi.portal,
  })
  const { data: featured } = useQuery({
    queryKey: ['portal', 'products', 'featured'],
    queryFn: () => catalogApi.listProducts({ per_page: 4 }),
  })

  const inTransit = data?.delivery_status.filter((delivery) => delivery.status !== 'DELIVERED') ?? []

  return (
    <section className="flex flex-col gap-8">
      <div className="flex flex-col gap-4 rounded-2xl border bg-card p-6 sm:flex-row sm:items-center sm:justify-between sm:p-8">
        <div className="flex flex-col gap-1">
          <h1 className="font-heading text-2xl font-medium tracking-tight sm:text-3xl">
            Welcome back{user ? `, ${user.first_name}` : ''}
          </h1>
          <p className="text-muted-foreground">Order handmade pottery at your prices and follow every delivery.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          {itemCount > 0 ? (
            <Link to="/portal/checkout" className={buttonVariants({ variant: 'outline', size: 'lg' })}>
              View cart ({formatQuantity(itemCount)})
            </Link>
          ) : null}
          <Button size="lg" nativeButton={false} render={<Link to="/portal/catalog" />}>
            Shop now
            <ArrowRightIcon data-icon="inline-end" />
          </Button>
        </div>
      </div>

      <QueryState
        isLoading={isLoading}
        error={error || (!isLoading && !data) ? 'Failed to load your account overview.' : null}
        loadingSkeleton={
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            {Array.from({ length: 3 }, (_, index) => (
              <Skeleton key={index} className="h-20 rounded-xl" />
            ))}
          </div>
        }
      >
        {data ? (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <Stat
              icon={PackageIcon}
              label="Open orders"
              value={data.active_orders}
              to="/portal/orders"
            />
            <Stat icon={TruckIcon} label="On the way" value={inTransit.length} to="/portal/orders" />
            <Stat
              icon={WalletIcon}
              label="Balance due"
              value={<MoneyText amount={data.outstanding_balance.amount} currency={data.outstanding_balance.currency} />}
              to="/portal/invoices"
            />
          </div>
        ) : null}
      </QueryState>

      {featured && featured.items.length > 0 ? (
        <div className="flex flex-col gap-4">
          <div className="flex items-center justify-between gap-4">
            <h2 className="font-heading text-lg font-medium">From the workshop</h2>
            <Link to="/portal/catalog" className={buttonVariants({ variant: 'ghost', size: 'sm' })}>
              See all
              <ArrowRightIcon data-icon="inline-end" />
            </Link>
          </div>
          <div className="grid grid-cols-2 gap-x-4 gap-y-8 lg:grid-cols-4">
            {featured.items.map((product) => (
              <ProductCard key={product.id} product={product} />
            ))}
          </div>
        </div>
      ) : null}

      {data ? (
        <div className="grid gap-6 lg:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle>Recent orders</CardTitle>
              <CardAction>
                <Link to="/portal/orders" className={buttonVariants({ variant: 'ghost', size: 'sm' })}>
                  All orders
                </Link>
              </CardAction>
            </CardHeader>
            <CardContent>
              {data.recent_orders.length === 0 ? (
                <p className="text-sm text-muted-foreground">No orders yet — your first one is a few clicks away.</p>
              ) : (
                <ul className="flex flex-col divide-y">
                  {data.recent_orders.map((order) => (
                    <li key={order.id}>
                      <Link
                        to={`/portal/orders/${order.id}`}
                        className="-mx-2 flex items-center gap-3 rounded-md px-2 py-2.5 hover:bg-muted"
                      >
                        <span className="flex min-w-0 flex-1 flex-col">
                          <span className="text-sm font-medium">{order.reference}</span>
                          <span className="text-xs text-muted-foreground">{formatDate(order.created_at)}</span>
                        </span>
                        <StatusBadge status={order.status} />
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
              <CardTitle>Invoices</CardTitle>
              <CardAction>
                <Link to="/portal/invoices" className={buttonVariants({ variant: 'ghost', size: 'sm' })}>
                  All invoices
                </Link>
              </CardAction>
            </CardHeader>
            <CardContent>
              {data.recent_invoices.length === 0 ? (
                <p className="text-sm text-muted-foreground">Invoices appear here once your orders ship.</p>
              ) : (
                <ul className="flex flex-col divide-y">
                  {data.recent_invoices.map((invoice) => (
                    <li key={invoice.id} className="flex items-center gap-3 py-2.5">
                      <span className="flex min-w-0 flex-1 flex-col">
                        <span className="text-sm font-medium">{invoice.document_number ?? 'Draft'}</span>
                        <span className="text-xs text-muted-foreground">
                          {Number(invoice.amount_due.amount) > 0 ? (
                            <>
                              <MoneyText amount={invoice.amount_due.amount} currency={invoice.amount_due.currency} /> due
                            </>
                          ) : (
                            'Paid'
                          )}
                        </span>
                      </span>
                      <StatusBadge status={invoice.status} />
                      {invoice.is_posted ? (
                        <a
                          href={invoicesApi.downloadUrl(invoice.id)}
                          target="_blank"
                          rel="noreferrer"
                          className={buttonVariants({ variant: 'ghost', size: 'icon-sm' })}
                          aria-label={`Download invoice ${invoice.document_number ?? ''} PDF`}
                        >
                          <FileTextIcon />
                        </a>
                      ) : null}
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>
        </div>
      ) : null}
    </section>
  )
}

function Stat({
  icon: Icon,
  label,
  value,
  to,
}: {
  icon: ComponentType<{ className?: string }>
  label: string
  value: ReactNode
  to: string
}) {
  return (
    <Link to={to} className="flex items-center gap-4 rounded-xl border bg-card p-4 transition-colors hover:border-foreground/30">
      <span className="flex size-10 items-center justify-center rounded-lg bg-muted text-muted-foreground">
        <Icon className="size-5" />
      </span>
      <span className="flex flex-col">
        <span className="text-sm text-muted-foreground">{label}</span>
        <span className="text-xl font-medium tabular-nums">{value}</span>
      </span>
    </Link>
  )
}
