import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { AlertCircleIcon, PlusIcon, Trash2Icon } from 'lucide-react'
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
import { CustomerSelect } from '@/features/admin/components/CustomerSelect'
import { VariantPicker, type PickedVariant } from '@/features/admin/components/VariantPicker'
import {
  documentsApi,
  documentTypeLabel,
  isDocumentType,
  MANUAL_DOCUMENT_TYPES,
  previewLineTotals,
  type DocumentType,
  type ManualDocumentLineInput,
} from '@/lib/api/documents'
import { formatTaxRate, settingsApi, TAX_SETTINGS_QUERY_KEY } from '@/lib/api/settings'

interface DraftLine {
  key: string
  variantId?: string
  /** Catalog price shown as a hint; the customer's own price is applied on save when unit price is blank. */
  catalogPrice?: string
  description: string
  sku: string
  quantity: string
  unitPrice: string
  taxRate: string
  discount: string
}

const DECIMAL = /^\d+(\.\d{1,4})?$/

/** Used until the company rate loads; matches the backend fallback. */
const FALLBACK_TAX_RATE = '20'

function blankLine(taxRate: string): DraftLine {
  return {
    key: crypto.randomUUID(),
    description: '',
    sku: '',
    quantity: '1',
    unitPrice: '',
    taxRate,
    discount: '',
  }
}

function lineErrors(line: DraftLine) {
  const effectivePrice = line.unitPrice || line.catalogPrice || ''
  const quantityOk = DECIMAL.test(line.quantity) && Number(line.quantity) > 0
  const priceOk = line.unitPrice === '' ? Boolean(line.variantId) : DECIMAL.test(line.unitPrice)
  const discountOk =
    line.discount === '' ||
    (DECIMAL.test(line.discount) && Number(line.discount) <= Number(line.quantity) * Number(effectivePrice || 0))

  return {
    description: !line.variantId && line.description.trim() === '',
    quantity: !quantityOk,
    unitPrice: !priceOk,
    taxRate: !DECIMAL.test(line.taxRate) || Number(line.taxRate) > 100,
    discount: !discountOk,
  }
}

