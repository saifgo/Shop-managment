import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { Button } from '@/components/ui/button'
import { dashboardApi } from '@/lib/api/dashboard'
import { invoicesApi } from '@/lib/api/finance'

export function PortalHomePage() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['portal', 'dashboard'],
    queryFn: dashboardApi.portal,
  })

  return (
    <section className="flex flex-col gap-8">
      <PageHeader title="Welcome to Tittawin" />

      <QueryState isLoading={isLoading} error={error || (!isLoading && !data) ? 'Failed to load dashboard.' : null}>
        {data ? (
          <>
            <div className="grid grid-cols-2 gap-6">
              <div className="flex flex-col gap-1">
                <p className="text-sm text-muted-foreground">Active orders</p>
                <p className="text-2xl tabular-nums">{data.active_orders}</p>
              </div>
              <div className="flex flex-col gap-1">
                <p className="text-sm text-muted-foreground">Outstanding balance</p>
                <p className="text-2xl tabular-nums">
                  {data.outstanding_balance ? (
                    <MoneyText
                      amount={data.outstanding_balance.amount}
                      currency={data.outstanding_balance.currency}
                    />
                  ) : (
                    '—'
                  )}
                </p>
              </div>
            </div>

            <div className="flex flex-col gap-3">
              <div className="flex items-center justify-between gap-4">
                <h2 className="font-heading text-lg font-medium">Recent orders</h2>
                <Button variant="link" nativeButton={false} render={<Link to="/portal/orders" />}>
                  View all
                </Button>
              </div>
              <QueryState
                isEmpty={data.recent_orders.length === 0}
                emptyTitle="No orders yet"
                emptyDescription="Place an order from the catalog to see it here."
              >
                <ResponsiveTable
                  data={data.recent_orders}
                  getRowKey={(order) => order.id}
                  columns={[
                    {
                      key: 'reference',
                      header: 'Reference',
                      primary: true,
                      cell: (order) => <Link to={`/portal/orders/${order.id}`}>{order.reference}</Link>,
                    },
                    {
                      key: 'status',
                      header: 'Status',
                      cell: (order) => <StatusBadge status={order.status} />,
                    },
                    {
                      key: 'total',
                      header: 'Total',
                      cell: (order) => (
                        <MoneyText amount={order.grand_total.amount} currency={order.grand_total.currency} />
                      ),
                    },
                  ]}
                />
              </QueryState>
            </div>

            <div className="flex flex-col gap-3">
              <div className="flex items-center justify-between gap-4">
                <h2 className="font-heading text-lg font-medium">Recent invoices</h2>
                <Button variant="link" nativeButton={false} render={<Link to="/portal/invoices" />}>
                  View all
                </Button>
              </div>
              <QueryState
                isEmpty={data.recent_invoices.length === 0}
                emptyTitle="No invoices yet"
                emptyDescription="Invoices will appear here once orders are billed."
              >
                <ResponsiveTable
                  data={data.recent_invoices}
                  getRowKey={(invoice) => invoice.id}
                  columns={[
                    {
                      key: 'number',
                      header: 'Number',
                      primary: true,
                      cell: (invoice) => invoice.document_number ?? 'Draft',
                    },
                    {
                      key: 'status',
                      header: 'Status',
                      cell: (invoice) => <StatusBadge status={invoice.status} />,
                    },
                    {
                      key: 'due',
                      header: 'Due',
                      cell: (invoice) => (
                        <MoneyText amount={invoice.amount_due.amount} currency={invoice.amount_due.currency} />
                      ),
                    },
                    {
                      key: 'document',
                      header: 'Document',
                      cell: (invoice) =>
                        invoice.is_posted ? (
                          <a href={invoicesApi.downloadUrl(invoice.id)} target="_blank" rel="noreferrer">
                            Download PDF
                          </a>
                        ) : (
                          '—'
                        ),
                    },
                  ]}
                />
              </QueryState>
            </div>

            <div className="flex flex-col gap-3">
              <h2 className="font-heading text-lg font-medium">Delivery tracking</h2>
              <QueryState
                isEmpty={data.delivery_status.length === 0}
                emptyTitle="No deliveries in progress"
                emptyDescription="Active deliveries will show up here."
              >
                <ul className="flex flex-col gap-2">
                  {data.delivery_status.map((delivery) => (
                    <li key={delivery.id} className="flex flex-wrap items-center gap-2 text-sm">
                      <Link to={`/portal/orders/${delivery.order_id}`}>{delivery.order_reference}</Link>
                      <span className="text-muted-foreground">{delivery.reference}</span>
                      <StatusBadge status={delivery.status} />
                    </li>
                  ))}
                </ul>
              </QueryState>
            </div>
          </>
        ) : null}
      </QueryState>
    </section>
  )
}
