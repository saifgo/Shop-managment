import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AlertCircleIcon } from 'lucide-react'
import { toast } from '@/lib/toast'
import { MoneyText } from '@/components/MoneyText'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field'
import { InputGroup, InputGroupAddon, InputGroupInput, InputGroupText } from '@/components/ui/input-group'
import { Spinner } from '@/components/ui/spinner'
import { productionApi, type ProductionDetail } from '@/lib/api/production'
import { formatWithUnit } from '@/lib/format'

const CURRENCY = 'TND'
const AMOUNT = /^\d+([.,]\d{1,4})?$/

/**
 * Raw materials drawn (or about to be drawn) from stock, and what the order costs. The labour and
 * kiln energy cost is entered by hand and spread over the pieces that survive the firings.
 */
export function ProductionCostCard({ production, canManage }: { production: ProductionDetail; canManage: boolean }) {
  const queryClient = useQueryClient()
  const open = production.status !== 'COMPLETED' && production.status !== 'CANCELLED'
  const [additional, setAdditional] = useState(() => String(Number(production.additional_cost)))
  const additionalValid = AMOUNT.test(additional.trim())
  const isDirty = additionalValid && Number(additional.replace(',', '.')) !== Number(production.additional_cost)
  const short = production.materials.filter((material) => material.is_short)

  const save = useMutation({
    mutationFn: () => productionApi.updateCosts(production.id, additional.trim().replace(',', '.')),
    onSuccess: (updated) => {
      queryClient.setQueryData(['admin', 'production', production.id], updated)
      toast.success('Cost saved.')
    },
  })

  if (production.materials.length === 0 && !open && Number(production.additional_cost) === 0) return null

  return (
    <Card>
      <CardHeader>
        <CardTitle>Materials & cost</CardTitle>
        <CardDescription>
          {production.materials.some((material) => material.consumed)
            ? 'Drawn from stock when the order started.'
            : production.materials.length > 0
              ? 'Drawn from stock when the order starts.'
              : 'No recipe is set for these products, so no materials are drawn.'}
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {short.length > 0 ? (
          <Alert variant="error">
            <AlertCircleIcon />
            <AlertTitle>Not enough raw material to start</AlertTitle>
            <AlertDescription>
              {short.map((material) => `${material.name}: need ${formatWithUnit(material.quantity, material.unit, 4)}, have ${formatWithUnit(material.on_hand, material.unit, 4)}`).join(' · ')}
              . Receive a purchase order or adjust stock first.
            </AlertDescription>
          </Alert>
        ) : null}

        {production.materials.length > 0 ? (
          <ResponsiveTable
            data={production.materials}
            getRowKey={(material) => material.variant_id}
            columns={[
              {
                key: 'material',
                header: 'Material',
                primary: true,
                cell: (material) => (
                  <span className="flex flex-col">
                    <span className="font-medium">{material.name}</span>
                    <span className="text-xs text-muted-foreground">{material.sku}</span>
                  </span>
                ),
              },
              {
                key: 'quantity',
                header: quantityHeader(production),
                mobile: true,
                className: 'tabular-nums',
                cell: (material) => formatWithUnit(material.quantity, material.unit, 4),
              },
              {
                key: 'on_hand',
                header: 'On hand',
                className: 'tabular-nums',
                cell: (material) =>
                  material.consumed ? (
                    '—'
                  ) : (
                    <span className="flex items-center gap-2">
                      {formatWithUnit(material.on_hand, material.unit, 4)}
                      {material.is_short ? <Badge variant="error">Short</Badge> : null}
                    </span>
                  ),
              },
              {
                key: 'cost',
                header: 'Cost',
                mobile: true,
                className: 'tabular-nums',
                cell: (material) => (material.cost === null ? '—' : <MoneyText amount={material.cost} currency={CURRENCY} />),
              },
            ]}
          />
        ) : null}

        <dl className="grid gap-3 text-sm sm:grid-cols-3">
          <div className="flex flex-col gap-0.5">
            <dt className="text-muted-foreground">Materials</dt>
            <dd className="tabular-nums">
              <MoneyText amount={production.material_cost} currency={CURRENCY} />
            </dd>
          </div>
          <div className="flex flex-col gap-0.5">
            <dt className="text-muted-foreground">Labour & kiln</dt>
            <dd className="tabular-nums">
              <MoneyText amount={production.additional_cost} currency={CURRENCY} />
            </dd>
          </div>
          <div className="flex flex-col gap-0.5">
            <dt className="text-muted-foreground">Total</dt>
            <dd className="font-medium tabular-nums">
              <MoneyText amount={production.total_cost} currency={CURRENCY} />
            </dd>
          </div>
        </dl>

        {production.status === 'COMPLETED' ? (
          <ul className="flex flex-col gap-1 text-sm">
            {production.items.map((item) => (
              <li key={item.id} className="flex items-center justify-between gap-3">
                <span className="truncate">
                  {item.sku}: {formatWithUnit(item.accepted_output_quantity, 'pc')} received
                </span>
                <span className="shrink-0 tabular-nums">
                  {item.unit_cost ? (
                    <>
                      <MoneyText amount={item.unit_cost} currency={CURRENCY} /> each
                    </>
                  ) : (
                    <span className="text-muted-foreground">No cost recorded</span>
                  )}
                </span>
              </li>
            ))}
          </ul>
        ) : null}

        {canManage && open ? (
          <form
            className="flex flex-col gap-2"
            onSubmit={(event) => {
              event.preventDefault()
              if (isDirty) save.mutate()
            }}
          >
            <Field data-invalid={additional !== '' && !additionalValid ? true : undefined}>
              <FieldLabel htmlFor="additional-cost">Labour & kiln cost</FieldLabel>
              <div className="flex flex-wrap items-center gap-2">
                <InputGroup className="sm:max-w-48">
                  <InputGroupInput
                    id="additional-cost"
                    inputMode="decimal"
                    value={additional}
                    aria-invalid={additional !== '' && !additionalValid}
                    onChange={(event) => setAdditional(event.target.value)}
                  />
                  <InputGroupAddon align="inline-end">
                    <InputGroupText>{CURRENCY}</InputGroupText>
                  </InputGroupAddon>
                </InputGroup>
                <Button type="submit" variant="secondary" disabled={save.isPending || !isDirty}>
                  {save.isPending ? <Spinner data-icon="inline-start" /> : null}
                  Save
                </Button>
              </div>
              <FieldDescription>
                Gas or electricity for the firings plus labour. It is shared across the products and added to the cost of each
                piece that comes out, so breakage makes the survivors dearer.
              </FieldDescription>
            </Field>
            {save.error ? <FieldError>{save.error.message}</FieldError> : null}
          </form>
        ) : null}
      </CardContent>
    </Card>
  )
}

function quantityHeader(production: ProductionDetail): string {
  return production.materials.some((material) => material.consumed) ? 'Used' : 'Needed'
}
