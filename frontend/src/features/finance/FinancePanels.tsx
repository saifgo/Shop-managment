import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { Button } from '@/components/ui/button'
import { Card, CardAction, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Spinner } from '@/components/ui/spinner'
import { deliveriesApi, invoicesApi } from '@/lib/api/finance'
import { ordersApi } from '@/lib/api/orders'

export function PortalInvoicesPage() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['portal', 'invoices'],
    queryFn: () => invoicesApi.list(),
  })

  return (
    <section className="flex flex-col gap-6">
      <PageHeader title="Invoices" />
      <QueryState
        isLoading={isLoading}
        error={error ? 'Failed to load invoices.' : null}
        isEmpty={data?.items.length === 0}
        emptyTitle="No invoices"
        emptyDescription="Invoices will appear here once orders are billed."
      >
        <ResponsiveTable
          data={data?.items ?? []}
          getRowKey={(invoice) => invoice.id}
          columns={[
            {
              key: 'number',
              header: 'Number',
              primary: true,
              cell: (invoice) => invoice.document_number,
            },
            {
              key: 'status',
              header: 'Status',
              cell: (invoice) => <StatusBadge status={invoice.status} />,
            },
            {
              key: 'total',
              header: 'Total',
              cell: (invoice) => (
                <MoneyText amount={invoice.grand_total.amount} currency={invoice.grand_total.currency} />
              ),
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
    </section>
  )
}

export function PortalOrderDetailFinance({ orderId }: { orderId: string }) {
  const { data: deliveries } = useQuery({
    queryKey: ['portal', 'deliveries', orderId],
    queryFn: () => deliveriesApi.list(orderId),
  })

  const items = deliveries?.items ?? []

  return (
    <Card>
      <CardHeader>
        <CardTitle>Deliveries</CardTitle>
      </CardHeader>
      <CardContent>
        {items.length === 0 ? (
          <p className="text-sm text-muted-foreground">No deliveries yet.</p>
        ) : (
          <ul className="flex flex-col gap-2">
            {items.map((delivery) => (
              <li key={delivery.id} className="flex items-center gap-2">
                <span>{delivery.reference}</span>
                <StatusBadge status={delivery.status} />
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}

export function AdminOrderFinancePanel({ orderId }: { orderId: string }) {
  const queryClient = useQueryClient()
  const { data: order } = useQuery({
    queryKey: ['admin', 'order', orderId],
    queryFn: () => ordersApi.getOrder(orderId),
  })
  const { data: deliveries } = useQuery({
    queryKey: ['admin', 'deliveries', orderId],
    queryFn: () => deliveriesApi.list(orderId),
  })

  const createDelivery = useMutation({
    mutationFn: (lines: Array<{ order_item_id: string; quantity: string }>) =>
      deliveriesApi.createFromOrder(orderId, lines),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'deliveries', orderId] }),
  })

  const transition = useMutation({
    mutationFn: ({ id, status }: { id: string; status: string }) => deliveriesApi.transition(id, status),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'deliveries', orderId] }),
  })

  const createInvoice = useMutation({
    mutationFn: (deliveryId: string) => invoicesApi.createFromDelivery(deliveryId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'invoices'] }),
  })

  if (!order) return null

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <h2 className="font-heading text-lg font-medium">Fulfillment</h2>
        <div className="flex flex-wrap gap-2">
          <Button
            onClick={() => {
              const firstItem = order.items[0]
              if (!firstItem) return
              const half = (Number(firstItem.quantity_ordered) / 2).toFixed(4)
              createDelivery.mutate([{ order_item_id: firstItem.id, quantity: half }])
            }}
            disabled={createDelivery.isPending}
          >
            {createDelivery.isPending ? <Spinner data-icon="inline-start" /> : null}
            Create partial delivery
          </Button>
          <Button
            variant="secondary"
            onClick={() =>
              createDelivery.mutate(
                order.items.map((item) => ({
                  order_item_id: item.id,
                  quantity: item.quantity_ordered,
                })),
              )
            }
            disabled={createDelivery.isPending}
          >
            {createDelivery.isPending ? <Spinner data-icon="inline-start" /> : null}
            Deliver all remaining
          </Button>
        </div>
      </div>

      {(deliveries?.items ?? []).map((delivery) => (
        <Card key={delivery.id}>
          <CardHeader>
            <CardTitle>{delivery.reference}</CardTitle>
            <CardAction>
              <StatusBadge status={delivery.status} />
            </CardAction>
          </CardHeader>
          <CardContent className="flex flex-wrap gap-2">
            {delivery.status === 'READY_TO_DELIVER' ? (
              <Button
                variant="outline"
                disabled={transition.isPending}
                onClick={() => transition.mutate({ id: delivery.id, status: 'PACKED' })}
              >
                Mark packed
              </Button>
            ) : null}
            {delivery.status === 'PACKED' ? (
              <Button
                variant="outline"
                disabled={transition.isPending}
                onClick={() => transition.mutate({ id: delivery.id, status: 'DISPATCHED' })}
              >
                Dispatch
              </Button>
            ) : null}
            {delivery.status === 'DISPATCHED' || delivery.status === 'IN_TRANSIT' ? (
              <Button
                variant="outline"
                disabled={transition.isPending}
                onClick={() => transition.mutate({ id: delivery.id, status: 'DELIVERED' })}
              >
                Mark delivered
              </Button>
            ) : null}
            <Button
              onClick={() => createInvoice.mutate(delivery.id)}
              disabled={createInvoice.isPending}
            >
              {createInvoice.isPending ? <Spinner data-icon="inline-start" /> : null}
              Create invoice
            </Button>
          </CardContent>
        </Card>
      ))}
    </div>
  )
}
