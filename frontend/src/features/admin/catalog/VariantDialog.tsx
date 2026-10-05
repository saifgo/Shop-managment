import { useState, type ReactElement } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AlertCircleIcon, PlusIcon, XIcon } from 'lucide-react'
import { toast } from '@/lib/toast'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { InputGroup, InputGroupAddon, InputGroupInput, InputGroupText } from '@/components/ui/input-group'
import { Spinner } from '@/components/ui/spinner'
import { Switch } from '@/components/ui/switch'
import { catalogApi, type ProductKind, type ProductVariant, type VariantInput } from '@/lib/api/catalog'

interface AttributeRow {
  key: string
  value: string
}

/** Common pottery attributes offered as one-click rows. */
const SUGGESTED_ATTRIBUTES = ['size', 'color', 'glaze', 'diameter']

function initialState(variant?: ProductVariant) {
  const rows = variant ? Object.entries(variant.attributes).map(([key, value]) => ({ key, value })) : []
  return {
    sku: variant?.sku ?? '',
    name: variant?.name ?? '',
    price: variant ? String(Number(variant.base_price.amount)) : '',
    currency: variant?.base_price.currency ?? 'TND',
    isActive: variant?.is_active ?? true,
    reorderLevel: variant?.reorder_level ? String(Number(variant.reorder_level)) : '',
    attributes: rows.length > 0 ? rows : [{ key: 'size', value: '' }],
  }
}

interface VariantDialogProps {
  productId: string
  productName: string
  /** Omit to create a new variant. */
  variant?: ProductVariant
  trigger: ReactElement
  /** Raw materials are not sold, so they have no price, and are counted in the product's unit. */
  productKind?: ProductKind
  unit?: string
}

