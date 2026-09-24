import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { AlertCircleIcon, FileTextIcon, PackageCheckIcon, PackageIcon, TruckIcon } from 'lucide-react'
import { toast } from 'sonner'
import { StatusBadge } from '@/components/StatusBadge'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
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
import { Spinner } from '@/components/ui/spinner'
import { Textarea } from '@/components/ui/textarea'
import { QuantityStepper } from '@/components/QuantityStepper'
import { PermissionGate } from '@/features/auth/components/PermissionGate'
import { deliveriesApi, invoicesApi, type DeliverySummary } from '@/lib/api/finance'
import type { OrderDetail } from '@/lib/api/orders'
import { PERMISSIONS } from '@/lib/auth/permissions'
import { formatDate, formatQuantity } from '@/lib/format'

type OrderItem = OrderDetail['items'][number]

/** Units reserved for this line that are not delivered or already on an open delivery. */
function readyToShip(item: OrderItem): number {
  const ready =
    Number(item.quantity_reserved) - Number(item.quantity_delivered) - Number(item.quantity_in_open_deliveries)
  return Math.max(0, Math.min(Number(item.quantity_deliverable), Math.floor(ready)))
}

/** The one next step for a delivery, so staff never have to know the state machine. */
const NEXT_STEP: Record<string, { status: string; label: string; icon: typeof PackageIcon } | undefined> = {
  READY_TO_DELIVER: { status: 'PACKED', label: 'Mark packed', icon: PackageIcon },
  PACKED: { status: 'DISPATCHED', label: 'Dispatch', icon: TruckIcon },
  DISPATCHED: { status: 'DELIVERED', label: 'Mark delivered', icon: PackageCheckIcon },
  IN_TRANSIT: { status: 'DELIVERED', label: 'Mark delivered', icon: PackageCheckIcon },
  DELIVERY_EXCEPTION: { status: 'DISPATCHED', label: 'Dispatch again', icon: TruckIcon },
}

export function OrderFulfillment({ order }: { order: OrderDetail }) {
  const { data: deliveries, isLoading } = useQuery({
    queryKey: ['admin', 'deliveries', order.id],
    queryFn: () => deliveriesApi.list(order.id),
  })

  const items = deliveries?.items ?? []

  return (
    <Card>
      <CardHeader>
        <CardTitle>Deliveries</CardTitle>
        <CardDescription>
          {order.can_create_delivery
            ? 'Ship all or part of the order. Each delivery can be invoiced once it is on its way.'
            : items.length === 0
              ? 'Confirm the order to reserve stock before shipping.'
              : 'Every ordered unit is on a delivery.'}
        </CardDescription>
        {order.can_create_delivery ? (
          <PermissionGate permission={PERMISSIONS.salesOrdersManage}>
            <CardAction>
              <CreateDeliveryDialog order={order} />
            </CardAction>
          </PermissionGate>
        ) : null}
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        {isLoading ? <p className="text-sm text-muted-foreground">Loading deliveries…</p> : null}
        {!isLoading && items.length === 0 ? (
          <p className="text-sm text-muted-foreground">No deliveries yet.</p>
        ) : null}
        {items.map((delivery) => (
          <DeliveryRow key={delivery.id} delivery={delivery} orderId={order.id} />
        ))}
      </CardContent>
    </Card>
  )
}

function DeliveryRow({ delivery, orderId }: { delivery: DeliverySummary; orderId: string }) {
  const queryClient = useQueryClient()
  const next = NEXT_STEP[delivery.status]

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['admin', 'deliveries', orderId] })
    void queryClient.invalidateQueries({ queryKey: ['admin', 'order', orderId] })
    void queryClient.invalidateQueries({ queryKey: ['admin', 'dashboard'] })
  }

  const transition = useMutation({
    mutationFn: (status: string) => deliveriesApi.transition(delivery.id, status),
    onSuccess: (updated) => {
      refresh()
      toast.success(`${updated.reference} is now ${updated.status.replace(/_/g, ' ').toLowerCase()}.`)
    },
    onError: (error) => toast.error(error.message),
  })

  const createInvoice = useMutation({
    mutationFn: () => invoicesApi.createFromDelivery(delivery.id),
    onSuccess: () => {
      refresh()
      void queryClient.invalidateQueries({ queryKey: ['admin', 'invoices'] })
      toast.success(`Draft invoice created for ${delivery.reference}. Issue it from Invoices when ready.`)
    },
    onError: (error) => toast.error(error.message),
  })

  // Invoicing a shipment before it leaves is unusual; offer it once it is dispatched.
  const canInvoice =
    !delivery.invoice && !delivery.is_replacement && ['DISPATCHED', 'IN_TRANSIT', 'DELIVERED'].includes(delivery.status)

  return (
    <div className="flex flex-col gap-3 rounded-lg border p-3 sm:flex-row sm:items-start sm:justify-between">
      <div className="flex min-w-0 flex-col gap-1.5">
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-medium">{delivery.reference}</span>
          <StatusBadge status={delivery.status} />
          {delivery.is_replacement ? <StatusBadge status="REPLACEMENT" label="Replacement" /> : null}
        </div>
        {delivery.lines && delivery.lines.length > 0 ? (
          <ul className="text-sm text-muted-foreground">
            {delivery.lines.map((line) => (
              <li key={line.id}>
                <span className="tabular-nums text-foreground">{formatQuantity(line.quantity)} ×</span> {line.product_name}{' '}
                — {line.variant_name}
              </li>
            ))}
          </ul>
        ) : null}
        <p className="text-xs text-muted-foreground">
          Created {formatDate(delivery.created_at)}
          {delivery.dispatched_at ? ` · Dispatched ${formatDate(delivery.dispatched_at)}` : ''}
          {delivery.delivered_at ? ` · Delivered ${formatDate(delivery.delivered_at)}` : ''}
        </p>
        {delivery.invoice ? (
          <Link
            to="/admin/invoices"
            className="inline-flex w-fit items-center gap-1 text-xs text-muted-foreground underline-offset-4 hover:underline"
          >
            <FileTextIcon className="size-3.5" />
            Invoice {delivery.invoice.document_number ?? '(draft)'} · {delivery.invoice.status.toLowerCase().replace(/_/g, ' ')}
          </Link>
        ) : null}
      </div>
      <PermissionGate permission={PERMISSIONS.salesOrdersManage}>
        <div className="flex shrink-0 flex-wrap gap-2">
          {next ? (
            <Button size="sm" disabled={transition.isPending} onClick={() => transition.mutate(next.status)}>
              {transition.isPending ? <Spinner data-icon="inline-start" /> : <next.icon data-icon="inline-start" />}
              {next.label}
            </Button>
          ) : null}
          {canInvoice ? (
            <Button size="sm" variant="outline" disabled={createInvoice.isPending} onClick={() => createInvoice.mutate()}>
              {createInvoice.isPending ? <Spinner data-icon="inline-start" /> : <FileTextIcon data-icon="inline-start" />}
              Create invoice
            </Button>
          ) : null}
        </div>
      </PermissionGate>
    </div>
  )
}

