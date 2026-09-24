import { useQuery } from '@tanstack/react-query'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { deliveriesApi, invoicesApi } from '@/lib/api/finance'

export function PortalInvoicesPage() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['portal', 'invoices'],
    queryFn: () => invoicesApi.list(),
  })

  return (
    <section className="flex flex-col gap-6">
      <PageHeader title="Invoices" />
      <QueryState
        isLoading={isLoading}
        error={error ? 'Failed to load invoices.' : null}
        isEmpty={data?.items.length === 0}
        emptyTitle="No invoices"
        emptyDescription="Invoices will appear here once orders are billed."
      >
        <ResponsiveTable
          data={data?.items ?? []}
          getRowKey={(invoice) => invoice.id}
          columns={[
            {
              key: 'number',
              header: 'Number',
              primary: true,
              cell: (invoice) => invoice.document_number,
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
            {
              key: 'document',
              header: 'Document',
              cell: (invoice) =>
                invoice.is_posted ? (
                  <a href={invoicesApi.downloadUrl(invoice.id)} target="_blank" rel="noreferrer">
                    Download PDF
                  </a>
                ) : (
                  '—'
                ),
            },
          ]}
        />
      </QueryState>
    </section>
  )
}

export function PortalOrderDetailFinance({ orderId }: { orderId: string }) {
  const { data: deliveries } = useQuery({
    queryKey: ['portal', 'deliveries', orderId],
    queryFn: () => deliveriesApi.list(orderId),
  })

  const items = deliveries?.items ?? []

  return (
    <Card>
      <CardHeader>
        <CardTitle>Deliveries</CardTitle>
      </CardHeader>
      <CardContent>
        {items.length === 0 ? (
          <p className="text-sm text-muted-foreground">No deliveries yet.</p>
        ) : (
          <ul className="flex flex-col gap-2">
            {items.map((delivery) => (
              <li key={delivery.id} className="flex items-center gap-2">
                <span>{delivery.reference}</span>
                <StatusBadge status={delivery.status} />
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}
