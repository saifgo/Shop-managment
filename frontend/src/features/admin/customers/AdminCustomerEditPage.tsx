import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Field, FieldGroup, FieldLabel, FieldTitle } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { Textarea } from '@/components/ui/textarea'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { customersApi, type CustomerDetail } from '@/lib/api/customers'
import { AlertCircleIcon } from 'lucide-react'

const emptyForm = {
  type: 'person',
  display_name: '',
  legal_name: '',
  tax_id: '',
  vat_number: '',
  notes: '',
  is_active: true,
}

export function AdminCustomerEditPage() {
  const { id } = useParams()
  // Route `customers/new` has no `:id` param, so `id` is undefined there.
  const isNew = !id || id === 'new'

  const { data: customer, isLoading, error } = useQuery({
    queryKey: ['admin', 'customer', id],
    queryFn: () => customersApi.get(id!),
    enabled: !isNew && Boolean(id),
  })

  if (isNew) {
    return <CustomerEditor />
  }

  return (
    <QueryState isLoading={isLoading} error={error || (!isLoading && !customer) ? 'Customer not found.' : null}>
      {customer ? <CustomerEditor key={customer.id} customer={customer} customerId={id} /> : null}
    </QueryState>
  )
}

function CustomerEditor({
  customer,
  customerId,
}: {
  customer?: CustomerDetail
  customerId?: string
}) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const isNew = !customerId

  const [form, setForm] = useState(() =>
    customer
      ? {
          type: customer.type,
          display_name: customer.display_name,
          legal_name: customer.legal_name ?? '',
          tax_id: customer.tax_id ?? '',
          vat_number: customer.vat_number ?? '',
          notes: customer.notes ?? '',
          is_active: customer.is_active,
        }
      : emptyForm,
  )

  const saveCustomer = useMutation({
    mutationFn: async () => {
      const payload = {
        type: form.type,
        display_name: form.display_name,
        legal_name: form.legal_name || null,
        tax_id: form.tax_id || null,
        vat_number: form.vat_number || null,
        notes: form.notes || null,
        is_active: form.is_active,
      }

      if (isNew) {
        return customersApi.create({
          ...payload,
          contacts: form.display_name
            ? [{ name: form.display_name, email: null, is_primary: true }]
            : [],
        })
      }

      return customersApi.update(customerId!, payload)
    },
    onSuccess: (saved) => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'customers'] })
      if (isNew) {
        navigate(`/admin/customers/${saved.id}`)
      }
    },
  })

  return (
    <section className="flex flex-col gap-6">
      <Link to="/admin/customers" className="text-sm text-muted-foreground">
        Customers
      </Link>
      <PageHeader title={isNew ? 'New customer' : customer?.display_name ?? 'Customer'} />

      {saveCustomer.isError ? (
        <Alert variant="destructive">
          <AlertCircleIcon />
          <AlertTitle>Unable to save</AlertTitle>
          <AlertDescription>The customer could not be saved. Check the fields and try again.</AlertDescription>
        </Alert>
      ) : null}

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Details</CardTitle>
          </CardHeader>
          <CardContent>
            <FieldGroup>
              <Field>
                <FieldTitle id="customer-type-label">Type</FieldTitle>
                <ToggleGroup
                  variant="outline"
                  spacing={0}
                  aria-labelledby="customer-type-label"
                  value={[form.type]}
                  onValueChange={(next) => {
                    if (next[0]) setForm({ ...form, type: next[0] })
                  }}
                >
                  <ToggleGroupItem value="person">Person</ToggleGroupItem>
                  <ToggleGroupItem value="company">Company</ToggleGroupItem>
                  <ToggleGroupItem value="association">Association</ToggleGroupItem>
                </ToggleGroup>
              </Field>
              <Field>
                <FieldLabel htmlFor="display-name">Display name</FieldLabel>
                <Input
                  id="display-name"
                  value={form.display_name}
                  onChange={(e) => setForm({ ...form, display_name: e.target.value })}
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="legal-name">Legal name</FieldLabel>
                <Input
                  id="legal-name"
                  value={form.legal_name}
                  onChange={(e) => setForm({ ...form, legal_name: e.target.value })}
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="tax-id">Tax ID</FieldLabel>
                <Input
                  id="tax-id"
                  value={form.tax_id}
                  onChange={(e) => setForm({ ...form, tax_id: e.target.value })}
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="vat-number">VAT number</FieldLabel>
                <Input
                  id="vat-number"
                  value={form.vat_number}
                  onChange={(e) => setForm({ ...form, vat_number: e.target.value })}
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="customer-notes">Notes</FieldLabel>
                <Textarea
                  id="customer-notes"
                  rows={3}
                  value={form.notes}
                  onChange={(e) => setForm({ ...form, notes: e.target.value })}
                />
              </Field>
              {!isNew ? (
                <Field orientation="horizontal">
                  <Checkbox
                    id="customer-active"
                    checked={form.is_active}
                    onCheckedChange={(checked) => setForm({ ...form, is_active: checked })}
                  />
                  <FieldLabel htmlFor="customer-active">Active</FieldLabel>
                </Field>
              ) : null}
            </FieldGroup>
          </CardContent>
          <CardFooter>
            <Button onClick={() => saveCustomer.mutate()} disabled={saveCustomer.isPending}>
              {saveCustomer.isPending ? <Spinner data-icon="inline-start" /> : null}
              {saveCustomer.isPending ? 'Saving…' : 'Save customer'}
            </Button>
          </CardFooter>
        </Card>

        {!isNew && customer ? (
          <Card>
            <CardHeader>
              <CardTitle>360 view</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-5">
              <p className="text-sm text-muted-foreground">Orders and invoices will appear here in later phases.</p>
              <dl className="grid grid-cols-2 gap-4">
                <div className="flex flex-col gap-1">
                  <dt className="text-sm text-muted-foreground">Orders</dt>
                  <dd className="tabular-nums">{customer.orders_count ?? 0}</dd>
                </div>
                <div className="flex flex-col gap-1">
                  <dt className="text-sm text-muted-foreground">Balance</dt>
                  <dd>
                    {customer.balance ? (
                      <MoneyText amount={customer.balance.amount} currency={customer.balance.currency} />
                    ) : (
                      '—'
                    )}
                  </dd>
                </div>
              </dl>

              <div className="flex flex-col gap-2">
                <h3 className="font-heading text-sm font-medium">Contacts</h3>
                {customer.contacts.length === 0 ? (
                  <p className="text-sm text-muted-foreground">No contacts.</p>
                ) : (
                  <ul className="flex flex-col gap-1">
                    {customer.contacts.map((contact) => (
                      <li key={contact.id} className="text-sm">
                        {contact.name} {contact.email ? `— ${contact.email}` : ''}
                      </li>
                    ))}
                  </ul>
                )}
              </div>

              <div className="flex flex-col gap-2">
                <h3 className="font-heading text-sm font-medium">Addresses</h3>
                {customer.addresses.length === 0 ? (
                  <p className="text-sm text-muted-foreground">No addresses.</p>
                ) : (
                  <ul className="flex flex-col gap-1">
                    {customer.addresses.map((address) => (
                      <li key={address.id} className="text-sm">
                        {address.line1}, {address.city} {address.postal_code}
                      </li>
                    ))}
                  </ul>
                )}
              </div>

              <div className="flex flex-col gap-2">
                <h3 className="font-heading text-sm font-medium">Price overrides</h3>
                {(customer.price_overrides ?? []).length === 0 ? (
                  <p className="text-sm text-muted-foreground">No overrides.</p>
                ) : (
                  <ul className="flex flex-col gap-1">
                    {(customer.price_overrides ?? []).map((override) => (
                      <li key={override.id} className="text-sm">
                        {override.variant_sku}:{' '}
                        <MoneyText amount={override.price.amount} currency={override.price.currency} />
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </CardContent>
          </Card>
        ) : null}
      </div>
    </section>
  )
}
