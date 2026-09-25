import { useRef, useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ImagePlusIcon, StampIcon, Trash2Icon } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel, FieldSeparator } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { InputGroup, InputGroupAddon, InputGroupInput, InputGroupText } from '@/components/ui/input-group'
import { Spinner } from '@/components/ui/spinner'
import { COMPANY_PROFILE_QUERY_KEY, settingsApi, type CompanyProfile, type CompanyProfileInput } from '@/lib/api/settings'

const AMOUNT = /^\d+(\.\d{1,4})?$/
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const STAMP_MAX_BYTES = 2 * 1024 * 1024

/** Form state: every field as text, empty when unset. */
type FormValues = Record<keyof CompanyProfileInput, string>

function toFormValues(profile: CompanyProfile): FormValues {
  return {
    name: profile.name ?? '',
    phone: profile.phone ?? '',
    email: profile.email ?? '',
    tax_id: profile.tax_id ?? '',
    address: profile.address ?? '',
    bank_label: profile.bank_label,
    bank_account: profile.bank_account ?? '',
    // "1.0000" -> "1" for the input.
    stamp_duty: String(Number(profile.stamp_duty)),
  }
}

/** Seller details printed on invoices and other documents, and the invoice stamp duty. */
export function CompanyProfileCard({ profile }: { profile: CompanyProfile }) {
  const queryClient = useQueryClient()
  const initial = toFormValues(profile)
  const [values, setValues] = useState(initial)
  const set = (field: keyof FormValues) => (event: { target: { value: string } }) =>
    setValues((current) => ({ ...current, [field]: event.target.value }))

  const emailValid = values.email.trim() === '' || EMAIL.test(values.email.trim())
  const stampDutyValid = AMOUNT.test(values.stamp_duty.trim() || '0')
  const dirty = JSON.stringify(values) !== JSON.stringify(initial)

  const onSaved = (saved: CompanyProfile) => queryClient.setQueryData(COMPANY_PROFILE_QUERY_KEY, saved)

  const save = useMutation({
    mutationFn: () => settingsApi.updateCompany({ ...values, stamp_duty: values.stamp_duty.trim() || '0' }),
    onSuccess: (saved) => {
      onSaved(saved)
      toast.success('Company details saved. New PDFs will use them.')
    },
    onError: (err) => toast.error(err.message),
  })

  const submit = (event: FormEvent) => {
    event.preventDefault()
    if (emailValid && stampDutyValid) save.mutate()
  }

  return (
    <form onSubmit={submit}>
      <Card>
        <CardHeader>
          <CardTitle>Company details on documents</CardTitle>
          <CardDescription>
            Printed at the top of invoices, quotes and delivery notes. PDFs already generated keep the details they
            were made with; regenerate one from its page to update it.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <FieldGroup>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field className="sm:col-span-2">
                <FieldLabel htmlFor="company-name">Business name</FieldLabel>
                <Input id="company-name" value={values.name} onChange={set('name')} autoComplete="organization" />
              </Field>
              <Field>
                <FieldLabel htmlFor="company-phone">Phone</FieldLabel>
                <Input id="company-phone" type="tel" value={values.phone} onChange={set('phone')} />
              </Field>
              <Field data-invalid={!emailValid || undefined}>
                <FieldLabel htmlFor="company-email">Email</FieldLabel>
                <Input
                  id="company-email"
                  type="email"
                  value={values.email}
                  aria-invalid={!emailValid || undefined}
                  onChange={set('email')}
                />
                {emailValid ? null : <FieldError>Enter a valid email address.</FieldError>}
              </Field>
              <Field>
                <FieldLabel htmlFor="company-tax-id">Tax ID (M.F)</FieldLabel>
                <Input id="company-tax-id" value={values.tax_id} onChange={set('tax_id')} />
              </Field>
              <Field>
                <FieldLabel htmlFor="company-address">Address</FieldLabel>
                <Input id="company-address" value={values.address} onChange={set('address')} />
                <FieldDescription>Optional.</FieldDescription>
              </Field>
            </div>

            <FieldSeparator />

            <div className="grid gap-4 sm:grid-cols-2">
              <Field>
                <FieldLabel htmlFor="company-bank-label">Payment account heading</FieldLabel>
                <Input id="company-bank-label" value={values.bank_label} onChange={set('bank_label')} />
                <FieldDescription>For example “Relevé d'identité postale” or “RIB”.</FieldDescription>
              </Field>
              <Field>
                <FieldLabel htmlFor="company-bank-account">Account number</FieldLabel>
                <Input id="company-bank-account" value={values.bank_account} onChange={set('bank_account')} />
              </Field>
              <Field data-invalid={!stampDutyValid || undefined}>
                <FieldLabel htmlFor="company-stamp-duty">Stamp duty (timbre fiscal)</FieldLabel>
                <InputGroup>
                  <InputGroupInput
                    id="company-stamp-duty"
                    inputMode="decimal"
                    value={values.stamp_duty}
                    aria-invalid={!stampDutyValid || undefined}
                    onChange={(event) => setValues((current) => ({ ...current, stamp_duty: event.target.value.trim() }))}
                  />
                  <InputGroupAddon align="inline-end">
                    <InputGroupText>{profile.stamp_duty_currency}</InputGroupText>
                  </InputGroupAddon>
                </InputGroup>
                {stampDutyValid ? (
                  <FieldDescription>
                    Added to each new invoice in {profile.stamp_duty_currency}. Use 0 for none.
                  </FieldDescription>
                ) : (
                  <FieldError>Enter an amount with at most 4 decimals.</FieldError>
                )}
              </Field>
            </div>

            <FieldSeparator />

            <StampField profile={profile} onSaved={onSaved} />
          </FieldGroup>
        </CardContent>
        <CardFooter className="justify-end">
          <Button type="submit" disabled={!dirty || !emailValid || !stampDutyValid || save.isPending}>
            {save.isPending ? <Spinner data-icon="inline-start" /> : null}
            Save
          </Button>
        </CardFooter>
      </Card>
    </form>
  )
}