function CreateDeliveryDialog({ order }: { order: OrderDetail }) {
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [notes, setNotes] = useState('')
  const [quantities, setQuantities] = useState<Record<string, number>>({})

  const deliverableItems = order.items.filter((item) => Number(item.quantity_deliverable) > 0)
  const totalUnits = deliverableItems.reduce((sum, item) => sum + (quantities[item.id] ?? 0), 0)
  const overReserved = deliverableItems.some((item) => (quantities[item.id] ?? 0) > readyToShip(item))

  const reset = () => {
    setNotes('')
    setQuantities(Object.fromEntries(deliverableItems.map((item) => [item.id, readyToShip(item)])))
  }

  const create = useMutation({
    mutationFn: () =>
      deliveriesApi.createFromOrder(
        order.id,
        deliverableItems
          .filter((item) => (quantities[item.id] ?? 0) > 0)
          .map((item) => ({ order_item_id: item.id, quantity: (quantities[item.id] ?? 0).toFixed(4) })),
        notes.trim() || undefined,
      ),
    onSuccess: (delivery) => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'deliveries', order.id] })
      void queryClient.invalidateQueries({ queryKey: ['admin', 'order', order.id] })
      toast.success(`Delivery ${delivery.reference} created. Pack it and dispatch when it leaves.`)
      setOpen(false)
    },
  })

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        if (next) {
          reset()
          create.reset()
        }
      }}
    >
      <DialogTrigger render={<Button size="sm" />}>
        <TruckIcon data-icon="inline-start" />
        New delivery
      </DialogTrigger>
      <DialogContent className="sm:max-w-xl">
        <form
          className="flex flex-col gap-4"
          onSubmit={(event) => {
            event.preventDefault()
            if (totalUnits > 0) create.mutate()
          }}
        >
          <DialogHeader>
            <DialogTitle>New delivery for {order.reference}</DialogTitle>
            <DialogDescription>
              Quantities start at what is reserved and not yet shipped. Set a line to 0 to leave it for a later
              delivery.
            </DialogDescription>
          </DialogHeader>

          {create.isError ? (
            <Alert variant="destructive">
              <AlertCircleIcon />
              <AlertDescription>{create.error.message}</AlertDescription>
            </Alert>
          ) : null}

          <ul className="flex flex-col divide-y rounded-lg border">
            {deliverableItems.map((item) => (
              <li key={item.id} className="flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">
                    {item.product_name} — {item.variant_name}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {item.sku} · {formatQuantity(item.quantity_deliverable)} left to ship ·{' '}
                    {formatQuantity(readyToShip(item))} in stock for this order
                  </p>
                </div>
                <QuantityStepper
                  size="sm"
                  min={0}
                  max={Math.floor(Number(item.quantity_deliverable))}
                  value={quantities[item.id] ?? 0}
                  onChange={(value) => setQuantities((current) => ({ ...current, [item.id]: value }))}
                  label={`Quantity of ${item.sku} to deliver`}
                />
              </li>
            ))}
          </ul>

          {overReserved ? (
            <Alert>
              <AlertCircleIcon />
              <AlertDescription>
                Some lines exceed the stock reserved for this order. Only ship them if the pieces are physically
                available.
              </AlertDescription>
            </Alert>
          ) : null}

          <Field>
            <FieldLabel htmlFor="delivery-notes">Notes for the driver or packer (optional)</FieldLabel>
            <Textarea id="delivery-notes" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
          </Field>

          <DialogFooter>
            <Button type="submit" disabled={create.isPending || totalUnits === 0}>
              {create.isPending ? <Spinner data-icon="inline-start" /> : null}
              Create delivery ({formatQuantity(totalUnits)} {totalUnits === 1 ? 'unit' : 'units'})
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
