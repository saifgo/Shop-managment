import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field'
import { InputGroup, InputGroupAddon, InputGroupInput, InputGroupText } from '@/components/ui/input-group'
import { Spinner } from '@/components/ui/spinner'
import { formatTaxRate, settingsApi, TAX_SETTINGS_QUERY_KEY, type TaxSettings } from '@/lib/api/settings'

const PERCENT = /^\d+(\.\d{1,4})?$/

function TaxRateForm({ settings }: { settings: TaxSettings }) {
  const queryClient = useQueryClient()
  const current = formatTaxRate(settings.default_tax_rate)
  const [rate, setRate] = useState(current)
  const valid = PERCENT.test(rate) && Number(rate) <= 100

  const save = useMutation({
    mutationFn: (value: string) => settingsApi.updateTax(value),
    onSuccess: (updated) => {
      // The page keys this form on the saved rate, so it remounts with the new value.
      queryClient.setQueryData(TAX_SETTINGS_QUERY_KEY, updated)
      toast.success(`Default tax rate set to ${formatTaxRate(updated.default_tax_rate)}%`)
    },
    onError: (err) => toast.error(err.message),
  })

  const submit = (event: FormEvent) => {
    event.preventDefault()
    if (valid) save.mutate(rate)
  }

  return (
    <form onSubmit={submit}>
      <Card>
        <CardHeader>
          <CardTitle>Tax</CardTitle>
          <CardDescription>
            Applied to new orders and to document lines entered without a rate. Existing orders, and documents built
            from them, keep the rate they were created with.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Field data-invalid={!valid || undefined} className="max-w-xs">
            <FieldLabel htmlFor="default-tax-rate">Default tax rate</FieldLabel>
            <InputGroup>
              <InputGroupInput
                id="default-tax-rate"
                inputMode="decimal"
                value={rate}
                aria-invalid={!valid || undefined}
                onChange={(event) => setRate(event.target.value.trim())}
              />
              <InputGroupAddon align="inline-end">
                <InputGroupText>%</InputGroupText>
              </InputGroupAddon>
            </InputGroup>
            {valid ? (
              <FieldDescription>
                {settings.updated_at
                  ? `Last changed ${new Date(settings.updated_at).toLocaleString()}.`
                  : 'Using the system default.'}
              </FieldDescription>
            ) : (
              <FieldError>Enter a percentage between 0 and 100 with at most 4 decimals.</FieldError>
            )}
          </Field>
        </CardContent>
        <CardFooter className="justify-end">
          <Button type="submit" disabled={!valid || rate === current || save.isPending}>
            {save.isPending ? <Spinner data-icon="inline-start" /> : null}
            Save
          </Button>
        </CardFooter>
      </Card>
    </form>
  )
}

export function AdminSettingsPage() {
  const { data, isLoading, error } = useQuery({ queryKey: TAX_SETTINGS_QUERY_KEY, queryFn: settingsApi.getTax })

  return (
    <section className="flex flex-col gap-6">
      <PageHeader title="Settings" description="Company-wide defaults." />
      <QueryState isLoading={isLoading} error={error}>
        {data ? <TaxRateForm key={data.default_tax_rate} settings={data} /> : null}
      </QueryState>
    </section>
  )
}