function StampField({ profile, onSaved }: { profile: CompanyProfile; onSaved: (profile: CompanyProfile) => void }) {
  const fileInput = useRef<HTMLInputElement>(null)

  const upload = useMutation({
    mutationFn: (file: File) => settingsApi.uploadStamp(file),
    onSuccess: (saved) => {
      onSaved(saved)
      toast.success('Stamp saved.')
    },
    onError: (err) => toast.error(err.message),
  })
  const remove = useMutation({
    mutationFn: () => settingsApi.deleteStamp(),
    onSuccess: onSaved,
    onError: (err) => toast.error(err.message),
  })

  const choose = (file: File | undefined) => {
    if (!file) return
    if (file.size > STAMP_MAX_BYTES) {
      toast.error(`"${file.name}" is larger than 2 MB.`)
      return
    }
    upload.mutate(file)
  }

  const busy = upload.isPending || remove.isPending

  return (
    <Field>
      <FieldLabel>Stamp or signature</FieldLabel>
      <div className="flex flex-wrap items-center gap-4">
        <div className="flex h-24 w-40 items-center justify-center rounded-lg border border-dashed bg-muted/40 p-2">
          {profile.stamp_image ? (
            <img src={profile.stamp_image} alt="Stamp printed on documents" className="max-h-full max-w-full object-contain" />
          ) : (
            <StampIcon className="size-6 text-muted-foreground" aria-hidden />
          )}
        </div>
        <div className="flex flex-wrap gap-2">
          <input
            ref={fileInput}
            type="file"
            accept="image/png,image/jpeg"
            className="sr-only"
            tabIndex={-1}
            onChange={(event) => {
              choose(event.target.files?.[0])
              event.target.value = ''
            }}
          />
          <Button type="button" variant="outline" disabled={busy} onClick={() => fileInput.current?.click()}>
            {upload.isPending ? <Spinner data-icon="inline-start" /> : <ImagePlusIcon data-icon="inline-start" />}
            {profile.stamp_image ? 'Replace' : 'Upload'}
          </Button>
          {profile.stamp_image ? (
            <Button type="button" variant="ghost" disabled={busy} onClick={() => remove.mutate()}>
              {remove.isPending ? <Spinner data-icon="inline-start" /> : <Trash2Icon data-icon="inline-start" />}
              Remove
            </Button>
          ) : null}
        </div>
      </div>
      <FieldDescription>
        PNG or JPEG up to 2 MB, printed above the payment details. A PNG with a transparent background looks best.
      </FieldDescription>
    </Field>
  )
}
