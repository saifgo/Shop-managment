import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { returnsApi } from '@/lib/api/returns'

export function PortalReturnsPage() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['portal', 'returns'],
    queryFn: () => returnsApi.list(),
  })

  return (
    <section className="flex flex-col gap-6">
      <PageHeader
        title="Returns & Exchanges"
        description="To return something, open the order and choose “Return items”. Track progress here."
      />
      <QueryState
        isLoading={isLoading}
        error={error ? 'Failed to load returns.' : null}
        isEmpty={(data?.items ?? []).length === 0}
        emptyTitle="No return requests yet"
        emptyDescription="Open an order and submit a return request to track it here."
      >
        <ResponsiveTable
          data={data?.items ?? []}
          getRowKey={(item) => item.id}
          columns={[
            { key: 'reference', header: 'Reference', primary: true, cell: (item) => item.reference },
            {
              key: 'order',
              header: 'Order',
              cell: (item) => <Link to={`/portal/orders/${item.order_id}`} className="underline-offset-4 hover:underline">View order</Link>,
            },
            {
              key: 'status',
              header: 'Status',
              cell: (item) => <StatusBadge status={item.status} />,
            },
            { key: 'resolution', header: 'Resolution', cell: (item) => item.resolution ?? '—' },
            {
              key: 'created',
              header: 'Created',
              cell: (item) => new Date(item.created_at).toLocaleDateString(),
            },
          ]}
        />
      </QueryState>
    </section>
  )
}
