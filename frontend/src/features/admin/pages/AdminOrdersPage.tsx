import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable, type ResponsiveTableColumn } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { ordersApi, type OrderSummary } from '@/lib/api/orders'

const columns: ResponsiveTableColumn<OrderSummary>[] = [
  {
    key: 'reference',
    header: 'Reference',
    primary: true,
    cell: (order) => (
      <Link to={`/admin/orders/${order.id}`} className="underline-offset-4 hover:underline">
        {order.reference}
      </Link>
    ),
  },
  {
    key: 'customer',
    header: 'Customer',
    mobile: true,
    cell: (order) => order.customer_name,
  },
  {
    key: 'status',
    header: 'Status',
    mobile: true,
    cell: (order) => <StatusBadge status={order.status} />,
  },
  {
    key: 'total',
    header: 'Total',
    mobile: true,
    cell: (order) => (
      <MoneyText amount={order.grand_total.amount} currency={order.grand_total.currency} />
    ),
  },
  {
    key: 'created',
    header: 'Created',
    cell: (order) => new Date(order.created_at).toLocaleString(),
  },
]

export function AdminOrdersPage() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['admin', 'orders'],
    queryFn: () => ordersApi.listOrders(),
  })

  return (
    <section className="flex flex-col gap-6">
      <PageHeader
        title="Orders"
        description="Confirm, reserve, and manage customer demand."
      />

      <QueryState
        isLoading={isLoading}
        error={error ? 'Unable to load orders.' : null}
        isEmpty={!!data && data.items.length === 0}
        emptyTitle="No orders"
        emptyDescription="There are no orders to show yet."
      >
        {data ? (
          <ResponsiveTable
            data={data.items}
            columns={columns}
            getRowKey={(order) => order.id}
          />
        ) : null}
      </QueryState>
    </section>
  )
}
