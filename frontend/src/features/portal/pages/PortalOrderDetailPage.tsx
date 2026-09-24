import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { AlertCircleIcon, ArrowLeftIcon, RotateCcwIcon, Undo2Icon } from 'lucide-react'
import { toast } from 'sonner'
import { ConfirmAction } from '@/components/ConfirmAction'
import { MoneyText } from '@/components/MoneyText'
import { OrderProgress } from '@/components/OrderProgress'
import { PageHeader } from '@/components/PageHeader'
import { QuantityStepper } from '@/components/QuantityStepper'
import { QueryState } from '@/components/QueryState'
import { StatusBadge } from '@/components/StatusBadge'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { Field, FieldLabel } from '@/components/ui/field'
import { Separator } from '@/components/ui/separator'
import { Spinner } from '@/components/ui/spinner'
import { Textarea } from '@/components/ui/textarea'
import { PortalOrderDetailFinance } from '@/features/finance/FinancePanels'
import { useCart } from '@/features/portal/context/CartContext'
import { ordersApi, type OrderDetail } from '@/lib/api/orders'
import { returnsApi } from '@/lib/api/returns'
import { formatDateTime, formatQuantity } from '@/lib/format'

/** Customer wording for line allocation states. */
const LINE_STATUS_LABELS: Record<string, string> = {
  UNALLOCATED: 'Awaiting confirmation',
  RESERVED: 'Reserved for you',
  BACKORDERED: 'Being made',
  READY: 'Ready to ship',
  DELIVERED: 'Delivered',
}

export function PortalOrderDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { addItem } = useCart()

  const { data: order, isLoading, error } = useQuery({
    queryKey: ['portal', 'order', id],
    queryFn: () => ordersApi.getOrder(id!),
    enabled: Boolean(id),
  })

  const cancelOrder = useMutation({
    mutationFn: () => ordersApi.cancelOrder(id!, 'Cancelled by customer'),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['portal'] })
      toast.success('Your order has been cancelled.')
    },
    onError: (err) => toast.error(err.message),
  })

  const orderAgain = (current: OrderDetail) => {
    for (const item of current.items) {
      addItem(
        {
          variantId: item.variant_id,
          productId: item.product_id,
          productName: item.product_name,
          variantName: item.variant_name,
          sku: item.sku,
        },
        item.quantity_ordered,
      )
    }
    toast.success('Items added to your cart. Prices are refreshed at checkout.')
    navigate('/portal/checkout')
  }

  const canRequestReturn = order?.items.some((item) => Number(item.quantity_returnable) > 0) ?? false

  return (
    <section className="flex flex-col gap-6">
      <Link
        to="/portal/orders"
        className="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeftIcon className="size-4" />
        Your orders
      </Link>

      <QueryState isLoading={isLoading} error={error || (!isLoading && !order) ? 'Order not found.' : null}>
        {order ? (
          <>
            <PageHeader
              title={`Order ${order.reference}`}
              description={`Placed ${formatDateTime(order.submitted_at ?? order.created_at)}`}
              action={
                <>
                  {order.can_cancel ? (
                    <ConfirmAction
                      variant="destructive"
                      title="Cancel this order?"
                      description="The order will be cancelled and nothing will be shipped. This cannot be undone."
                      confirmLabel="Cancel order"
                      cancelLabel="Keep order"
                      trigger={<Button variant="outline">Cancel order</Button>}
                      onConfirm={async () => {
                        await cancelOrder.mutateAsync()
                      }}
                    />
                  ) : null}
                  {canRequestReturn ? <ReturnRequestDialog order={order} /> : null}
                  <Button onClick={() => orderAgain(order)}>
                    <RotateCcwIcon data-icon="inline-start" />
                    Order again
                  </Button>
                </>
              }
            />

            <OrderProgress status={order.status} />

            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
              <div className="flex min-w-0 flex-col gap-6">
                <Card>
                  <CardHeader>
                    <CardTitle>Items</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <ul className="flex flex-col divide-y">
                      {order.items.map((item) => (
                        <li key={item.id} className="flex items-start justify-between gap-4 py-3 first:pt-0 last:pb-0">
                          <div className="min-w-0">
                            <Link
                              to={`/portal/catalog/${item.product_id}`}
                              className="font-medium underline-offset-4 hover:underline"
                            >
                              {item.product_name}
                            </Link>
                            <p className="text-sm text-muted-foreground">
                              {item.variant_name} · {formatQuantity(item.quantity_ordered)} ×{' '}
                              <MoneyText amount={item.unit_price.amount} currency={item.unit_price.currency} />
                            </p>
                            <p className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                              <StatusBadge status={item.line_status} label={LINE_STATUS_LABELS[item.line_status]} />
                              {Number(item.quantity_backordered) > 0 ? (
                                <span>{formatQuantity(item.quantity_backordered)} being made for you</span>
                              ) : null}
                              {Number(item.quantity_delivered) > 0 ? (
                                <span>{formatQuantity(item.quantity_delivered)} delivered</span>
                              ) : null}
                            </p>
                          </div>
                          <MoneyText
                            amount={item.line_total.amount}
                            currency={item.line_total.currency}
                            className="shrink-0 font-medium"
                          />
                        </li>
                      ))}
                    </ul>
                  </CardContent>
                </Card>

                {id ? <PortalOrderDetailFinance orderId={id} /> : null}
              </div>

              <Card>
                <CardHeader>
                  <CardTitle>Summary</CardTitle>
                </CardHeader>
                <CardContent className="flex flex-col gap-2 text-sm">
                  <p className="flex justify-between gap-4">
                    <span className="text-muted-foreground">Subtotal</span>
                    <MoneyText amount={order.subtotal.amount} currency={order.subtotal.currency} />
                  </p>
                  <p className="flex justify-between gap-4">
                    <span className="text-muted-foreground">Tax</span>
                    <MoneyText amount={order.tax_total.amount} currency={order.tax_total.currency} />
                  </p>
                  <Separator className="my-1" />
                  <p className="flex justify-between gap-4 text-base font-medium">
                    <span>Total</span>
                    <MoneyText amount={order.grand_total.amount} currency={order.grand_total.currency} />
                  </p>
                  {order.notes ? (
                    <>
                      <Separator className="my-1" />
                      <p className="text-xs font-medium text-muted-foreground">Your notes</p>
                      <p className="whitespace-pre-line">{order.notes}</p>
                    </>
                  ) : null}
                </CardContent>
              </Card>
            </div>
          </>
        ) : null}
      </QueryState>
    </section>
  )
}

