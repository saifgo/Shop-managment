import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AlertCircleIcon, PlusIcon } from 'lucide-react'
import { toast } from 'sonner'
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
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { Textarea } from '@/components/ui/textarea'
import { purchasingApi, type CreateSupplierInput } from '@/lib/api/purchasing'

const emptyForm = { code: '', name: '', contact_email: '', contact_phone: '', address: '', tax_id: '' }

export function NewSupplierDialog() {
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(emptyForm)

  const create = useMutation({
    mutationFn: () => {
      const payload: CreateSupplierInput = { code: form.code.trim(), name: form.name.trim() }
      for (const key of ['contact_email', 'contact_phone', 'address', 'tax_id'] as const) {
        if (form[key].trim()) payload[key] = form[key].trim()
      }
      return purchasingApi.createSupplier(payload)
    },
    onSuccess: (supplier) => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'suppliers'] })
      toast.success(`Supplier ${supplier.name} added.`)
      setForm(emptyForm)
      setOpen(false)
    },
  })

  const set = (key: keyof typeof emptyForm) => (event: { target: { value: string } }) =>
    setForm((current) => ({ ...current, [key]: event.target.value }))

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        if (!next) create.reset()
      }}
    >
      <DialogTrigger render={<Button />}>
        <PlusIcon data-icon="inline-start" />
        New supplier
      </DialogTrigger>
      <DialogContent className="sm:max-w-md">
        <form
          className="flex flex-col gap-4"
          onSubmit={(event) => {
            event.preventDefault()
            if (form.code.trim() && form.name.trim()) create.mutate()
          }}
        >
          <DialogHeader>
            <DialogTitle>New supplier</DialogTitle>
            <DialogDescription>Code and name are required; the code must be unique.</DialogDescription>
          </DialogHeader>

          {create.isError ? (
            <Alert variant="destructive">
              <AlertCircleIcon />
              <AlertDescription>{create.error.message}</AlertDescription>
            </Alert>
          ) : null}

          <FieldGroup>
            <div className="grid gap-4 sm:grid-cols-[8rem_1fr]">
              <Field>
                <FieldLabel htmlFor="supplier-code">Code</FieldLabel>
                <Input id="supplier-code" required maxLength={32} value={form.code} onChange={set('code')} />
              </Field>
              <Field>
                <FieldLabel htmlFor="supplier-name">Name</FieldLabel>
                <Input id="supplier-name" required maxLength={200} value={form.name} onChange={set('name')} />
              </Field>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field>
                <FieldLabel htmlFor="supplier-email">Email</FieldLabel>
                <Input id="supplier-email" type="email" value={form.contact_email} onChange={set('contact_email')} />
              </Field>
              <Field>
                <FieldLabel htmlFor="supplier-phone">Phone</FieldLabel>
                <Input id="supplier-phone" type="tel" value={form.contact_phone} onChange={set('contact_phone')} />
              </Field>
            </div>
            <Field>
              <FieldLabel htmlFor="supplier-tax-id">Tax ID</FieldLabel>
              <Input id="supplier-tax-id" maxLength={64} value={form.tax_id} onChange={set('tax_id')} />
            </Field>
            <Field>
              <FieldLabel htmlFor="supplier-address">Address</FieldLabel>
              <Textarea id="supplier-address" rows={2} value={form.address} onChange={set('address')} />
            </Field>
          </FieldGroup>

          <DialogFooter>
            <Button type="submit" disabled={create.isPending}>
              {create.isPending ? <Spinner data-icon="inline-start" /> : null}
              Add supplier
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
