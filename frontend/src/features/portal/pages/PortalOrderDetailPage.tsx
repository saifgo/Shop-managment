import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { ConfirmAction } from '@/components/ConfirmAction'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Spinner } from '@/components/ui/spinner'
import { Textarea } from '@/components/ui/textarea'
import { PortalOrderDetailFinance } from '@/features/finance/FinancePanels'
import { ordersApi } from '@/lib/api/orders'
import { returnsApi } from '@/lib/api/returns'
import { CheckIcon } from 'lucide-react'

export function PortalOrderDetailPage() {
  const { id } = useParams()
  const queryClient = useQueryClient()

  const { data: order, isLoading, error } = useQuery({
    queryKey: ['portal', 'order', id],
    queryFn: () => ordersApi.getOrder(id!),
    enabled: Boolean(id),
  })

  const cancelOrder = useMutation({
    mutationFn: () => ordersApi.cancelOrder(id!, 'Cancelled by customer'),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['portal', 'order', id] }),
  })

  const createReturn = useMutation({
    mutationFn: (payload: { reason: string; items: Array<{ order_item_id: string; quantity: string }> }) =>
      returnsApi.create(
        { order_id: id!, reason: payload.reason, items: payload.items },
        crypto.randomUUID(),
      ),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['portal', 'returns'] })
    },
  })

  const canRequestReturn = order ? ['DELIVERED', 'PARTIALLY_DELIVERED'].includes(order.status) : false

  return (
    <section className="flex flex-col gap-6">
      <Link to="/portal/orders" className="text-sm text-muted-foreground">
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
              action={
                <>
                  <StatusBadge status={order.status} />
                  {order.can_cancel ? (
                    <ConfirmAction
                      variant="destructive"
                      title="Cancel this order?"
                      description="This cannot be undone. The order will be cancelled."
                      confirmLabel="Cancel order"
                      cancelLabel="Keep order"
                      trigger={<Button variant="destructive">Cancel order</Button>}
                      onConfirm={async () => {
                        await cancelOrder.mutateAsync()
                      }}
                    />
                  ) : null}
                </>
              }
            />

            <Card>
              <CardHeader>
                <CardTitle>Summary</CardTitle>
                <CardDescription>
                  Submitted {order.submitted_at ? new Date(order.submitted_at).toLocaleString() : '—'}
                </CardDescription>
              </CardHeader>
              <CardContent>
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
              wide
              data={order.items}
              getRowKey={(item) => item.id}
              columns={[
                {
                  key: 'item',
                  header: 'Item',
                  primary: true,
                  cell: (item) => `${item.product_name} — ${item.variant_name} (${item.sku})`,
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
                  header: 'Status',
                  cell: (item) => <StatusBadge status={item.line_status} />,
                },
                {
                  key: 'total',
                  header: 'Total',
                  cell: (item) => <MoneyText amount={item.line_total.amount} currency={item.line_total.currency} />,
                },
              ]}
            />

            {id ? <PortalOrderDetailFinance orderId={id} /> : null}

            {canRequestReturn ? (
              <Card>
                <CardHeader>
                  <CardTitle>Request a return</CardTitle>
                </CardHeader>
                <CardContent>
                  <form
                    id="return-request-form"
                    className="flex flex-col gap-5"
                    onSubmit={(event) => {
                      event.preventDefault()
                      const form = new FormData(event.currentTarget)
                      const reason = String(form.get('reason') ?? '')
                      const firstItem = order.items[0]
                      if (!firstItem) return
                      createReturn.mutate({
                        reason,
                        items: [{ order_item_id: firstItem.id, quantity: firstItem.quantity_ordered }],
                      })
                    }}
                  >
                    <FieldGroup>
                      <Field>
                        <FieldLabel htmlFor="reason">Reason</FieldLabel>
                        <Textarea id="reason" name="reason" rows={3} placeholder="Describe the issue" required />
                        <FieldDescription>Return request will include all line items from this order.</FieldDescription>
                      </Field>
                    </FieldGroup>
                  </form>
                  {createReturn.isSuccess ? (
                    <Alert>
                      <CheckIcon />
                      <AlertTitle>Return request submitted</AlertTitle>
                      <AlertDescription>Return request submitted.</AlertDescription>
                    </Alert>
                  ) : null}
                </CardContent>
                <CardFooter>
                  <Button type="submit" form="return-request-form" disabled={createReturn.isPending}>
                    {createReturn.isPending ? <Spinner data-icon="inline-start" /> : null}
                    Submit return request
                  </Button>
                </CardFooter>
              </Card>
            ) : null}
          </>
        ) : null}
      </QueryState>
    </section>
  )
}
