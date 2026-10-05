import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertCircleIcon, XIcon } from 'lucide-react'
import { toast } from '@/lib/toast'
import { MoneyText } from '@/components/MoneyText'
import { QueryState } from '@/components/QueryState'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select'
import { Spinner } from '@/components/ui/spinner'
import { VariantPicker } from '@/features/admin/components/VariantPicker'
import { catalogApi, type ProductDetail, type Recipe } from '@/lib/api/catalog'
import { formatQuantity } from '@/lib/format'

const CURRENCY = 'TND'
const QUANTITY = /^\d+([.,]\d{1,4})?$/

interface RecipeRow {
  component_variant_id: string
  label: string
  sku: string
  unit: string
  quantity: string
  /** Current average cost of one unit; unknown for rows added since the last save. */
  unit_cost: string | null
}

function rowsFromRecipe(recipe: Recipe): RecipeRow[] {
  return recipe.components.map((line) => ({
    component_variant_id: line.component_variant_id,
    label: `${line.product_name} — ${line.variant_name}`,
    sku: line.sku,
    unit: line.unit,
    quantity: String(Number(line.quantity_per_unit)),
    unit_cost: line.unit_cost,
  }))
}

/**
 * What one piece of a finished variant consumes (clay, glaze...). Starting a production order
 * draws these materials from stock and costs the finished pieces from them.
 */
export function RecipeCard({ product, canManage }: { product: ProductDetail; canManage: boolean }) {
  const variants = product.variants.filter((variant) => variant.is_active)
  const [variantId, setVariantId] = useState(variants[0]?.id ?? '')

  return (
    <Card>
      <CardHeader>
        <CardTitle>Recipe</CardTitle>
        <CardDescription>
          Raw materials one piece uses. They are drawn from stock when a production order starts, and make up the cost of the
          finished pieces.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {variants.length > 1 ? (
          <Field>
            <FieldLabel htmlFor="recipe-variant">Variant</FieldLabel>
            <NativeSelect id="recipe-variant" className="w-full" value={variantId} onChange={(e) => setVariantId(e.target.value)}>
              {variants.map((variant) => (
                <NativeSelectOption key={variant.id} value={variant.id}>
                  {variant.name} ({variant.sku})
                </NativeSelectOption>
              ))}
            </NativeSelect>
          </Field>
        ) : null}
        {variantId ? <RecipeEditor key={variantId} variantId={variantId} canManage={canManage} /> : null}
      </CardContent>
    </Card>
  )
}

function RecipeEditor({ variantId, canManage }: { variantId: string; canManage: boolean }) {
  const recipe = useQuery({
    queryKey: ['admin', 'recipe', variantId],
    queryFn: () => catalogApi.getRecipe(variantId),
  })

  return (
    <QueryState isLoading={recipe.isLoading} error={recipe.error ? 'Unable to load the recipe.' : null}>
      {recipe.data ? <RecipeForm recipe={recipe.data} canManage={canManage} /> : null}
    </QueryState>
  )
}

function RecipeForm({ recipe, canManage }: { recipe: Recipe; canManage: boolean }) {
  const queryClient = useQueryClient()
  const [rows, setRows] = useState<RecipeRow[]>(() => rowsFromRecipe(recipe))
  const saved = JSON.stringify(rowsFromRecipe(recipe))
  const isDirty = JSON.stringify(rows) !== saved

  const quantitiesValid = rows.every((row) => QUANTITY.test(row.quantity.trim()) && Number(row.quantity.replace(',', '.')) > 0)

  const save = useMutation({
    mutationFn: () =>
      catalogApi.replaceRecipe(
        recipe.variant_id,
        rows.map((row) => ({
          component_variant_id: row.component_variant_id,
          quantity_per_unit: row.quantity.trim().replace(',', '.'),
        })),
      ),
    onSuccess: (result) => {
      queryClient.setQueryData(['admin', 'recipe', recipe.variant_id], result)
      setRows(rowsFromRecipe(result))
      toast.success(`Recipe for ${result.sku} saved.`)
    },
  })

  const setQuantity = (index: number, quantity: string) =>
    setRows((current) => current.map((row, i) => (i === index ? { ...row, quantity } : row)))

  const estimate = rows.every((row) => row.unit_cost !== null && QUANTITY.test(row.quantity.trim()))
    ? rows.reduce((sum, row) => sum + Number(row.quantity.replace(',', '.')) * Number(row.unit_cost), 0)
    : null

  return (
    <>
      {save.isError ? (
        <Alert variant="error">
          <AlertCircleIcon />
          <AlertDescription>{save.error.message}</AlertDescription>
        </Alert>
      ) : null}

      {rows.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          No recipe yet. Without one, production does not draw any materials and finished pieces have no material cost.
        </p>
      ) : (
        <ul className="flex flex-col divide-y divide-border rounded-lg border border-border">
          {rows.map((row, index) => (
            <li key={row.component_variant_id} className="flex flex-wrap items-center gap-3 px-3 py-2">
              <span className="flex min-w-0 flex-1 flex-col">
                <span className="truncate font-medium">{row.label}</span>
                <span className="text-xs text-muted-foreground">
                  {row.sku}
                  {row.unit_cost !== null && Number(row.unit_cost) > 0 ? (
                    <>
                      {' · '}
                      <MoneyText amount={row.unit_cost} currency={CURRENCY} /> / {row.unit}
                    </>
                  ) : null}
                </span>
              </span>
              <div className="flex items-center gap-2">
                <Input
                  aria-label={`${row.label} quantity per piece`}
                  inputMode="decimal"
                  className="w-24 tabular-nums"
                  disabled={!canManage}
                  value={row.quantity}
                  aria-invalid={!QUANTITY.test(row.quantity.trim())}
                  onChange={(e) => setQuantity(index, e.target.value)}
                />
                <span className="w-8 text-sm text-muted-foreground">{row.unit}</span>
                {canManage ? (
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label={`Remove ${row.label}`}
                    onClick={() => setRows((current) => current.filter((_, i) => i !== index))}
                  >
                    <XIcon />
                  </Button>
                ) : null}
              </div>
            </li>
          ))}
        </ul>
      )}

      {canManage ? (
        <VariantPicker
          triggerLabel="Add raw material"
          kind="raw_material"
          onSelect={(picked) => {
            if (rows.some((row) => row.component_variant_id === picked.variant_id)) {
              toast.info(`${picked.sku} is already in the recipe.`)
              return
            }
            setRows((current) => [
              ...current,
              {
                component_variant_id: picked.variant_id,
                label: picked.label,
                sku: picked.sku,
                unit: picked.unit,
                quantity: '',
                unit_cost: null,
              },
            ])
          }}
        />
      ) : null}

      {rows.length > 0 ? (
        <p className="text-sm text-muted-foreground">
          Estimated material cost per piece:{' '}
          {estimate !== null ? (
            <MoneyText amount={estimate} currency={CURRENCY} className="font-medium text-foreground" />
          ) : (
            <span>
              {isDirty ? 'save to update' : '—'}
              {recipe.estimated_material_cost !== '0.0000' && !isDirty ? ` (${formatQuantity(recipe.estimated_material_cost, 4)})` : ''}
            </span>
          )}
        </p>
      ) : null}

      {canManage ? (
        <div>
          <Button onClick={() => save.mutate()} disabled={save.isPending || !isDirty || !quantitiesValid}>
            {save.isPending ? <Spinner data-icon="inline-start" /> : null}
            {isDirty ? 'Save recipe' : 'Saved'}
          </Button>
        </div>
      ) : null}
    </>
  )
}
