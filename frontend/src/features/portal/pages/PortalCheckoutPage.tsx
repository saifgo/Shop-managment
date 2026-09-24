import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { AlertCircleIcon, ArrowLeftIcon, ShoppingBagIcon, Trash2Icon, TruckIcon } from 'lucide-react'
import { toast } from 'sonner'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { ProductImage } from '@/components/ProductImage'
import { QuantityStepper } from '@/components/QuantityStepper'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Empty,
  EmptyContent,
  EmptyDescription,
  EmptyHeader,
  EmptyMedia,
  EmptyTitle,
} from '@/components/ui/empty'
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field'
import { Separator } from '@/components/ui/separator'
import { Skeleton } from '@/components/ui/skeleton'
import { Spinner } from '@/components/ui/spinner'
import { Textarea } from '@/components/ui/textarea'
import { useCart, type CartItem } from '@/features/portal/context/CartContext'
import { ordersApi, type CartLine } from '@/lib/api/orders'
import { formatQuantity } from '@/lib/format'

export function PortalCheckoutPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { items, updateQuantity, removeItem, clear } = useCart()
  const [notes, setNotes] = useState('')
  // One key per checkout attempt: a double click or retry cannot create two orders.
  const [idempotencyKey, setIdempotencyKey] = useState(() => crypto.randomUUID())

  const cartPayload = items.map((item) => ({ variant_id: item.variantId, quantity: item.quantity }))

  const { data: validation, isLoading, error, isFetching } = useQuery({
    queryKey: ['portal', 'cart-validate', cartPayload],
    queryFn: () => ordersApi.validateCart(cartPayload),
    enabled: cartPayload.length > 0,
    placeholderData: keepPreviousData,
  })

  const submitOrder = useMutation({
    mutationFn: () => ordersApi.createOrder(cartPayload, notes.trim() || undefined, undefined, idempotencyKey),
    onSuccess: (order) => {
      clear()
      void queryClient.invalidateQueries({ queryKey: ['portal'] })
      toast.success(`Order ${order.reference} placed. We will confirm it shortly.`)
      navigate(`/portal/orders/${order.id}`)
    },
    onError: () => setIdempotencyKey(crypto.randomUUID()),
  })

  if (items.length === 0) {
    return (
      <section className="flex flex-col gap-6">
        <PageHeader title="Your cart" />
        <Empty className="border border-dashed">
          <EmptyHeader>
            <EmptyMedia variant="icon">
              <ShoppingBagIcon />
            </EmptyMedia>
            <EmptyTitle>Your cart is empty</EmptyTitle>
            <EmptyDescription>Browse the shop and add the pieces you need.</EmptyDescription>
          </EmptyHeader>
          <EmptyContent>
            <Button nativeButton={false} render={<Link to="/portal/catalog" />}>
              Browse the shop
            </Button>
          </EmptyContent>
        </Empty>
      </section>
    )
  }

  const unitCount = items.reduce((sum, item) => sum + Number(item.quantity), 0)
  const lineFor = (item: CartItem) => validation?.lines.find((line) => line.variant_id === item.variantId)
  const hasBackorders = validation?.lines.some((line) => line.will_backorder) ?? false

  return (
    <section className="flex flex-col gap-6">
      <Link
        to="/portal/catalog"
        className="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeftIcon className="size-4" />
        Continue shopping
      </Link>
      <PageHeader
        title="Your cart"
        description={`${formatQuantity(unitCount)} ${unitCount === 1 ? 'item' : 'items'} · prices and stock are checked live`}
      />

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
        <Card>
          <CardContent className="p-0">
            <ul className="divide-y">
              {items.map((item) => (
                <CartRow
                  key={item.variantId}
                  item={item}
                  line={lineFor(item)}
                  onQuantity={(quantity) => updateQuantity(item.variantId, quantity.toFixed(4))}
                  onRemove={() => removeItem(item.variantId)}
                />
              ))}
            </ul>
          </CardContent>
        </Card>

        <Card className="lg:sticky lg:top-20">
          <CardHeader>
            <CardTitle>Order summary</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-3 text-sm">
            {error ? (
              <Alert variant="destructive">
                <AlertCircleIcon />
                <AlertTitle>Unable to check your cart</AlertTitle>
                <AlertDescription>{error.message}</AlertDescription>
              </Alert>
            ) : null}
            {isLoading ? (
              <div className="flex flex-col gap-2">
                <Skeleton className="h-4 w-full" />
                <Skeleton className="h-4 w-full" />
                <Skeleton className="h-6 w-full" />
              </div>
            ) : null}
            {validation ? (
              <div className={isFetching ? 'flex flex-col gap-2 opacity-60' : 'flex flex-col gap-2'}>
                <p className="flex justify-between gap-4">
                  <span className="text-muted-foreground">Subtotal</span>
                  <MoneyText amount={validation.subtotal.amount} currency={validation.subtotal.currency} />
                </p>
                <p className="flex justify-between gap-4">
                  <span className="text-muted-foreground">Tax</span>
                  <MoneyText amount={validation.tax_total.amount} currency={validation.tax_total.currency} />
                </p>
                <Separator className="my-1" />
                <p className="flex justify-between gap-4 text-base font-medium">
                  <span>Total</span>
                  <MoneyText amount={validation.grand_total.amount} currency={validation.grand_total.currency} />
                </p>
              </div>
            ) : null}

            {validation && !validation.valid ? (
              <Alert variant="destructive">
                <AlertCircleIcon />
                <AlertTitle>Some items are not available</AlertTitle>
                <AlertDescription>Lower the quantity or remove the items marked below.</AlertDescription>
              </Alert>
            ) : null}
            {hasBackorders && validation?.valid ? (
              <Alert>
                <TruckIcon />
                <AlertDescription>
                  Some pieces will be made to order. In-stock items can ship first; the rest follows when ready.
                </AlertDescription>
              </Alert>
            ) : null}

            <Field>
              <FieldLabel htmlFor="order-notes">Notes (optional)</FieldLabel>
              <Textarea
                id="order-notes"
                rows={3}
                maxLength={1000}
                placeholder="Delivery instructions, preferred date, packaging…"
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
              />
              <FieldDescription>Delivered to the address on your account.</FieldDescription>
            </Field>

            {submitOrder.isError ? (
              <Alert variant="destructive">
                <AlertCircleIcon />
                <AlertTitle>Order not placed</AlertTitle>
                <AlertDescription>{submitOrder.error.message}</AlertDescription>
              </Alert>
            ) : null}
          </CardContent>
          <CardFooter>
            <Button
              size="lg"
              className="w-full"
              disabled={!validation?.valid || isFetching || submitOrder.isPending}
              onClick={() => submitOrder.mutate()}
            >
              {submitOrder.isPending ? <Spinner data-icon="inline-start" /> : null}
              {submitOrder.isPending ? 'Placing order…' : 'Place order'}
            </Button>
          </CardFooter>
        </Card>
      </div>
    </section>
  )
}

