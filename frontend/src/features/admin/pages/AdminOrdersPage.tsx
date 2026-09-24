import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { PlusIcon, SearchIcon } from 'lucide-react'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { PaginationBar } from '@/components/PaginationBar'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable, type ResponsiveTableColumn } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { Button } from '@/components/ui/button'
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group'
import { ScrollArea, ScrollBar } from '@/components/ui/scroll-area'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { PermissionGate } from '@/features/auth/components/PermissionGate'
import { useDebouncedValue } from '@/hooks/use-debounced-value'
import { ordersApi, type OrderSummary } from '@/lib/api/orders'
import { PERMISSIONS } from '@/lib/auth/permissions'
import { formatDateTime } from '@/lib/format'

/** Work queues an operator actually thinks in, mapped to backend statuses. */
const ORDER_QUEUES = [
  { value: 'all', label: 'All', statuses: '' },
  { value: 'to-confirm', label: 'To confirm', statuses: 'SUBMITTED' },
  { value: 'waiting-stock', label: 'Waiting for stock', statuses: 'CONFIRMED,PARTIALLY_ALLOCATED' },
  { value: 'to-ship', label: 'To ship', statuses: 'READY_TO_DELIVER,PARTIALLY_DELIVERED' },
  { value: 'delivered', label: 'Delivered', statuses: 'DELIVERED' },
  { value: 'cancelled', label: 'Cancelled', statuses: 'CANCELLED' },
] as const

const PER_PAGE = 25

const columns: ResponsiveTableColumn<OrderSummary>[] = [
  {
    key: 'reference',
    header: 'Order',
    primary: true,
    cell: (order) => (
      <Link to={`/admin/orders/${order.id}`} className="font-medium underline-offset-4 hover:underline">
        {order.reference}
      </Link>
    ),
  },
  {
    key: 'customer',
    header: 'Customer',
    mobile: true,
    cell: (order) => (
      <Link to={`/admin/customers/${order.customer_id}`} className="underline-offset-4 hover:underline">
        {order.customer_name}
      </Link>
    ),
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
    className: 'text-right',
    cell: (order) => <MoneyText amount={order.grand_total.amount} currency={order.grand_total.currency} />,
  },
  {
    key: 'created',
    header: 'Placed',
    cell: (order) => <span className="text-muted-foreground">{formatDateTime(order.created_at)}</span>,
  },
]

export function AdminOrdersPage() {
  const [params, setParams] = useSearchParams()
  const queue = ORDER_QUEUES.find((item) => item.value === params.get('queue')) ?? ORDER_QUEUES[0]
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const debouncedSearch = useDebouncedValue(search.trim())

  const { data, isLoading, error, isFetching } = useQuery({
    queryKey: ['admin', 'orders', { queue: queue.value, search: debouncedSearch, page }],
    queryFn: () =>
      ordersApi.listOrders(page, {
        status: queue.statuses || undefined,
        search: debouncedSearch || undefined,
        perPage: PER_PAGE,
      }),
    placeholderData: keepPreviousData,
  })

  return (
    <section className="flex flex-col gap-6">
      <PageHeader
        title="Orders"
        description="Confirm new orders, ship what is ready, and follow what is waiting for stock."
        action={
          <PermissionGate permission={PERMISSIONS.salesOrdersManage}>
            <Button nativeButton={false} render={<Link to="/admin/orders/new" />}>
              <PlusIcon data-icon="inline-start" />
              New order
            </Button>
          </PermissionGate>
        }
      />

      <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <ScrollArea className="max-w-full">
          <Tabs
            value={queue.value}
            onValueChange={(value) => {
              setPage(1)
              setParams(value === 'all' ? {} : { queue: String(value) }, { replace: true })
            }}
          >
            <TabsList>
              {ORDER_QUEUES.map((item) => (
                <TabsTrigger key={item.value} value={item.value}>
                  {item.label}
                </TabsTrigger>
              ))}
            </TabsList>
          </Tabs>
          <ScrollBar orientation="horizontal" />
        </ScrollArea>
        <InputGroup className="lg:max-w-xs">
          <InputGroupAddon>
            <SearchIcon />
          </InputGroupAddon>
          <InputGroupInput
            placeholder="Order number or customer"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
            aria-label="Search orders"
          />
        </InputGroup>
      </div>

      <QueryState
        isLoading={isLoading}
        error={error ? 'Unable to load orders.' : null}
        isEmpty={!!data && data.items.length === 0}
        emptyTitle={debouncedSearch ? 'No orders match your search' : `No orders in “${queue.label}”`}
        emptyDescription={debouncedSearch ? 'Check the order number or customer name.' : 'Nothing is waiting here right now.'}
      >
        {data ? (
          <div className={isFetching ? 'flex flex-col gap-3 opacity-70 transition-opacity' : 'flex flex-col gap-3'}>
            <ResponsiveTable data={data.items} columns={columns} getRowKey={(order) => order.id} />
            <PaginationBar
              page={data.meta.page}
              totalPages={data.meta.total_pages}
              total={data.meta.total}
              noun={data.meta.total === 1 ? 'order' : 'orders'}
              onPageChange={setPage}
            />
          </div>
        ) : null}
      </QueryState>
    </section>
  )
}
