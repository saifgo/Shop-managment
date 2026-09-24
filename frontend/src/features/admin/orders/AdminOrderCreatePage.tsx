import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { AlertCircleIcon, Trash2Icon } from 'lucide-react'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Field, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import { CustomerSelect } from '@/features/admin/components/CustomerSelect'
import { VariantPicker, type PickedVariant } from '@/features/admin/components/VariantPicker'
import { ordersApi } from '@/lib/api/orders'

interface OrderLine extends PickedVariant {
  quantity: string
}

function isPositiveQuantity(value: string) {
  return /^\d+(\.\d{1,4})?$/.test(value) && Number(value) > 0
}

export function AdminOrderCreatePage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [idempotencyKey] = useState(() => crypto.randomUUID())
  const [customerId, setCustomerId] = useState('')
  const [lines, setLines] = useState<OrderLine[]>([])
  const [notes, setNotes] = useState('')
  const [confirmNow, setConfirmNow] = useState(true)

  const items = lines.map((line) => ({ variant_id: line.variant_id, quantity: line.quantity }))
  const linesValid = lines.length > 0 && lines.every((line) => isPositiveQuantity(line.quantity))

  // Prices come from the server so customer overrides and stock policy apply.
  const pricing = useQuery({
    queryKey: ['admin', 'order-create', 'validate', customerId, items],
    queryFn: () => ordersApi.validateCart(items, customerId),
    enabled: customerId !== '' && linesValid,
    placeholderData: (previous) => previous,
  })

  const createOrder = useMutation({
    mutationFn: async () => {
      const order = await ordersApi.createOrder(items, notes.trim() || undefined, customerId, idempotencyKey)
      return confirmNow ? ordersApi.confirmOrder(order.id) : order
    },
    onSuccess: (order) => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'orders'] })
      navigate(`/admin/orders/${order.id}`)
    },
  })

  const addLine = (variant: PickedVariant) => {
    setLines((current) => {
      const existing = current.find((line) => line.variant_id === variant.variant_id)
      if (existing) {
        return current.map((line) =>
          line === existing ? { ...line, quantity: String(Number(line.quantity || '0') + 1) } : line,
        )
      }
      return [...current, { ...variant, quantity: '1' }]
    })
  }

  const updateQuantity = (variantId: string, quantity: string) =>
    setLines((current) => current.map((line) => (line.variant_id === variantId ? { ...line, quantity } : line)))

  const removeLine = (variantId: string) =>
    setLines((current) => current.filter((line) => line.variant_id !== variantId))

  const pricedLine = (variantId: string) => pricing.data?.lines.find((line) => line.variant_id === variantId)
  const hasBlockedLine = pricing.data?.valid === false
  const canSubmit = customerId !== '' && linesValid && !hasBlockedLine && !pricing.isFetching && !createOrder.isPending

  return (
    <section className="flex flex-col gap-6">
      <Link to="/admin/orders" className="text-sm text-muted-foreground">
        Orders
      </Link>
      <PageHeader title="New order" description="Create an order on behalf of a customer." />

      {createOrder.isError ? (
        <Alert variant="destructive">
          <AlertCircleIcon />
          <AlertTitle>Unable to create the order</AlertTitle>
          <AlertDescription>{createOrder.error.message}</AlertDescription>
        </Alert>
      ) : null}

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <div className="flex flex-col gap-6">
          <Card>
            <CardHeader>
              <CardTitle>Customer</CardTitle>
            </CardHeader>
            <CardContent>
              <Field>
                <FieldLabel htmlFor="order-customer">Customer</FieldLabel>
                <CustomerSelect id="order-customer" value={customerId} onChange={setCustomerId} />
                <FieldDescription>
                  Customer-specific prices are applied automatically.{' '}
                  <Link to="/admin/customers/new" className="underline underline-offset-4">
                    New customer
                  </Link>
                </FieldDescription>
              </Field>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="flex flex-row items-center justify-between gap-2">
              <CardTitle>Items</CardTitle>
              <VariantPicker onSelect={addLine} />
            </CardHeader>
            <CardContent>
              {lines.length === 0 ? (
                <p className="text-sm text-muted-foreground">No items yet. Add products from the catalog.</p>
              ) : (
                <div className="overflow-x-auto [contain:inline-size]">
                  <Table className="min-w-[36rem]">
                    <TableHeader>
                      <TableRow>
                        <TableHead>Product</TableHead>
                        <TableHead className="w-28">Quantity</TableHead>
                        <TableHead className="text-right">Unit price</TableHead>
                        <TableHead className="text-right">Total</TableHead>
                        <TableHead className="w-10">
                          <span className="sr-only">Remove</span>
                        </TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {lines.map((line) => {
                        const priced = pricedLine(line.variant_id)
                        const quantityValid = isPositiveQuantity(line.quantity)
                        return (
                          <TableRow key={line.variant_id}>
                            <TableCell>
                              <div className="flex flex-col gap-1">
                                <span>{line.label}</span>
                                <span className="text-xs text-muted-foreground">{line.sku}</span>
                                {priced?.blocked ? (
                                  <Badge variant="destructive">Not enough stock</Badge>
                                ) : priced?.will_backorder ? (
                                  <Badge variant="outline">Will be backordered</Badge>
                                ) : null}
                              </div>
                            </TableCell>
                            <TableCell>
                              <Input
                                aria-label={`Quantity for ${line.label}`}
                                inputMode="decimal"
                                value={line.quantity}
                                aria-invalid={!quantityValid || undefined}
                                onChange={(event) => updateQuantity(line.variant_id, event.target.value)}
                              />
                            </TableCell>
                            <TableCell className="text-right">
                              <MoneyText
                                amount={priced?.unit_price.amount ?? line.price.amount}
                                currency={priced?.unit_price.currency ?? line.price.currency}
                              />
                            </TableCell>
                            <TableCell className="text-right">
                              {priced ? (
                                <MoneyText amount={priced.line_total.amount} currency={priced.line_total.currency} />
                              ) : (
                                '—'
                              )}
                            </TableCell>
                            <TableCell>
                              <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                aria-label={`Remove ${line.label}`}
                                onClick={() => removeLine(line.variant_id)}
                              >
                                <Trash2Icon />
                              </Button>
                            </TableCell>
                          </TableRow>
                        )
                      })}
                    </TableBody>
                  </Table>
                </div>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Notes</CardTitle>
            </CardHeader>
            <CardContent>
              <Field>
                <FieldLabel htmlFor="order-notes" className="sr-only">
                  Notes
                </FieldLabel>
                <Textarea id="order-notes" rows={3} value={notes} onChange={(event) => setNotes(event.target.value)} />
              </Field>
            </CardContent>
          </Card>
        </div>

        <Card className="h-fit xl:sticky xl:top-18">
          <CardHeader>
            <CardTitle>Summary</CardTitle>
          </CardHeader>
          <CardContent>
            <FieldGroup>
              {pricing.error ? (
                <Alert variant="destructive">
                  <AlertCircleIcon />
                  <AlertDescription>{pricing.error.message}</AlertDescription>
                </Alert>
              ) : null}
              <dl className="flex flex-col gap-2 text-sm">
                <SummaryRow label="Subtotal" money={pricing.data?.subtotal} />
                <SummaryRow label="Tax" money={pricing.data?.tax_total} />
                <SummaryRow label="Total" money={pricing.data?.grand_total} strong />
              </dl>
              {hasBlockedLine ? (
                <p className="text-sm text-destructive">
                  Some items are out of stock and cannot be backordered. Reduce the quantity or remove them.
                </p>
              ) : null}
              <Field orientation="horizontal">
                <Checkbox id="order-confirm" checked={confirmNow} onCheckedChange={(checked) => setConfirmNow(checked)} />
                <FieldLabel htmlFor="order-confirm">Confirm and reserve stock now</FieldLabel>
              </Field>
            </FieldGroup>
          </CardContent>
          <CardFooter>
            <Button className="w-full" disabled={!canSubmit} onClick={() => createOrder.mutate()}>
              {createOrder.isPending ? <Spinner data-icon="inline-start" /> : null}
              Create order
            </Button>
          </CardFooter>
        </Card>
      </div>
    </section>
  )
}

function SummaryRow({
  label,
  money,
  strong,
}: {
  label: string
  money?: { amount: string; currency: string }
  strong?: boolean
}) {
  return (
    <div className={strong ? 'flex justify-between font-medium' : 'flex justify-between text-muted-foreground'}>
      <dt>{label}</dt>
      <dd>{money ? <MoneyText amount={money.amount} currency={money.currency} /> : '—'}</dd>
    </div>
  )
}