function CartRow({
  item,
  line,
  onQuantity,
  onRemove,
}: {
  item: CartItem
  line?: CartLine
  onQuantity: (quantity: number) => void
  onRemove: () => void
}) {
  return (
    <li className="flex gap-4 p-4">
      <Link
        to={`/portal/catalog/${item.productId}`}
        className="size-20 shrink-0 overflow-hidden rounded-lg border"
      >
        <ProductImage url={item.imageUrl} alt="" className="size-full" />
      </Link>
      <div className="flex min-w-0 flex-1 flex-col gap-2">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <Link to={`/portal/catalog/${item.productId}`} className="font-medium underline-offset-4 hover:underline">
              {item.productName}
            </Link>
            <p className="text-sm text-muted-foreground">
              {item.variantName} · {item.sku}
            </p>
            {line ? (
              <p className="text-sm text-muted-foreground">
                <MoneyText amount={line.unit_price.amount} currency={line.unit_price.currency} /> each
              </p>
            ) : null}
          </div>
          <span className="text-right font-medium">
            {line ? <MoneyText amount={line.line_total.amount} currency={line.line_total.currency} /> : '—'}
          </span>
        </div>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <QuantityStepper
            size="sm"
            value={Number(item.quantity)}
            min={1}
            onChange={onQuantity}
            label={`Quantity for ${item.productName} ${item.variantName}`}
          />
          <Button variant="ghost" size="sm" onClick={onRemove}>
            <Trash2Icon data-icon="inline-start" />
            Remove
          </Button>
        </div>
        {line?.blocked ? (
          <p className="text-sm text-destructive">
            Only {formatQuantity(line.availability.available_to_sell)} available — this piece is not made to order.
          </p>
        ) : line?.will_backorder ? (
          <p className="text-sm text-amber-700">
            {Number(line.availability.available_to_sell) > 0
              ? `${formatQuantity(line.availability.available_to_sell)} in stock, the rest made to order.`
              : 'Made to order.'}
          </p>
        ) : null}
      </div>
    </li>
  )
}