export function AdminDocumentCreatePage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [searchParams] = useSearchParams()
  const requestedType = searchParams.get('type')
  const [idempotencyKey] = useState(() => crypto.randomUUID())
  const [type, setType] = useState<DocumentType>(
    isDocumentType(requestedType) && MANUAL_DOCUMENT_TYPES.includes(requestedType) ? requestedType : 'INVOICE',
  )
  const [customerId, setCustomerId] = useState('')
  const [currency, setCurrency] = useState('TND')
  const [dueDate, setDueDate] = useState('')
  const [notes, setNotes] = useState('')
  const [lines, setLines] = useState<DraftLine[]>([])
  const [showErrors, setShowErrors] = useState(false)
  const { data: taxSettings } = useQuery({ queryKey: TAX_SETTINGS_QUERY_KEY, queryFn: settingsApi.getTax })
  const defaultTaxRate = taxSettings ? formatTaxRate(taxSettings.default_tax_rate) : FALLBACK_TAX_RATE

  const save = useMutation({
    mutationFn: (issue: boolean) =>
      documentsApi.create(
        {
          document_type: type,
          customer_id: customerId,
          currency,
          notes: notes.trim() || undefined,
          due_date: type === 'INVOICE' && issue && dueDate ? dueDate : undefined,
          issue,
          lines: lines.map(
            (line): ManualDocumentLineInput => ({
              variant_id: line.variantId,
              description: line.description.trim() || undefined,
              sku: line.sku.trim() || undefined,
              quantity: line.quantity,
              unit_price: line.unitPrice || undefined,
              tax_rate: line.taxRate,
              discount_amount: line.discount || undefined,
            }),
          ),
        },
        idempotencyKey,
      ),
    onSuccess: (document) => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'documents'] })
      void queryClient.invalidateQueries({ queryKey: ['admin', 'invoices'] })
      navigate(`/admin/documents/${document.id}`)
    },
  })

  const updateLine = (key: string, patch: Partial<DraftLine>) =>
    setLines((current) => current.map((line) => (line.key === key ? { ...line, ...patch } : line)))

  const addCatalogLine = (variant: PickedVariant) =>
    setLines((current) => [
      ...current,
      {
        ...blankLine(defaultTaxRate),
        variantId: variant.variant_id,
        catalogPrice: variant.price.amount,
        description: variant.label,
        sku: variant.sku,
      },
    ])

  const totals = lines.reduce(
    (sum, line) => {
      const preview = previewLineTotals(line.quantity, line.unitPrice || line.catalogPrice || '0', line.taxRate, line.discount)
      return {
        subtotal: sum.subtotal + preview.subtotal,
        discount: sum.discount + preview.discount,
        tax: sum.tax + preview.tax,
        total: sum.total + preview.total,
      }
    },
    { subtotal: 0, discount: 0, tax: 0, total: 0 },
  )
  const usesCatalogPricing = lines.some((line) => line.variantId && line.unitPrice === '')

  const formValid =
    customerId !== '' &&
    /^[A-Za-z]{3}$/.test(currency) &&
    lines.length > 0 &&
    lines.every((line) => !Object.values(lineErrors(line)).some(Boolean))

  const submit = (issue: boolean) => {
    setShowErrors(true)
    if (formValid) save.mutate(issue)
  }

  return (
    <section className="flex flex-col gap-6">
      <Link to="/admin/documents" className="text-sm text-muted-foreground">
        Documents
      </Link>
      <PageHeader title="New document" description="Enter lines by hand or pick products from the catalog." />

      {save.isError ? (
        <Alert variant="destructive">
          <AlertCircleIcon />
          <AlertTitle>Unable to save the document</AlertTitle>
          <AlertDescription>{save.error.message}</AlertDescription>
        </Alert>
      ) : null}

      <Card>
        <CardHeader>
          <CardTitle>Details</CardTitle>
        </CardHeader>
        <CardContent>
          <FieldGroup className="grid gap-4 md:grid-cols-2">
            <Field>
              <FieldLabel htmlFor="document-type">Document type</FieldLabel>
              <NativeSelect
                id="document-type"
                className="w-full"
                value={type}
                onChange={(event) => setType(event.target.value as DocumentType)}
              >
                {MANUAL_DOCUMENT_TYPES.map((value) => (
                  <NativeSelectOption key={value} value={value}>
                    {documentTypeLabel(value)}
                  </NativeSelectOption>
                ))}
              </NativeSelect>
            </Field>
            <Field data-invalid={showErrors && customerId === '' ? true : undefined}>
              <FieldLabel htmlFor="document-customer">Customer</FieldLabel>
              <CustomerSelect id="document-customer" value={customerId} onChange={setCustomerId} />
            </Field>
            <Field>
              <FieldLabel htmlFor="document-currency">Currency</FieldLabel>
              <Input
                id="document-currency"
                maxLength={3}
                value={currency}
                aria-invalid={!/^[A-Za-z]{3}$/.test(currency) || undefined}
                onChange={(event) => setCurrency(event.target.value.toUpperCase())}
              />
            </Field>
            {type === 'INVOICE' ? (
              <Field>
                <FieldLabel htmlFor="document-due-date">Due date</FieldLabel>
                <Input id="document-due-date" type="date" value={dueDate} onChange={(event) => setDueDate(event.target.value)} />
                <FieldDescription>Applied when the invoice is issued. Defaults to 30 days.</FieldDescription>
              </Field>
            ) : null}
          </FieldGroup>
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2">
          <CardTitle>Lines</CardTitle>
          <div className="flex flex-wrap gap-2">
            <VariantPicker onSelect={addCatalogLine} />
            <Button type="button" variant="outline" onClick={() => setLines((current) => [...current, blankLine(defaultTaxRate)])}>
              <PlusIcon data-icon="inline-start" />
              Custom line
            </Button>
          </div>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          {lines.length === 0 ? (
            <p className={showErrors ? 'text-sm text-destructive' : 'text-sm text-muted-foreground'}>
              Add at least one line: a catalog product or a custom line (service, fee, one-off item…).
            </p>
          ) : (
            <div className="overflow-x-auto [contain:inline-size]">
              <Table className="min-w-[56rem]">
                <TableHeader>
                  <TableRow>
                    <TableHead>Description</TableHead>
                    <TableHead className="w-32">SKU / ref.</TableHead>
                    <TableHead className="w-24">Qty</TableHead>
                    <TableHead className="w-32">Unit price</TableHead>
                    <TableHead className="w-20">Tax %</TableHead>
                    <TableHead className="w-28">Discount</TableHead>
                    <TableHead className="text-right">Total</TableHead>
                    <TableHead className="w-10">
                      <span className="sr-only">Remove</span>
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {lines.map((line, index) => {
                    const errors = showErrors ? lineErrors(line) : null
                    const preview = previewLineTotals(
                      line.quantity,
                      line.unitPrice || line.catalogPrice || '0',
                      line.taxRate,
                      line.discount,
                    )
                    const label = `line ${index + 1}`
                    return (
                      <TableRow key={line.key} className="align-top">
                        <TableCell>
                          <Input
                            aria-label={`Description, ${label}`}
                            placeholder={line.variantId ? undefined : 'e.g. Installation service'}
                            value={line.description}
                            aria-invalid={errors?.description || undefined}
                            onChange={(event) => updateLine(line.key, { description: event.target.value })}
                          />
                        </TableCell>
                        <TableCell>
                          <Input
                            aria-label={`SKU, ${label}`}
                            value={line.sku}
                            readOnly={Boolean(line.variantId)}
                            onChange={(event) => updateLine(line.key, { sku: event.target.value })}
                          />
                        </TableCell>
                        <TableCell>
                          <Input
                            aria-label={`Quantity, ${label}`}
                            inputMode="decimal"
                            value={line.quantity}
                            aria-invalid={errors?.quantity || undefined}
                            onChange={(event) => updateLine(line.key, { quantity: event.target.value })}
                          />
                        </TableCell>
                        <TableCell>
                          <Input
                            aria-label={`Unit price, ${label}`}
                            inputMode="decimal"
                            placeholder={line.catalogPrice ? `${Number(line.catalogPrice).toFixed(2)} (auto)` : '0.00'}
                            value={line.unitPrice}
                            aria-invalid={errors?.unitPrice || undefined}
                            onChange={(event) => updateLine(line.key, { unitPrice: event.target.value })}
                          />
                        </TableCell>
                        <TableCell>
                          <Input
                            aria-label={`Tax rate percent, ${label}`}
                            inputMode="decimal"
                            value={line.taxRate}
                            aria-invalid={errors?.taxRate || undefined}
                            onChange={(event) => updateLine(line.key, { taxRate: event.target.value })}
                          />
                        </TableCell>
                        <TableCell>
                          <Input
                            aria-label={`Discount amount, ${label}`}
                            inputMode="decimal"
                            placeholder="0.00"
                            value={line.discount}
                            aria-invalid={errors?.discount || undefined}
                            onChange={(event) => updateLine(line.key, { discount: event.target.value })}
                          />
                        </TableCell>
                        <TableCell className="pt-3 text-right">
                          <MoneyText amount={preview.total} currency={currency.length === 3 ? currency : undefined} />
                        </TableCell>
                        <TableCell>
                          <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            aria-label={`Remove ${label}`}
                            onClick={() => setLines((current) => current.filter((item) => item.key !== line.key))}
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
            <dl className="ml-auto flex w-full max-w-xs flex-col gap-2 text-sm">
              <TotalRow label="Subtotal" amount={totals.subtotal} currency={currency} />
              <TotalRow label="Discount" amount={-totals.discount} currency={currency} />
              <TotalRow label="Tax" amount={totals.tax} currency={currency} />
              <TotalRow label="Total" amount={totals.total} currency={currency} strong />
              {usesCatalogPricing ? (
                <p className="text-xs text-muted-foreground">
                  Estimate. Lines marked “auto” use the customer’s price, which may differ from the catalog price.
                </p>
              ) : null}
            </dl>
          ) : null}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Notes</CardTitle>
        </CardHeader>
        <CardContent>
          <Field>
            <FieldLabel htmlFor="document-notes" className="sr-only">
              Notes
            </FieldLabel>
            <Textarea id="document-notes" rows={3} value={notes} onChange={(event) => setNotes(event.target.value)} />
          </Field>
        </CardContent>
        <CardFooter className="flex flex-wrap justify-end gap-2">
          <Button variant="outline" disabled={save.isPending} onClick={() => submit(false)}>
            {save.isPending && save.variables === false ? <Spinner data-icon="inline-start" /> : null}
            Save as draft
          </Button>
          <Button disabled={save.isPending} onClick={() => submit(true)}>
            {save.isPending && save.variables === true ? <Spinner data-icon="inline-start" /> : null}
            Save and issue
          </Button>
        </CardFooter>
      </Card>
    </section>
  )
}

function TotalRow({ label, amount, currency, strong }: { label: string; amount: number; currency: string; strong?: boolean }) {
  return (
    <div className={strong ? 'flex justify-between font-medium' : 'flex justify-between text-muted-foreground'}>
      <dt>{label}</dt>
      <dd>
        <MoneyText amount={amount} currency={currency.length === 3 ? currency : undefined} />
      </dd>
    </div>
  )
}
