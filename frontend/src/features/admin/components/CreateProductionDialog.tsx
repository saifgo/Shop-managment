import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
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

export interface ProductionPrefill {
  variant_id: string
  label: string
  sku: string
  quantity?: string
}

interface CreateProductionDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Pre-selects a variant (and quantity), e.g. from the demand planning view. */
  prefill?: ProductionPrefill | null
}

interface FormState {
  variant: { variant_id: string; label: string; sku: string } | null
  quantity: string
  priority: string
  plannedDue: string
  notes: string
  plan: boolean
}

function initialState(prefill?: ProductionPrefill | null): FormState {
  return {
    variant: prefill ? { variant_id: prefill.variant_id, label: prefill.label, sku: prefill.sku } : null,
    quantity: prefill?.quantity ?? '',
    priority: 'NORMAL',
    plannedDue: '',
    notes: '',
    plan: true,
  }
}

export function CreateProductionDialog({ open, onOpenChange, prefill }: CreateProductionDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>New production order</DialogTitle>
          <DialogDescription>Choose what to make and how many. Stages are created when you start it.</DialogDescription>
        </DialogHeader>
        {/* The popup unmounts when closed, so the form starts fresh every time it opens. */}
        <CreateProductionForm prefill={prefill} onClose={() => onOpenChange(false)} />
      </DialogContent>
    </Dialog>
  )
}

function CreateProductionForm({ prefill, onClose }: { prefill?: ProductionPrefill | null; onClose: () => void }) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [form, setForm] = useState<FormState>(() => initialState(prefill))
  const [showErrors, setShowErrors] = useState(false)

  const create = useMutation({
    mutationFn: () =>
      productionApi.create({
        variant_id: form.variant!.variant_id,
        planned_quantity: form.quantity.trim(),
        priority: form.priority,
        plan: form.plan,
        planned_due: form.plannedDue || undefined,
        notes: form.notes.trim() || undefined,
      }),
    onSuccess: (production) => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'productions'] })
      void queryClient.invalidateQueries({ queryKey: ['admin', 'production-demand'] })
      toast.success(`Production ${production.reference} created`)
      onClose()
      navigate(`/admin/production/${production.id}`)
    },
  })

  const quantityValid = DECIMAL.test(form.quantity.trim()) && Number(form.quantity) > 0
  const canSubmit = form.variant !== null && quantityValid

  const submit = () => {
    setShowErrors(true)
    if (canSubmit) create.mutate()
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
        <Field data-invalid={showErrors && !form.variant ? true : undefined}>
          <FieldTitle>Product</FieldTitle>
          {form.variant ? (
            <div className="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2">
              <span className="flex min-w-0 flex-col">
                <span className="truncate font-medium">{form.variant.label}</span>
                <span className="text-xs text-muted-foreground">{form.variant.sku}</span>
              </span>
              <VariantPicker
                triggerLabel="Change"
                onSelect={(variant) =>
                  setForm((current) => ({
                    ...current,
                    variant: { variant_id: variant.variant_id, label: variant.label, sku: variant.sku },
                  }))
                }
              />
            </div>
          ) : (
            <VariantPicker
              triggerLabel="Choose product"
              onSelect={(variant) =>
                setForm((current) => ({
                  ...current,
                  variant: { variant_id: variant.variant_id, label: variant.label, sku: variant.sku },
                }))
              }
            />
          )}
          {showErrors && !form.variant ? <FieldError>Choose the product to produce.</FieldError> : null}
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field data-invalid={showErrors && !quantityValid ? true : undefined}>
            <FieldLabel htmlFor="production-quantity">Quantity</FieldLabel>
            <Input
              id="production-quantity"
              inputMode="decimal"
              placeholder="0"
              className="tabular-nums"
              value={form.quantity}
              aria-invalid={showErrors && !quantityValid ? true : undefined}
              onChange={(event) => setForm({ ...form, quantity: event.target.value })}
            />
            {showErrors && !quantityValid ? <FieldError>Enter a quantity above 0.</FieldError> : null}
          </Field>
          <Field>
            <FieldLabel htmlFor="production-priority">Priority</FieldLabel>
            <NativeSelect
              id="production-priority"
              className="w-full"
              value={form.priority}
              onChange={(event) => setForm({ ...form, priority: event.target.value })}
            >
              <NativeSelectOption value="NORMAL">Normal</NativeSelectOption>
              <NativeSelectOption value="HIGH">High</NativeSelectOption>
              <NativeSelectOption value="URGENT">Urgent</NativeSelectOption>
            </NativeSelect>
          </Field>
        </div>

        <Field>
          <FieldLabel htmlFor="production-due">Due date (optional)</FieldLabel>
          <Input
            id="production-due"
            type="date"
            value={form.plannedDue}
            onChange={(event) => setForm({ ...form, plannedDue: event.target.value })}
          />
        </Field>

        <Field>
          <FieldLabel htmlFor="production-notes">Notes (optional)</FieldLabel>
          <Textarea
            id="production-notes"
            rows={2}
            value={form.notes}
            onChange={(event) => setForm({ ...form, notes: event.target.value })}
          />
        </Field>

        <Field orientation="horizontal">
          <Checkbox
            id="production-plan"
            checked={form.plan}
            onCheckedChange={(checked) => setForm({ ...form, plan: checked === true })}
          />
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
