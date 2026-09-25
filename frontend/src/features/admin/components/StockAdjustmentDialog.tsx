import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel, FieldTitle } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select'
import { Spinner } from '@/components/ui/spinner'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { VariantPicker } from '@/features/admin/components/VariantPicker'
import { inventoryApi } from '@/lib/api/inventory'

const DECIMAL = /^\d+(\.\d{1,4})?$/
const SCALE = 10_000

type AdjustmentMode = 'add' | 'remove' | 'set'

const REASONS = [
  'Physical count',
  'Damaged / broken',
  'Found stock',
  'Returned to stock',
  'Data entry correction',
  'Other',
] as const

export interface AdjustmentTarget {
  variant_id: string
  label: string
  sku: string
  location_id?: string
}

interface StockAdjustmentDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Pre-selects the stock row being adjusted; otherwise the user picks a product. */
  target?: AdjustmentTarget | null
}

/** Exact 4-decimal arithmetic on integers, so "set to count" never produces float noise. */
function toUnits(value: string): number {
  const [whole, fraction = ''] = value.trim().split('.')
  return Number(whole) * SCALE + Number(fraction.padEnd(4, '0').slice(0, 4))
}

function fromUnits(units: number): string {
  const sign = units < 0 ? '-' : ''
  const abs = Math.abs(units)
  return `${sign}${Math.floor(abs / SCALE)}.${String(abs % SCALE).padStart(4, '0')}`
}

export function StockAdjustmentDialog({ open, onOpenChange, target }: StockAdjustmentDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Adjust stock</DialogTitle>
          <DialogDescription>
            Records an adjustment in the movement ledger. Use it for counts, breakage or corrections.
          </DialogDescription>
        </DialogHeader>
        {/* The popup unmounts when closed, so the form starts fresh every time it opens. */}
        <StockAdjustmentForm target={target} onClose={() => onOpenChange(false)} />
      </DialogContent>
    </Dialog>
  )
}

