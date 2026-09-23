import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { Button } from '@/components/ui/button'
import { ordersApi } from '@/lib/api/orders'

export function PortalOrdersPage() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['portal', 'orders'],
    queryFn: () => ordersApi.listOrders(),
  })

  return (
    <section className="flex flex-col gap-6">
      <PageHeader
        title="Your orders"
        action={
          <Button nativeButton={false} render={<Link to="/portal/checkout" />}>
            Go to checkout
          </Button>
        }
      />

      <QueryState
        isLoading={isLoading}
        error={error ? 'Unable to load orders.' : null}
        isEmpty={data?.items.length === 0}
        emptyTitle="No orders yet"
        emptyDescription="Submit an order from checkout to see it here."
      >
        <ResponsiveTable
          data={data?.items ?? []}
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
              cell: (order) => <MoneyText amount={order.grand_total.amount} currency={order.grand_total.currency} />,
            },
            {
              key: 'created',
              header: 'Created',
              cell: (order) => new Date(order.created_at).toLocaleString(),
            },
          ]}
        />
      </QueryState>
    </section>
  )
}
