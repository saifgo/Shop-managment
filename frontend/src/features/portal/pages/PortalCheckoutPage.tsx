import { useMutation, useQuery } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { useCart } from '@/features/portal/context/CartContext'
import { ordersApi } from '@/lib/api/orders'
import { AlertCircleIcon } from 'lucide-react'

export function PortalCheckoutPage() {
  const navigate = useNavigate()
  const { items, updateQuantity, removeItem, clear } = useCart()

  const cartPayload = items.map((item) => ({
    variant_id: item.variantId,
    quantity: item.quantity,
  }))

  const { data: validation, isLoading, error } = useQuery({
    queryKey: ['portal', 'cart-validate', cartPayload],
    queryFn: () => ordersApi.validateCart(cartPayload),
    enabled: cartPayload.length > 0,
  })

  const submitOrder = useMutation({
    mutationFn: () => ordersApi.createOrder(cartPayload),
    onSuccess: (order) => {
      clear()
      navigate(`/portal/orders/${order.id}`)
    },
  })

  if (items.length === 0) {
    return (
      <section className="flex flex-col gap-6">
        <PageHeader title="Checkout" />
        <QueryState
          isEmpty
          emptyTitle="Your cart is empty"
          emptyDescription="Add products from the catalog before checking out."
          emptyAction={
            <Button nativeButton={false} render={<Link to="/portal/catalog" />}>
              Browse catalog
            </Button>
          }
        >
          {null}
        </QueryState>
      </section>
    )
  }

  return (
    <section className="flex flex-col gap-6">
      <PageHeader title="Checkout" description="Prices and availability are validated by the server before submission." />

      <div className="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <ResponsiveTable
          data={items}
          getRowKey={(item) => item.variantId}
          columns={[
            {
              key: 'product',
              header: 'Product',
              primary: true,
              cell: (item) => (
                <span className="flex flex-col gap-0.5">
                  <span className="font-medium">{item.productName}</span>
                  <span className="text-sm text-muted-foreground">
                    {item.variantName} ({item.sku})
                  </span>
                </span>
              ),
            },
            {
              key: 'qty',
              header: 'Qty',
              cell: (item) => (
                <Input
                  type="number"
                  min="1"
                  step="1"
                  value={Number(item.quantity)}
                  onChange={(e) => updateQuantity(item.variantId, Number(e.target.value).toFixed(4))}
                  aria-label={`Quantity for ${item.variantName}`}
                  className="w-20"
                />
              ),
            },
            {
              key: 'availability',
              header: 'Availability',
              cell: (item) => {
                const validatedLine = validation?.lines.find((line) => line.variant_id === item.variantId)
                if (!validatedLine) {
                  return <span className="text-sm text-muted-foreground">Checking…</span>
                }
                return (
                  <span className="flex flex-col gap-1">
                    <span className="tabular-nums">{validatedLine.availability.available_to_sell} available</span>
                    {validatedLine.will_backorder ? <Badge variant="secondary">Will backorder</Badge> : null}
                    {validatedLine.blocked ? <span className="text-sm text-destructive">Blocked</span> : null}
                  </span>
                )
              },
            },
            {
              key: 'total',
              header: 'Line total',
              cell: (item) => {
                const validatedLine = validation?.lines.find((line) => line.variant_id === item.variantId)
                return validatedLine ? (
                  <MoneyText amount={validatedLine.line_total.amount} currency={validatedLine.line_total.currency} />
                ) : (
                  '—'
                )
              },
            },
          ]}
          rowAction={(item) => (
            <Button variant="outline" size="sm" onClick={() => removeItem(item.variantId)}>
              Remove
            </Button>
          )}
        />

        <Card>
          <CardHeader>
            <CardTitle>Order summary</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            {isLoading ? <p className="text-sm text-muted-foreground">Validating cart…</p> : null}
            {error ? (
              <Alert variant="destructive">
                <AlertCircleIcon />
                <AlertTitle>Unable to validate cart</AlertTitle>
                <AlertDescription>Refresh and try again.</AlertDescription>
              </Alert>
            ) : null}
            {validation ? (
              <>
                <p className="flex justify-between gap-4">
                  <span className="text-muted-foreground">Subtotal</span>
                  <MoneyText amount={validation.subtotal.amount} currency={validation.subtotal.currency} />
                </p>
                <p className="flex justify-between gap-4">
                  <span className="text-muted-foreground">Tax</span>
                  <MoneyText amount={validation.tax_total.amount} currency={validation.tax_total.currency} />
                </p>
                <p className="flex justify-between gap-4 font-medium">
                  <span>Total</span>
                  <MoneyText amount={validation.grand_total.amount} currency={validation.grand_total.currency} />
                </p>
                {!validation.valid ? (
                  <Alert variant="destructive">
                    <AlertCircleIcon />
                    <AlertTitle>Some items cannot be ordered</AlertTitle>
                    <AlertDescription>Some items cannot be ordered due to stock policy.</AlertDescription>
                  </Alert>
                ) : null}
              </>
            ) : null}
          </CardContent>
          <CardFooter>
            {validation ? (
              <Button
                className="w-full"
                disabled={!validation.valid || submitOrder.isPending}
                onClick={() => submitOrder.mutate()}
              >
                {submitOrder.isPending ? <Spinner data-icon="inline-start" /> : null}
                {submitOrder.isPending ? 'Submitting…' : 'Submit order'}
              </Button>
            ) : null}
          </CardFooter>
        </Card>
      </div>
    </section>
  )
}