function StockAdjustmentForm({ target, onClose }: { target?: AdjustmentTarget | null; onClose: () => void }) {
  const queryClient = useQueryClient()
  const [variant, setVariant] = useState<AdjustmentTarget | null>(target ?? null)
  const [mode, setMode] = useState<AdjustmentMode>('add')
  const [quantity, setQuantity] = useState('')
  const [reason, setReason] = useState<string>(REASONS[0])
  const [note, setNote] = useState('')
  const [showErrors, setShowErrors] = useState(false)

  const availability = useQuery({
    queryKey: ['admin', 'inventory', 'availability', variant?.variant_id, variant?.location_id],
    queryFn: () => inventoryApi.availability(variant!.variant_id, variant!.location_id),
    enabled: variant !== null,
  })

  const onHand = availability.data?.physical_on_hand ?? null
  const quantityValid = DECIMAL.test(quantity.trim())
  const delta = (() => {
    if (!quantityValid) return null
    const units = toUnits(quantity)
    if (mode === 'add') return units
    if (mode === 'remove') return -units
    return onHand === null ? null : units - toUnits(onHand)
  })()
  const resultingOnHand = onHand !== null && delta !== null ? toUnits(onHand) + delta : null
  const noteRequired = reason === 'Other'
  const reasonText = note.trim() ? `${reason}: ${note.trim()}` : reason

  const errors = {
    variant: variant === null ? 'Choose the product to adjust.' : null,
    quantity: !quantityValid
      ? 'Enter a quantity (up to 4 decimals).'
      : delta === 0
        ? mode === 'set'
          ? 'Counted quantity matches what is on hand — nothing to adjust.'
          : 'Enter a quantity above 0.'
        : resultingOnHand !== null && resultingOnHand < 0
          ? `Only ${onHand} on hand — you can't remove more than that.`
          : null,
    note: noteRequired && !note.trim() ? 'Describe the reason.' : null,
  }
  const hasErrors = Object.values(errors).some(Boolean) || delta === null

  const adjust = useMutation({
    mutationFn: () =>
      inventoryApi.adjust({
        variant_id: variant!.variant_id,
        location_id: variant!.location_id,
        quantity_delta: fromUnits(delta!),
        reason: reasonText.slice(0, 255),
      }),
    onSuccess: (result) => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'inventory'] })
      void queryClient.invalidateQueries({ queryKey: ['admin', 'production-demand'] })
      toast.success(`Stock adjusted by ${result.movement.quantity_delta} for ${variant!.sku}`)
      onClose()
    },
  })

  const submit = () => {
    setShowErrors(true)
    if (!hasErrors) adjust.mutate()
  }

  return (
    <form
      className="flex flex-col gap-4"
      onSubmit={(event) => {
        event.preventDefault()
        submit()
      }}
    >
      <FieldGroup>
        <Field data-invalid={showErrors && errors.variant ? true : undefined}>
          <FieldTitle>Product</FieldTitle>
          {variant ? (
            <div className="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2">
              <span className="flex min-w-0 flex-col">
                <span className="truncate font-medium">{variant.label}</span>
                <span className="text-xs text-muted-foreground">
                  {variant.sku} · On hand:{' '}
                  <span className="tabular-nums">
                    {availability.isLoading ? '…' : (onHand ?? '—')}
                  </span>
                </span>
              </span>
              {target ? null : (
                <VariantPicker
                  triggerLabel="Change"
                  onSelect={(picked) =>
                    setVariant({ variant_id: picked.variant_id, label: picked.label, sku: picked.sku })
                  }
                />
              )}
            </div>
          ) : (
            <VariantPicker
              triggerLabel="Choose product"
              onSelect={(picked) =>
                setVariant({ variant_id: picked.variant_id, label: picked.label, sku: picked.sku })
              }
            />
          )}
          {showErrors && errors.variant ? <FieldError>{errors.variant}</FieldError> : null}
        </Field>

        <Field>
          <FieldTitle id="adjust-mode-label">Adjustment</FieldTitle>
          <ToggleGroup
            variant="outline"
            spacing={0}
            aria-labelledby="adjust-mode-label"
            value={[mode]}
            onValueChange={(next) => {
              if (next[0]) setMode(next[0] as AdjustmentMode)
            }}
          >
            <ToggleGroupItem value="add">Add</ToggleGroupItem>
            <ToggleGroupItem value="remove">Remove</ToggleGroupItem>
            <ToggleGroupItem value="set">Set to count</ToggleGroupItem>
          </ToggleGroup>
        </Field>

        <Field data-invalid={showErrors && errors.quantity ? true : undefined}>
          <FieldLabel htmlFor="adjust-quantity">
            {mode === 'set' ? 'Counted quantity' : mode === 'add' ? 'Quantity to add' : 'Quantity to remove'}
          </FieldLabel>
          <Input
            id="adjust-quantity"
            inputMode="decimal"
            placeholder="0"
            className="tabular-nums"
            value={quantity}
            aria-invalid={showErrors && errors.quantity ? true : undefined}
            onChange={(event) => setQuantity(event.target.value)}
          />
          {showErrors && errors.quantity ? (
            <FieldError>{errors.quantity}</FieldError>
          ) : resultingOnHand !== null && delta !== null && delta !== 0 ? (
            <FieldDescription className="tabular-nums">
              {onHand} → {fromUnits(resultingOnHand)} ({delta > 0 ? '+' : ''}
              {fromUnits(delta)})
            </FieldDescription>
          ) : null}
        </Field>

        <Field>
          <FieldLabel htmlFor="adjust-reason">Reason</FieldLabel>
          <NativeSelect
            id="adjust-reason"
            className="w-full"
            value={reason}
            onChange={(event) => setReason(event.target.value)}
          >
            {REASONS.map((option) => (
              <NativeSelectOption key={option} value={option}>
                {option}
              </NativeSelectOption>
            ))}
          </NativeSelect>
        </Field>

        <Field data-invalid={showErrors && errors.note ? true : undefined}>
          <FieldLabel htmlFor="adjust-note">Note{noteRequired ? '' : ' (optional)'}</FieldLabel>
          <Input
            id="adjust-note"
            maxLength={200}
            value={note}
            aria-invalid={showErrors && errors.note ? true : undefined}
            onChange={(event) => setNote(event.target.value)}
          />
          {showErrors && errors.note ? <FieldError>{errors.note}</FieldError> : null}
        </Field>
      </FieldGroup>

      {adjust.error ? <FieldError>{adjust.error.message}</FieldError> : null}

      <DialogFooter>
        <Button type="button" variant="outline" disabled={adjust.isPending} onClick={onClose}>
          Cancel
        </Button>
        <Button type="submit" disabled={adjust.isPending}>
          {adjust.isPending ? <Spinner data-icon="inline-start" /> : null}
          {adjust.isPending ? 'Saving…' : 'Save adjustment'}
        </Button>
      </DialogFooter>
    </form>
  )
}
