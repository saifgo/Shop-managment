import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { toast } from 'sonner'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { StatusBadge } from '@/components/StatusBadge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { purchasingApi, type PurchaseOrderItem } from '@/lib/api/purchasing'
import { PERMISSIONS } from '@/lib/auth/permissions'

const DECIMAL = /^\d+(\.\d{1,4})?$/

/** Rounded to the API's 4-decimal quantity scale to avoid float artifacts like 0.30000000000000004. */
function remaining(item: PurchaseOrderItem) {
  return Math.max(0, Number((Number(item.quantity_ordered) - Number(item.quantity_received)).toFixed(4)))
}

export function AdminPurchaseOrderDetailPage() {
  const { id } = useParams()
  const queryClient = useQueryClient()
  const { can } = useAuth()
  const [receiving, setReceiving] = useState<Record<string, string>>({})

  const { data: po, isLoading, error } = useQuery({
    queryKey: ['admin', 'purchase-order', id],
    queryFn: () => purchasingApi.getPurchaseOrder(id!),
    enabled: Boolean(id),
  })

  const receive = useMutation({
    mutationFn: (lines: Array<{ purchase_order_item_id: string; quantity: string }>) =>
      purchasingApi.receivePurchaseOrder(id!, lines, crypto.randomUUID()),
    onSuccess: (receipt) => {
      toast.success(`Receipt ${receipt.reference} recorded. Stock updated.`)
      setReceiving({})
      void queryClient.invalidateQueries({ queryKey: ['admin', 'purchase-order', id] })
      void queryClient.invalidateQueries({ queryKey: ['admin', 'purchase-orders'] })
    },
    onError: (err) => toast.error(err.message),
  })

  const items = po?.items ?? []
  const openItems = items.filter((item) => remaining(item) > 0)
  const canReceive = can(PERMISSIONS.purchasingManage) && openItems.length > 0 && po?.status !== 'CANCELLED'

  const receiptLines = openItems
    .map((item) => ({ purchase_order_item_id: item.id, quantity: receiving[item.id] ?? '' }))
    .filter((line) => line.quantity !== '' && Number(line.quantity) > 0)
  const receiptInvalid = openItems.some((item) => {
    const value = receiving[item.id]
    return value !== undefined && value !== '' && (!DECIMAL.test(value) || Number(value) > remaining(item))
  })

  return (
    <section className="flex flex-col gap-6">
      <Link to="/admin/purchasing" className="text-sm text-muted-foreground">
        Purchase orders
      </Link>

      <QueryState isLoading={isLoading} error={error || (!isLoading && !po) ? 'Purchase order not found.' : null}>
        {po ? (
          <>
            <PageHeader
              title={po.reference}
              description={po.supplier_name}
              action={<StatusBadge status={po.status} />}
            />

            <Card>
              <CardHeader>
                <CardTitle>Summary</CardTitle>
              </CardHeader>
              <CardContent>
                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                  <dt className="text-muted-foreground">Total</dt>
                  <dd>
                    <MoneyText amount={po.grand_total.amount} currency={po.grand_total.currency} />
                  </dd>
                  <dt className="text-muted-foreground">Created</dt>
                  <dd>{new Date(po.created_at).toLocaleString()}</dd>
                  <dt className="text-muted-foreground">Expected</dt>
                  <dd>{po.expected_at ? new Date(po.expected_at).toLocaleDateString() : '—'}</dd>
                  {po.notes ? (
                    <>
                      <dt className="text-muted-foreground">Notes</dt>
                      <dd className="whitespace-pre-line">{po.notes}</dd>
                    </>
                  ) : null}
                </dl>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Items</CardTitle>
              </CardHeader>
              <CardContent>
                <div className="overflow-x-auto [contain:inline-size]">
                  <Table className="min-w-[40rem]">
                    <TableHeader>
                      <TableRow>
                        <TableHead>Product</TableHead>
                        <TableHead className="text-right">Ordered</TableHead>
                        <TableHead className="text-right">Received</TableHead>
                        <TableHead className="text-right">Unit price</TableHead>
                        <TableHead className="text-right">Total</TableHead>
                        {canReceive ? <TableHead className="w-32">Receive now</TableHead> : null}
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {items.map((item) => {
                        const open = remaining(item)
                        const value = receiving[item.id] ?? ''
                        const invalid = value !== '' && (!DECIMAL.test(value) || Number(value) > open)
                        return (
                          <TableRow key={item.id}>
                            <TableCell>
                              <div className="flex flex-col">
                                <span>
                                  {item.product_name} — {item.variant_name}
                                </span>
                                <span className="text-xs text-muted-foreground">{item.sku}</span>
                              </div>
                            </TableCell>
                            <TableCell className="text-right tabular-nums">{Number(item.quantity_ordered)}</TableCell>
                            <TableCell className="text-right tabular-nums">{Number(item.quantity_received)}</TableCell>
                            <TableCell className="text-right">
                              <MoneyText amount={item.unit_price.amount} currency={item.unit_price.currency} />
                            </TableCell>
                            <TableCell className="text-right">
                              <MoneyText amount={item.line_total.amount} currency={item.line_total.currency} />
                            </TableCell>
                            {canReceive ? (
                              <TableCell>
                                {open > 0 ? (
                                  <Input
                                    aria-label={`Quantity to receive for ${item.sku}`}
                                    inputMode="decimal"
                                    placeholder={`max ${open}`}
                                    value={value}
                                    aria-invalid={invalid || undefined}
                                    onChange={(event) =>
                                      setReceiving((current) => ({ ...current, [item.id]: event.target.value }))
                                    }
                                  />
                                ) : (
                                  <span className="text-xs text-muted-foreground">Complete</span>
                                )}
                              </TableCell>
                            ) : null}
                          </TableRow>
                        )
                      })}
                    </TableBody>
                  </Table>
                </div>
              </CardContent>
              {canReceive ? (
                <CardFooter className="flex flex-wrap justify-end gap-2">
                  <Button
                    variant="outline"
                    onClick={() =>
                      setReceiving(Object.fromEntries(openItems.map((item) => [item.id, String(remaining(item))])))
                    }
                  >
                    Fill remaining
                  </Button>
                  <Button
                    disabled={receiptLines.length === 0 || receiptInvalid || receive.isPending}
                    onClick={() => receive.mutate(receiptLines)}
                  >
                    {receive.isPending ? <Spinner data-icon="inline-start" /> : null}
                    Record receipt
                  </Button>
                </CardFooter>
              ) : null}
            </Card>
          </>
        ) : null}
      </QueryState>
    </section>
  )
}
