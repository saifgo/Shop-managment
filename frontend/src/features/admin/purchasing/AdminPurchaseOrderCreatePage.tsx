import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { AlertCircleIcon, Trash2Icon } from 'lucide-react'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select'
import { Spinner } from '@/components/ui/spinner'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import { VariantPicker, type PickedVariant } from '@/features/admin/components/VariantPicker'
import { purchasingApi } from '@/lib/api/purchasing'

interface PoLine extends PickedVariant {
  quantity: string
  unitPrice: string
}

const DECIMAL = /^\d+(\.\d{1,4})?$/

export function AdminPurchaseOrderCreatePage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [idempotencyKey] = useState(() => crypto.randomUUID())
  const [supplierId, setSupplierId] = useState('')
  const [currency, setCurrency] = useState('TND')
  const [expectedAt, setExpectedAt] = useState('')
  const [notes, setNotes] = useState('')
  const [lines, setLines] = useState<PoLine[]>([])
  const [showErrors, setShowErrors] = useState(false)

  const suppliers = useQuery({
    queryKey: ['admin', 'suppliers', 'select'],
    queryFn: () => purchasingApi.listSuppliers({ per_page: 100 }),
  })

  const create = useMutation({
    mutationFn: () =>
      purchasingApi.createPurchaseOrder(
        {
          supplier_id: supplierId,
          currency,
          expected_at: expectedAt || undefined,
          notes: notes.trim() || undefined,
          items: lines.map((line) => ({ variant_id: line.variant_id, quantity: line.quantity, unit_price: line.unitPrice })),
        },
        idempotencyKey,
      ),
    onSuccess: (po) => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'purchase-orders'] })
      navigate(`/admin/purchasing/${po.id}`)
    },
  })

  const updateLine = (variantId: string, patch: Partial<PoLine>) =>
    setLines((current) => current.map((line) => (line.variant_id === variantId ? { ...line, ...patch } : line)))

  const addLine = (variant: PickedVariant) =>
    setLines((current) =>
      current.some((line) => line.variant_id === variant.variant_id)
        ? current
        : [...current, { ...variant, quantity: '1', unitPrice: '' }],
    )

  const lineInvalid = (line: PoLine) => ({
    quantity: !DECIMAL.test(line.quantity) || Number(line.quantity) <= 0,
    unitPrice: !DECIMAL.test(line.unitPrice),
  })

  const total = lines.reduce((sum, line) => sum + (Number(line.quantity) || 0) * (Number(line.unitPrice) || 0), 0)
  const valid =
    supplierId !== '' &&
    /^[A-Z]{3}$/.test(currency) &&
    lines.length > 0 &&
    lines.every((line) => !Object.values(lineInvalid(line)).some(Boolean))
  const activeSuppliers = (suppliers.data?.items ?? []).filter((supplier) => supplier.is_active)

  return (
    <section className="flex flex-col gap-6">
      <Link to="/admin/purchasing" className="text-sm text-muted-foreground">
        Purchase orders
      </Link>
      <PageHeader title="New purchase order" description="Order stock from a supplier. Receive it later to add it to inventory." />

      {create.isError ? (
        <Alert variant="destructive">
          <AlertCircleIcon />
          <AlertTitle>Unable to create the purchase order</AlertTitle>
          <AlertDescription>{create.error.message}</AlertDescription>
        </Alert>
      ) : null}

      <Card>
        <CardHeader>
          <CardTitle>Supplier</CardTitle>
        </CardHeader>
        <CardContent>
          <FieldGroup className="grid gap-4 md:grid-cols-3">
            <Field data-invalid={showErrors && supplierId === '' ? true : undefined}>
              <FieldLabel htmlFor="po-supplier">Supplier</FieldLabel>
              <NativeSelect
                id="po-supplier"
                className="w-full"
                value={supplierId}
                disabled={suppliers.isLoading}
                onChange={(event) => setSupplierId(event.target.value)}
              >
                <NativeSelectOption value="">
                  {suppliers.isLoading ? 'Loading suppliers…' : 'Select a supplier'}
                </NativeSelectOption>
                {activeSuppliers.map((supplier) => (
                  <NativeSelectOption key={supplier.id} value={supplier.id}>
                    {supplier.code} — {supplier.name}
                  </NativeSelectOption>
                ))}
              </NativeSelect>
              <FieldDescription>
                Missing one?{' '}
                <Link to="/admin/suppliers" className="underline underline-offset-4">
                  Add a supplier
                </Link>
              </FieldDescription>
            </Field>
            <Field>
              <FieldLabel htmlFor="po-currency">Currency</FieldLabel>
              <Input
                id="po-currency"
                maxLength={3}
                value={currency}
                aria-invalid={!/^[A-Z]{3}$/.test(currency) || undefined}
                onChange={(event) => setCurrency(event.target.value.toUpperCase())}
              />
            </Field>
            <Field>
              <FieldLabel htmlFor="po-expected">Expected delivery</FieldLabel>
              <Input id="po-expected" type="date" value={expectedAt} onChange={(event) => setExpectedAt(event.target.value)} />
            </Field>
          </FieldGroup>
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between gap-2">
          <CardTitle>Items</CardTitle>
          <VariantPicker onSelect={addLine} />
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          {lines.length === 0 ? (
            <p className={showErrors ? 'text-sm text-destructive' : 'text-sm text-muted-foreground'}>
              Add the products you are ordering.
            </p>
          ) : (
            <div className="overflow-x-auto [contain:inline-size]">
              <Table className="min-w-[40rem]">
                <TableHeader>
                  <TableRow>
                    <TableHead>Product</TableHead>
                    <TableHead className="w-28">Quantity</TableHead>
                    <TableHead className="w-36">Purchase price</TableHead>
                    <TableHead className="text-right">Total</TableHead>
                    <TableHead className="w-10">
                      <span className="sr-only">Remove</span>
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {lines.map((line) => {
                    const invalid = showErrors ? lineInvalid(line) : null
                    return (
                      <TableRow key={line.variant_id} className="align-top">
                        <TableCell>
                          <div className="flex flex-col">
                            <span>{line.label}</span>
                            <span className="text-xs text-muted-foreground">{line.sku}</span>
                          </div>
                        </TableCell>
                        <TableCell>
                          <Input
                            aria-label={`Quantity for ${line.label}`}
                            inputMode="decimal"
                            value={line.quantity}
                            aria-invalid={invalid?.quantity || undefined}
                            onChange={(event) => updateLine(line.variant_id, { quantity: event.target.value })}
                          />
                        </TableCell>
                        <TableCell>
                          <Input
                            aria-label={`Purchase price for ${line.label}`}
                            inputMode="decimal"
                            placeholder="0.00"
                            value={line.unitPrice}
                            aria-invalid={invalid?.unitPrice || undefined}
                            onChange={(event) => updateLine(line.variant_id, { unitPrice: event.target.value })}
                          />
                        </TableCell>
                        <TableCell className="pt-3 text-right">
                          <MoneyText
                            amount={(Number(line.quantity) || 0) * (Number(line.unitPrice) || 0)}
                            currency={currency.length === 3 ? currency : undefined}
                          />
                        </TableCell>
                        <TableCell>
                          <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            aria-label={`Remove ${line.label}`}
                            onClick={() => setLines((current) => current.filter((item) => item.variant_id !== line.variant_id))}
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
          {lines.length > 0 ? (
            <p className="ml-auto text-sm font-medium">
              Total <MoneyText amount={total} currency={currency.length === 3 ? currency : undefined} />
            </p>
          ) : null}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Notes</CardTitle>
        </CardHeader>
        <CardContent>
          <Field>
            <FieldLabel htmlFor="po-notes" className="sr-only">
              Notes
            </FieldLabel>
            <Textarea id="po-notes" rows={3} value={notes} onChange={(event) => setNotes(event.target.value)} />
          </Field>
        </CardContent>
        <CardFooter className="justify-end">
          <Button
            disabled={create.isPending}
            onClick={() => {
              setShowErrors(true)
              if (valid) create.mutate()
            }}
          >
            {create.isPending ? <Spinner data-icon="inline-start" /> : null}
            Create purchase order
          </Button>
        </CardFooter>
      </Card>
    </section>
  )
}
