import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Trash2Icon } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
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
import { Textarea } from '@/components/ui/textarea'
import { VariantPicker } from '@/features/admin/components/VariantPicker'
import { productionApi } from '@/lib/api/production'

const DECIMAL = /^\d+(\.\d{1,4})?$/

export interface ProductionLinePrefill {
  variant_id: string
  label: string
  sku: string
  quantity?: string
}

interface CreateProductionDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Pre-selects products (and quantities), e.g. from the demand planning view. */
  prefill?: ProductionLinePrefill[] | null
}

interface Line {
  variant_id: string
  label: string
  sku: string
  quantity: string
}

function isValidQuantity(value: string) {
  return DECIMAL.test(value.trim()) && Number(value) > 0
}

export function CreateProductionDialog({ open, onOpenChange, prefill }: CreateProductionDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>New production order</DialogTitle>
          <DialogDescription>
            Add every product this run will make. They move through the stages together.
          </DialogDescription>
        </DialogHeader>
        {/* The popup unmounts when closed, so the form starts fresh every time it opens. */}
        <CreateProductionForm prefill={prefill} onClose={() => onOpenChange(false)} />
      </DialogContent>
    </Dialog>
  )
}

function CreateProductionForm({
  prefill,
  onClose,
}: {
  prefill?: ProductionLinePrefill[] | null
  onClose: () => void
}) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [lines, setLines] = useState<Line[]>(() =>
    (prefill ?? []).map((line) => ({ ...line, quantity: line.quantity ?? '' })),
  )
  const [priority, setPriority] = useState('NORMAL')
  const [plannedDue, setPlannedDue] = useState('')
  const [notes, setNotes] = useState('')
  const [plan, setPlan] = useState(true)
  const [showErrors, setShowErrors] = useState(false)

  const addLine = (variant: { variant_id: string; label: string; sku: string }) => {
    setLines((current) =>
      current.some((line) => line.variant_id === variant.variant_id)
        ? current
        : [...current, { variant_id: variant.variant_id, label: variant.label, sku: variant.sku, quantity: '' }],
    )
  }

  const updateQuantity = (variantId: string, quantity: string) =>
    setLines((current) => current.map((line) => (line.variant_id === variantId ? { ...line, quantity } : line)))

  const removeLine = (variantId: string) =>
    setLines((current) => current.filter((line) => line.variant_id !== variantId))

  const create = useMutation({
    mutationFn: () =>
      productionApi.create({
        items: lines.map((line) => ({ variant_id: line.variant_id, planned_quantity: line.quantity.trim() })),
        priority,
        plan,
        planned_due: plannedDue || undefined,
        notes: notes.trim() || undefined,
      }),
    onSuccess: (production) => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'productions'] })
      void queryClient.invalidateQueries({ queryKey: ['admin', 'production-demand'] })
      toast.success(`Production ${production.reference} created`)
      onClose()
      navigate(`/admin/production/${production.id}`)
    },
  })

  const canSubmit = lines.length > 0 && lines.every((line) => isValidQuantity(line.quantity))

  return (
    <form
      className="flex flex-col gap-4"
      onSubmit={(event) => {
        event.preventDefault()
        setShowErrors(true)
        if (canSubmit) create.mutate()
      }}
    >
      <FieldGroup>
        <Field data-invalid={showErrors && lines.length === 0 ? true : undefined}>
          <div className="flex items-center justify-between gap-3">
            <FieldTitle>Products</FieldTitle>
            <VariantPicker triggerLabel="Add product" onSelect={addLine} />
          </div>

          {lines.length === 0 ? (
            <p className={showErrors ? 'text-sm text-destructive' : 'text-sm text-muted-foreground'}>
              Add the products to produce.
            </p>
          ) : (
            <ul className="flex max-h-72 flex-col divide-y divide-border overflow-y-auto rounded-lg border border-border">
              {lines.map((line) => {
                const invalid = showErrors && !isValidQuantity(line.quantity)
                const inputId = `production-qty-${line.variant_id}`

                return (
                  <li key={line.variant_id} className="flex flex-col gap-1 px-3 py-2">
                    <div className="flex items-center gap-3">
                      <label htmlFor={inputId} className="flex min-w-0 flex-1 flex-col">
                        <span className="truncate font-medium">{line.label}</span>
                        <span className="text-xs text-muted-foreground">{line.sku}</span>
                      </label>
                      <Input
                        id={inputId}
                        inputMode="decimal"
                        placeholder="Qty"
                        className="w-24 tabular-nums"
                        value={line.quantity}
                        aria-invalid={invalid ? true : undefined}
                        onChange={(event) => updateQuantity(line.variant_id, event.target.value)}
                      />
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        aria-label={`Remove ${line.label}`}
                        onClick={() => removeLine(line.variant_id)}
                      >
                        <Trash2Icon />
                      </Button>
                    </div>
                    {invalid ? <FieldError>Enter a quantity above 0.</FieldError> : null}
                  </li>
                )
              })}
            </ul>
          )}
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field>
            <FieldLabel htmlFor="production-priority">Priority</FieldLabel>
            <NativeSelect
              id="production-priority"
              className="w-full"
              value={priority}
              onChange={(event) => setPriority(event.target.value)}
            >
              <NativeSelectOption value="NORMAL">Normal</NativeSelectOption>
              <NativeSelectOption value="HIGH">High</NativeSelectOption>
              <NativeSelectOption value="URGENT">Urgent</NativeSelectOption>
            </NativeSelect>
          </Field>
          <Field>
            <FieldLabel htmlFor="production-due">Due date (optional)</FieldLabel>
            <Input
              id="production-due"
              type="date"
              value={plannedDue}
              onChange={(event) => setPlannedDue(event.target.value)}
            />
          </Field>
        </div>

        <Field>
          <FieldLabel htmlFor="production-notes">Notes (optional)</FieldLabel>
          <Textarea id="production-notes" rows={2} value={notes} onChange={(event) => setNotes(event.target.value)} />
        </Field>

        <Field orientation="horizontal">
          <Checkbox id="production-plan" checked={plan} onCheckedChange={(checked) => setPlan(checked === true)} />
          <div className="flex flex-col gap-1">
            <FieldLabel htmlFor="production-plan">Mark as planned</FieldLabel>
            <FieldDescription>Planned orders count toward “In production” in demand planning.</FieldDescription>
          </div>
        </Field>
      </FieldGroup>

      {create.error ? <FieldError>{create.error.message}</FieldError> : null}

      <DialogFooter>
        <Button type="button" variant="outline" disabled={create.isPending} onClick={onClose}>
          Cancel
        </Button>
        <Button type="submit" disabled={create.isPending}>
          {create.isPending ? <Spinner data-icon="inline-start" /> : null}
          {create.isPending ? 'Creating…' : 'Create production'}
        </Button>
      </DialogFooter>
    </form>
  )
}
