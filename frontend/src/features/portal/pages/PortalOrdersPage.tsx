import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { PaginationBar } from '@/components/PaginationBar'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { Button } from '@/components/ui/button'
import { ordersApi } from '@/lib/api/orders'
import { formatDate } from '@/lib/format'

export function PortalOrdersPage() {
  const [page, setPage] = useState(1)
  const { data, isLoading, error } = useQuery({
    queryKey: ['portal', 'orders', page],
    queryFn: () => ordersApi.listOrders(page),
    placeholderData: keepPreviousData,
  })

  return (
    <section className="flex flex-col gap-6">
      <PageHeader
        title="Your orders"
        description="Track each order from confirmation to delivery."
        action={
          <Button nativeButton={false} render={<Link to="/portal/catalog" />}>
            Shop
          </Button>
        }
      />

      <QueryState
        isLoading={isLoading}
        error={error ? 'Unable to load orders.' : null}
        isEmpty={data?.items.length === 0}
        emptyTitle="No orders yet"
        emptyDescription="Browse the shop and place your first order."
        emptyAction={
          <Button nativeButton={false} render={<Link to="/portal/catalog" />}>
            Browse the shop
          </Button>
        }
      >
        <div className="flex flex-col gap-3">
          <ResponsiveTable
            data={data?.items ?? []}
            getRowKey={(order) => order.id}
            columns={[
              {
                key: 'reference',
                header: 'Order',
                primary: true,
                cell: (order) => (
                  <Link to={`/portal/orders/${order.id}`} className="font-medium underline-offset-4 hover:underline">
                    {order.reference}
                  </Link>
                ),
              },
              {
                key: 'created',
                header: 'Placed',
                cell: (order) => formatDate(order.created_at),
              },
              {
                key: 'status',
                header: 'Status',
                cell: (order) => <StatusBadge status={order.status} />,
              },
              {
                key: 'total',
                header: 'Total',
                className: 'text-right',
                cell: (order) => <MoneyText amount={order.grand_total.amount} currency={order.grand_total.currency} />,
              },
            ]}
            rowAction={(order) => (
              <Button variant="outline" size="sm" nativeButton={false} render={<Link to={`/portal/orders/${order.id}`} />}>
                View
              </Button>
            )}
          />
          {data ? (
            <PaginationBar
              page={data.meta.page}
              totalPages={data.meta.total_pages}
              total={data.meta.total}
              noun={data.meta.total === 1 ? 'order' : 'orders'}
              onPageChange={setPage}
            />
          ) : null}
        </div>
      </QueryState>
    </section>
  )
}
