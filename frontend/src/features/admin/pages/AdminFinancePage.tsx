import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { invoicesApi, paymentsApi } from '@/lib/api/finance'
import { customersApi } from '@/lib/api/customers'

export function AdminInvoicesPage() {
  const queryClient = useQueryClient()
  const { data, isLoading, error } = useQuery({
    queryKey: ['admin', 'invoices'],
    queryFn: () => invoicesApi.list(),
  })

  const issue = useMutation({
    mutationFn: (id: string) => invoicesApi.issue(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'invoices'] }),
  })

  return (
    <section className="flex flex-col gap-6">
      <PageHeader title="Invoices" />
      <QueryState
        isLoading={isLoading}
        error={error ? 'Failed to load invoices.' : null}
        isEmpty={data?.items.length === 0}
        emptyTitle="No invoices"
        emptyDescription="Invoices appear after deliveries are billed."
      >
        <ResponsiveTable
          wide
          data={data?.items ?? []}
          getRowKey={(invoice) => invoice.id}
          columns={[
            {
              key: 'number',
              header: 'Number',
              primary: true,
              cell: (invoice) => invoice.document_number ?? 'Draft',
            },
            {
              key: 'customer',
              header: 'Customer',
              cell: (invoice) => invoice.customer_display_name,
            },
            {
              key: 'status',
              header: 'Status',
              cell: (invoice) => <StatusBadge status={invoice.status} />,
            },
            {
              key: 'total',
              header: 'Total',
              cell: (invoice) => (
                <MoneyText amount={invoice.grand_total.amount} currency={invoice.grand_total.currency} />
              ),
            },
            {
              key: 'due',
              header: 'Due',
              cell: (invoice) => (
                <MoneyText amount={invoice.amount_due.amount} currency={invoice.amount_due.currency} />
              ),
            },
          ]}
          rowAction={(invoice) =>
            !invoice.is_posted ? (
              <Button size="sm" onClick={() => issue.mutate(invoice.id)} disabled={issue.isPending}>
                {issue.isPending ? <Spinner data-icon="inline-start" /> : null}
                Issue
              </Button>
            ) : (
              <Button
                variant="link"
                size="sm"
                nativeButton={false}
                render={<a href={invoicesApi.downloadUrl(invoice.id)} target="_blank" rel="noreferrer" />}
              >
                PDF
              </Button>
            )
          }
        />
      </QueryState>
    </section>
  )
}

export function AdminPaymentsPage() {
  const queryClient = useQueryClient()
  const { data: customers } = useQuery({
    queryKey: ['admin', 'customers'],
    queryFn: () => customersApi.list(),
  })

  const record = useMutation({
    mutationFn: (payload: { customerId: string; amount: string; invoiceId: string }) =>
      paymentsApi
        .record({
          customer_id: payload.customerId,
          amount: payload.amount,
          currency: 'TND',
          method: 'BANK_TRANSFER',
          payment_date: new Date().toISOString().slice(0, 10),
        })
        .then((payment) =>
          paymentsApi.allocate(payment.id, [{ invoice_id: payload.invoiceId, amount: payload.amount }]),
        ),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'invoices'] }),
  })

  const firstCustomer = customers?.items[0]

  return (
    <section className="flex flex-col gap-6">
      <PageHeader
        title="Record payment"
        description="Use invoice detail from an order to obtain invoice IDs, or record via API."
      />
      {firstCustomer ? (
        <p className="text-sm text-muted-foreground">
          Default customer for quick actions: {firstCustomer.display_name}
        </p>
      ) : null}
      <Card className="max-w-lg">
        <CardHeader>
          <CardTitle>Payment</CardTitle>
        </CardHeader>
        <CardContent>
          <form
            id="record-payment-form"
            className="flex flex-col gap-5"
            onSubmit={(event) => {
              event.preventDefault()
              const form = new FormData(event.currentTarget)
              const customerId = String(form.get('customer_id') ?? '')
              const invoiceId = String(form.get('invoice_id') ?? '')
              const amount = String(form.get('amount') ?? '')
              if (!customerId || !invoiceId || !amount) return
              record.mutate({ customerId, invoiceId, amount })
            }}
          >
            <FieldGroup>
              <Field>
                <FieldLabel htmlFor="customer_id">Customer ID</FieldLabel>
                <Input id="customer_id" name="customer_id" defaultValue={firstCustomer?.id ?? ''} required />
              </Field>
              <Field>
                <FieldLabel htmlFor="invoice_id">Invoice ID</FieldLabel>
                <Input id="invoice_id" name="invoice_id" required />
              </Field>
              <Field>
                <FieldLabel htmlFor="amount">Amount</FieldLabel>
                <Input id="amount" name="amount" placeholder="100.0000" required />
                <FieldDescription>Recorded as a bank transfer in TND.</FieldDescription>
              </Field>
            </FieldGroup>
          </form>
        </CardContent>
        <CardFooter>
          <Button type="submit" form="record-payment-form" disabled={record.isPending}>
            {record.isPending ? <Spinner data-icon="inline-start" /> : null}
            Record & allocate
          </Button>
        </CardFooter>
      </Card>
    </section>
  )
}
