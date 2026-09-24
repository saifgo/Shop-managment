import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeftIcon, CheckIcon, RefreshCwIcon } from 'lucide-react'
import { toast } from 'sonner'
import { ConfirmAction } from '@/components/ConfirmAction'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Separator } from '@/components/ui/separator'
import { Spinner } from '@/components/ui/spinner'
import { OrderFulfillment } from '@/features/admin/orders/OrderFulfillment'
import { OrderProgress } from '@/components/OrderProgress'
import { PermissionGate } from '@/features/auth/components/PermissionGate'
import { ordersApi } from '@/lib/api/orders'
import { PERMISSIONS } from '@/lib/auth/permissions'
import { formatDateTime, formatQuantity } from '@/lib/format'

export function AdminOrderDetailPage() {
  const { id } = useParams()
  const queryClient = useQueryClient()

  const { data: order, isLoading, error } = useQuery({
    queryKey: ['admin', 'order', id],
    queryFn: () => ordersApi.getOrder(id!),
    enabled: Boolean(id),
  })

  const onUpdated = (message: string) => {
    void queryClient.invalidateQueries({ queryKey: ['admin', 'order', id] })
    void queryClient.invalidateQueries({ queryKey: ['admin', 'orders'] })
    void queryClient.invalidateQueries({ queryKey: ['admin', 'dashboard'] })
    toast.success(message)
  }

  const confirm = useMutation({
    mutationFn: () => ordersApi.confirmOrder(id!),
    onSuccess: (updated) =>
      onUpdated(
        updated.status === 'READY_TO_DELIVER'
          ? 'Order confirmed. All items are reserved and ready to ship.'
          : 'Order confirmed. Missing stock has been added to production demand.',
      ),
    onError: (err) => toast.error(err.message),
  })
  const reserve = useMutation({
    mutationFn: () => ordersApi.reserveOrder(id!),
    onSuccess: () => onUpdated('Stock re-checked and reserved where available.'),
    onError: (err) => toast.error(err.message),
  })
  const cancel = useMutation({
    mutationFn: () => ordersApi.cancelOrder(id!, 'Cancelled by admin'),
    onSuccess: () => onUpdated('Order cancelled and reserved stock released.'),
    onError: (err) => toast.error(err.message),
  })

  return (
    <section className="flex flex-col gap-6">
      <Link
        to="/admin/orders"
        className="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeftIcon className="size-4" />
        Orders
      </Link>

      <QueryState isLoading={isLoading} error={error || (!isLoading && !order) ? 'Order not found.' : null}>
        {order ? (
          <>
            <PageHeader
              title={order.reference}
              description={`Placed ${formatDateTime(order.created_at)}`}
              action={
                <PermissionGate permission={PERMISSIONS.salesOrdersManage}>
                  {order.can_cancel ? (
                    <ConfirmAction
                      variant="destructive"
                      title={`Cancel ${order.reference}?`}
                      description="Reserved stock is released and the customer sees the order as cancelled. This cannot be undone."
                      confirmLabel="Cancel order"
                      cancelLabel="Keep order"
                      trigger={<Button variant="outline">Cancel order</Button>}
                      onConfirm={async () => {
                        await cancel.mutateAsync()
                      }}
                    />
                  ) : null}
                  {order.can_reserve ? (
                    <Button variant="outline" disabled={reserve.isPending} onClick={() => reserve.mutate()}>
                      {reserve.isPending ? <Spinner data-icon="inline-start" /> : <RefreshCwIcon data-icon="inline-start" />}
                      Re-check stock
                    </Button>
                  ) : null}
                  {order.can_confirm ? (
                    <Button disabled={confirm.isPending} onClick={() => confirm.mutate()}>
                      {confirm.isPending ? <Spinner data-icon="inline-start" /> : <CheckIcon data-icon="inline-start" />}
                      Confirm & reserve stock
                    </Button>
                  ) : null}
                </PermissionGate>
              }
            />

            <OrderProgress status={order.status} />

            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
              <div className="flex min-w-0 flex-col gap-6">
                <Card>
                  <CardHeader>
                    <CardTitle>Items</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <ResponsiveTable
                      data={order.items}
                      getRowKey={(item) => item.id}
                      columns={[
                        {
                          key: 'item',
                          header: 'Item',
                          primary: true,
                          cell: (item) => (
                            <span className="flex flex-col gap-0.5">
                              <span className="font-medium">{item.product_name}</span>
                              <span className="text-xs text-muted-foreground">
                                {item.variant_name} · {item.sku}
                              </span>
                            </span>
                          ),
                        },
                        {
                          key: 'qty',
                          header: 'Qty',
                          mobile: true,
                          className: 'tabular-nums',
                          cell: (item) => (
                            <span className="flex flex-col gap-0.5">
                              <span>
                                {formatQuantity(item.quantity_ordered)} × <MoneyText amount={item.unit_price.amount} currency={item.unit_price.currency} />
                              </span>
                              <span className="text-xs text-muted-foreground">
                                {formatQuantity(item.quantity_reserved)} reserved
                                {Number(item.quantity_backordered) > 0
                                  ? ` · ${formatQuantity(item.quantity_backordered)} to produce`
                                  : ''}
                                {Number(item.quantity_delivered) > 0
                                  ? ` · ${formatQuantity(item.quantity_delivered)} delivered`
                                  : ''}
                              </span>
                            </span>
                          ),
                        },
                        {
                          key: 'status',
                          header: 'Status',
                          mobile: true,
                          cell: (item) => <StatusBadge status={item.line_status} />,
                        },
                        {
                          key: 'total',
                          header: 'Total',
                          className: 'text-right',
                          mobile: true,
                          cell: (item) => <MoneyText amount={item.line_total.amount} currency={item.line_total.currency} />,
                        },
                      ]}
                    />
                  </CardContent>
                </Card>

                <OrderFulfillment order={order} />
              </div>

              <div className="flex flex-col gap-6">
                <Card>
                  <CardHeader>
                    <CardTitle>Customer</CardTitle>
                  </CardHeader>
                  <CardContent className="flex flex-col gap-1 text-sm">
                    <Link
                      to={`/admin/customers/${order.customer_id}`}
                      className="font-medium underline-offset-4 hover:underline"
                    >
                      {order.customer_name}
                    </Link>
                    {order.notes ? (
                      <>
                        <Separator className="my-2" />
                        <p className="text-xs font-medium text-muted-foreground">Order notes</p>
                        <p className="whitespace-pre-line">{order.notes}</p>
                      </>
                    ) : null}
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader>
                    <CardTitle>Summary</CardTitle>
                  </CardHeader>
                  <CardContent className="flex flex-col gap-2 text-sm">
                    <SummaryRow label="Subtotal" amount={order.subtotal} />
                    {Number(order.discount_total.amount) > 0 ? (
                      <SummaryRow label="Discount" amount={order.discount_total} />
                    ) : null}
                    <SummaryRow label="Tax" amount={order.tax_total} />
                    <Separator className="my-1" />
                    <SummaryRow label="Total" amount={order.grand_total} strong />
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader>
                    <CardTitle>History</CardTitle>
                  </CardHeader>
                  <CardContent>
                    {order.status_history.length === 0 ? (
                      <p className="text-sm text-muted-foreground">No status changes yet.</p>
                    ) : (
                      <ol className="relative flex flex-col gap-4 border-l pl-4">
                        {[...order.status_history].reverse().map((entry, index) => (
                          <li key={`${entry.created_at}-${index}`} className="relative text-sm">
                            <span className="absolute top-1.5 -left-[1.3rem] size-2 rounded-full bg-foreground/40" />
                            <StatusBadge status={entry.to_status} />
                            {entry.reason ? <p className="mt-1">{entry.reason}</p> : null}
                            <p className="text-xs text-muted-foreground">{formatDateTime(entry.created_at)}</p>
                          </li>
                        ))}
                      </ol>
                    )}
                  </CardContent>
                </Card>
              </div>
            </div>
          </>
        ) : null}
      </QueryState>
    </section>
  )
}

function SummaryRow({
  label,
  amount,
  strong = false,
}: {
  label: string
  amount: { amount: string; currency: string }
  strong?: boolean
}) {
  return (
    <p className={strong ? 'flex justify-between gap-4 text-base font-medium' : 'flex justify-between gap-4'}>
      <span className={strong ? undefined : 'text-muted-foreground'}>{label}</span>
      <MoneyText amount={amount.amount} currency={amount.currency} />
    </p>
  )
}