function ReturnRequestDialog({ order }: { order: OrderDetail }) {
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [reason, setReason] = useState('')
  const [quantities, setQuantities] = useState<Record<string, number>>({})
  const [idempotencyKey, setIdempotencyKey] = useState(() => crypto.randomUUID())
  const returnable = order.items.filter((item) => Number(item.quantity_returnable) > 0)
  const selected = returnable.filter((item) => (quantities[item.id] ?? 0) > 0)

  const submit = useMutation({
    mutationFn: () =>
      returnsApi.create(
        {
          order_id: order.id,
          reason: reason.trim(),
          items: selected.map((item) => ({ order_item_id: item.id, quantity: (quantities[item.id] ?? 0).toFixed(4) })),
        },
        idempotencyKey,
      ),
    onSuccess: (created) => {
      void queryClient.invalidateQueries({ queryKey: ['portal'] })
      toast.success(`Return ${created.reference} requested. We will contact you with next steps.`)
      setOpen(false)
    },
    onError: () => setIdempotencyKey(crypto.randomUUID()),
  })

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        if (next) {
          setReason('')
          setQuantities({})
          submit.reset()
        }
      }}
    >
      <DialogTrigger render={<Button variant="outline" />}>
        <Undo2Icon data-icon="inline-start" />
        Return items
      </DialogTrigger>
      <DialogContent className="sm:max-w-lg">
        <form
          className="flex flex-col gap-4"
          onSubmit={(event) => {
            event.preventDefault()
            if (selected.length > 0 && reason.trim()) submit.mutate()
          }}
        >
          <DialogHeader>
            <DialogTitle>Return items from {order.reference}</DialogTitle>
            <DialogDescription>Choose how many of each piece you are sending back.</DialogDescription>
          </DialogHeader>

          {submit.isError ? (
            <Alert variant="destructive">
              <AlertCircleIcon />
              <AlertDescription>{submit.error.message}</AlertDescription>
            </Alert>
          ) : null}

          <ul className="flex flex-col divide-y rounded-lg border">
            {returnable.map((item) => (
              <li key={item.id} className="flex items-center justify-between gap-3 p-3">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{item.product_name}</p>
                  <p className="text-xs text-muted-foreground">
                    {item.variant_name} · up to {formatQuantity(item.quantity_returnable)}
                  </p>
                </div>
                <QuantityStepper
                  size="sm"
                  min={0}
                  max={Math.floor(Number(item.quantity_returnable))}
                  value={quantities[item.id] ?? 0}
                  onChange={(value) => setQuantities((current) => ({ ...current, [item.id]: value }))}
                  label={`Quantity of ${item.product_name} to return`}
                />
              </li>
            ))}
          </ul>

          <Field>
            <FieldLabel htmlFor="return-reason">What happened?</FieldLabel>
            <Textarea
              id="return-reason"
              rows={3}
              required
              placeholder="e.g. Two bowls arrived chipped."
              value={reason}
              onChange={(e) => setReason(e.target.value)}
            />
          </Field>

          <DialogFooter>
            <Button type="submit" disabled={submit.isPending || selected.length === 0 || !reason.trim()}>
              {submit.isPending ? <Spinner data-icon="inline-start" /> : null}
              Request return
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
