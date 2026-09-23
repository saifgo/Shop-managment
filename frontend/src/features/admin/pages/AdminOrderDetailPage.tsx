import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { ConfirmAction } from '@/components/ConfirmAction'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Spinner } from '@/components/ui/spinner'
import { AdminOrderFinancePanel } from '@/features/finance/FinancePanels'
import { ordersApi } from '@/lib/api/orders'

export function AdminOrderDetailPage() {
  const { id } = useParams()
  const queryClient = useQueryClient()

  const { data: order, isLoading, error } = useQuery({
    queryKey: ['admin', 'order', id],
    queryFn: () => ordersApi.getOrder(id!),
    enabled: Boolean(id),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['admin', 'order', id] })

  const confirm = useMutation({ mutationFn: () => ordersApi.confirmOrder(id!), onSuccess: invalidate })
  const reserve = useMutation({ mutationFn: () => ordersApi.reserveOrder(id!), onSuccess: invalidate })
  const cancel = useMutation({
    mutationFn: () => ordersApi.cancelOrder(id!, 'Cancelled by admin'),
    onSuccess: invalidate,
  })

  return (
    <section className="flex flex-col gap-6">
      <Link to="/admin/orders" className="text-sm text-muted-foreground">
        Orders
      </Link>

      <QueryState
        isLoading={isLoading}
        error={error || (!isLoading && !order) ? 'Order not found.' : null}
      >
        {order ? (
          <>
            <PageHeader
              title={order.reference}
              description={order.customer_name}
              action={
                <>
                  <StatusBadge status={order.status} />
                  {order.can_confirm ? (
                    <Button disabled={confirm.isPending} onClick={() => confirm.mutate()}>
                      {confirm.isPending ? <Spinner data-icon="inline-start" /> : null}
                      Confirm
                    </Button>
                  ) : null}
                  {order.can_reserve ? (
                    <Button disabled={reserve.isPending} onClick={() => reserve.mutate()}>
                      {reserve.isPending ? <Spinner data-icon="inline-start" /> : null}
                      Re-reserve
                    </Button>
                  ) : null}
                  {order.can_cancel ? (
                    <ConfirmAction
                      variant="destructive"
                      title="Cancel this order?"
                      description="This cannot be undone. The order will be cancelled."
                      confirmLabel="Cancel order"
                      cancelLabel="Keep order"
                      trigger={<Button variant="destructive">Cancel</Button>}
                      onConfirm={async () => {
                        await cancel.mutateAsync()
                      }}
                    />
                  ) : null}
                </>
              }
            />

            <Card>
              <CardHeader>
                <CardTitle>Summary</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-2">
                <p>Customer: {order.customer_name}</p>
                <p>
                  Total:{' '}
                  {order.grand_total ? (
                    <MoneyText amount={order.grand_total.amount} currency={order.grand_total.currency} />
                  ) : (
                    '—'
                  )}
                </p>
              </CardContent>
            </Card>

            <ResponsiveTable
              data={order.items}
              getRowKey={(item) => item.id}
              columns={[
                {
                  key: 'item',
                  header: 'Item',
                  primary: true,
                  cell: (item) => `${item.product_name} — ${item.variant_name}`,
                },
                {
                  key: 'ordered',
                  header: 'Ordered',
                  cell: (item) => <span className="tabular-nums">{item.quantity_ordered}</span>,
                },
                {
                  key: 'reserved',
                  header: 'Reserved',
                  cell: (item) => <span className="tabular-nums">{item.quantity_reserved}</span>,
                },
                {
                  key: 'backordered',
                  header: 'Backordered',
                  cell: (item) => <span className="tabular-nums">{item.quantity_backordered}</span>,
                },
                {
                  key: 'status',
                  header: 'Line status',
                  cell: (item) => <StatusBadge status={item.line_status} />,
                },
              ]}
            />

            <Card>
              <CardHeader>
                <CardTitle>Status history</CardTitle>
              </CardHeader>
              <CardContent>
                {order.status_history.length === 0 ? (
                  <p className="text-sm text-muted-foreground">No status changes yet.</p>
                ) : (
                  <ul className="flex flex-col gap-2">
                    {order.status_history.map((entry, index) => (
                      <li key={`${entry.created_at}-${index}`} className="text-sm">
                        {entry.from_status} → {entry.to_status}
                        {entry.reason ? ` — ${entry.reason}` : ''}
                        <span className="text-muted-foreground">
                          {' '}
                          ({new Date(entry.created_at).toLocaleString()})
                        </span>
                      </li>
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>

            <AdminOrderFinancePanel orderId={order.id} />
          </>
        ) : null}
      </QueryState>
    </section>
  )
}