export function VariantDialog({ productId, productName, variant, trigger, productKind = 'finished_good', unit = 'pc' }: VariantDialogProps) {
  const isRawMaterial = productKind === 'raw_material'
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(() => initialState(variant))
  const isEdit = Boolean(variant)

  const save = useMutation({
    mutationFn: () => {
      const payload: VariantInput = {
        sku: form.sku.trim(),
        name: form.name.trim(),
        base_price_amount: isRawMaterial ? '0' : form.price.trim().replace(',', '.'),
        base_price_currency: form.currency,
        attributes: Object.fromEntries(
          form.attributes
            .map((row) => [row.key.trim(), row.value.trim()] as const)
            .filter(([key, value]) => key !== '' && value !== ''),
        ),
        is_active: form.isActive,
        reorder_level: form.reorderLevel.trim() ? form.reorderLevel.trim().replace(',', '.') : null,
      }
      return variant
        ? catalogApi.updateVariant(productId, variant.id, payload)
        : catalogApi.createVariant(productId, payload)
    },
    onSuccess: (saved) => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'product', productId] })
      void queryClient.invalidateQueries({ queryKey: ['admin', 'products'] })
      toast.success(isEdit ? `Variant ${saved.sku} updated.` : `Variant ${saved.sku} added.`)
      setOpen(false)
    },
  })

  const setAttribute = (index: number, patch: Partial<AttributeRow>) =>
    setForm((current) => ({
      ...current,
      attributes: current.attributes.map((row, i) => (i === index ? { ...row, ...patch } : row)),
    }))

  const usedKeys = new Set(form.attributes.map((row) => row.key.trim().toLowerCase()))
  const priceValid = isRawMaterial || /^\d+([.,]\d{1,4})?$/.test(form.price.trim())
  const reorderValid = form.reorderLevel.trim() === '' || /^\d+([.,]\d{1,4})?$/.test(form.reorderLevel.trim())

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        if (next) {
          setForm(initialState(variant))
          save.reset()
        }
      }}
    >
      <DialogTrigger render={trigger} />
      <DialogContent className="sm:max-w-lg">
        <form
          className="flex flex-col gap-4"
          onSubmit={(event) => {
            event.preventDefault()
            if (form.sku.trim() && form.name.trim() && priceValid && reorderValid) save.mutate()
          }}
        >
          <DialogHeader>
            <DialogTitle>{isEdit ? `Edit ${variant?.sku}` : 'Add variant'}</DialogTitle>
            <DialogDescription>
              {isRawMaterial
                ? `A variant is one purchasable version of ${productName} — for example a grade or pack size.`
                : `A variant is one sellable version of ${productName} — for example a size or glaze.`}
            </DialogDescription>
          </DialogHeader>

          {save.isError ? (
            <Alert variant="error">
              <AlertCircleIcon />
              <AlertDescription>{save.error.message}</AlertDescription>
            </Alert>
          ) : null}

          <div className="flex flex-col gap-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field>
                <FieldLabel htmlFor="variant-name">Name</FieldLabel>
                <Input
                  id="variant-name"
                  required
                  maxLength={200}
                  placeholder="Large, blue glaze"
                  value={form.name}
                  onChange={(e) => setForm({ ...form, name: e.target.value })}
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="variant-sku">SKU</FieldLabel>
                <Input
                  id="variant-sku"
                  required
                  maxLength={64}
                  placeholder="TAG-L-BLU"
                  value={form.sku}
                  onChange={(e) => setForm({ ...form, sku: e.target.value.toUpperCase() })}
                />
              </Field>
            </div>

            {isRawMaterial ? null : (
            <Field data-invalid={form.price !== '' && !priceValid ? true : undefined}>
              <FieldLabel htmlFor="variant-price">Base price</FieldLabel>
              <InputGroup className="sm:max-w-56">
                <InputGroupInput
                  id="variant-price"
                  required
                  inputMode="decimal"
                  placeholder="0.00"
                  value={form.price}
                  aria-invalid={form.price !== '' && !priceValid}
                  onChange={(e) => setForm({ ...form, price: e.target.value })}
                />
                <InputGroupAddon align="inline-end">
                  <InputGroupText>{form.currency}</InputGroupText>
                </InputGroupAddon>
              </InputGroup>
              <FieldDescription>
                Price before tax. Customer-specific prices can be set further down the product page.
              </FieldDescription>
            </Field>
            )}

            <Field data-invalid={!reorderValid ? true : undefined}>
              <FieldLabel htmlFor="variant-reorder">Reorder level (optional)</FieldLabel>
              <InputGroup className="sm:max-w-56">
                <InputGroupInput
                  id="variant-reorder"
                  inputMode="decimal"
                  placeholder={isRawMaterial ? '50' : '5'}
                  value={form.reorderLevel}
                  aria-invalid={!reorderValid}
                  onChange={(e) => setForm({ ...form, reorderLevel: e.target.value })}
                />
                <InputGroupAddon align="inline-end">
                  <InputGroupText>{unit}</InputGroupText>
                </InputGroupAddon>
              </InputGroup>
              <FieldDescription>
                {isRawMaterial
                  ? 'Flagged as low stock at or below this level, so you re-order before the bin runs out.'
                  : 'Flagged as low stock at or below this level. Left empty, products are flagged under 5.'}
              </FieldDescription>
            </Field>

            <Field>
              <span className="text-sm font-medium text-foreground">Attributes</span>
              <div className="flex flex-col gap-2">
                {form.attributes.map((row, index) => (
                  <div key={index} className="flex items-center gap-2">
                    <Input
                      aria-label={`Attribute ${index + 1} name`}
                      placeholder="size"
                      className="w-32"
                      value={row.key}
                      onChange={(e) => setAttribute(index, { key: e.target.value })}
                    />
                    <Input
                      aria-label={`Attribute ${index + 1} value`}
                      placeholder="large"
                      value={row.value}
                      onChange={(e) => setAttribute(index, { value: e.target.value })}
                    />
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon-sm"
                      aria-label="Remove attribute"
                      onClick={() =>
                        setForm({ ...form, attributes: form.attributes.filter((_, i) => i !== index) })
                      }
                    >
                      <XIcon />
                    </Button>
                  </div>
                ))}
                <div className="flex flex-wrap gap-1.5">
                  <Button
                    type="button"
                    variant="outline"
                    size="xs"
                    onClick={() => setForm({ ...form, attributes: [...form.attributes, { key: '', value: '' }] })}
                  >
                    <PlusIcon data-icon="inline-start" />
                    Attribute
                  </Button>
                  {SUGGESTED_ATTRIBUTES.filter((key) => !usedKeys.has(key)).map((key) => (
                    <Button
                      key={key}
                      type="button"
                      variant="ghost"
                      size="xs"
                      onClick={() => setForm({ ...form, attributes: [...form.attributes, { key, value: '' }] })}
                    >
                      + {key}
                    </Button>
                  ))}
                </div>
              </div>
            </Field>

            <Field className="flex-row items-center">
              <Switch
                id="variant-active"
                checked={form.isActive}
                onCheckedChange={(checked) => setForm({ ...form, isActive: checked })}
              />
              <FieldLabel htmlFor="variant-active" className="flex flex-col items-start gap-0.5">
                <span>Available for sale</span>
                <span className="text-xs font-normal text-muted-foreground">
                  Turn off to hide this variant from the store and new orders.
                </span>
              </FieldLabel>
            </Field>
          </div>

          <DialogFooter>
            <Button type="submit" disabled={save.isPending || !priceValid || !reorderValid || !form.sku.trim() || !form.name.trim()}>
              {save.isPending ? <Spinner data-icon="inline-start" /> : null}
              {isEdit ? 'Save variant' : 'Add variant'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
