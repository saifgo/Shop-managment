import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { DownloadIcon } from 'lucide-react'
import { toast } from 'sonner'
import { ConfirmAction } from '@/components/ConfirmAction'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Spinner } from '@/components/ui/spinner'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { documentsApi, documentTypeLabel, type CommercialDocument } from '@/lib/api/documents'
import { PERMISSIONS } from '@/lib/auth/permissions'

export function AdminDocumentDetailPage() {
  const { id } = useParams()
  const queryClient = useQueryClient()
  const { can } = useAuth()
  const [dueDate, setDueDate] = useState('')

  const { data: document, isLoading, error } = useQuery({
    queryKey: ['admin', 'document', id],
    queryFn: () => documentsApi.get(id!),
    enabled: Boolean(id),
  })

  const onChanged = (updated: CommercialDocument) => {
    queryClient.setQueryData(['admin', 'document', id], updated)
    void queryClient.invalidateQueries({ queryKey: ['admin', 'documents'] })
    void queryClient.invalidateQueries({ queryKey: ['admin', 'invoices'] })
  }

  const issue = useMutation({
    mutationFn: () => documentsApi.issue(id!, dueDate || undefined),
    onSuccess: onChanged,
    onError: (err) => toast.error(err.message),
  })
  const cancel = useMutation({
    mutationFn: () => documentsApi.cancel(id!),
    onSuccess: onChanged,
    onError: (err) => toast.error(err.message),
  })
  const download = useMutation({
    mutationFn: (doc: CommercialDocument) => documentsApi.downloadPdf(doc),
    onError: (err) => toast.error(err.message),
  })

  const isDraft = document ? !document.is_posted && document.status !== 'CANCELLED' : false

  return (
    <section className="flex flex-col gap-6">
      <Link to="/admin/documents" className="text-sm text-muted-foreground">
        Documents
      </Link>

      <QueryState isLoading={isLoading} error={error || (!isLoading && !document) ? 'Document not found.' : null}>
        {document ? (
          <>
            <PageHeader
              title={document.document_number ?? `Draft — ${documentTypeLabel(document.document_type)}`}
              description={`${documentTypeLabel(document.document_type)} · ${document.customer_display_name}`}
              action={
                <>
                  <StatusBadge status={document.status} />
                  {document.is_posted ? (
                    <Button variant="outline" disabled={download.isPending} onClick={() => download.mutate(document)}>
                      {download.isPending ? <Spinner data-icon="inline-start" /> : <DownloadIcon data-icon="inline-start" />}
                      PDF
                    </Button>
                  ) : null}
                  {isDraft && can(PERMISSIONS.documentsCancel) ? (
                    <ConfirmAction
                      variant="destructive"
                      title="Cancel this draft?"
                      description="The draft will be kept for reference but can no longer be issued."
                      confirmLabel="Cancel draft"
                      cancelLabel="Keep draft"
                      trigger={<Button variant="outline">Cancel draft</Button>}
                      onConfirm={async () => {
                        await cancel.mutateAsync()
                      }}
                    />
                  ) : null}
                  {isDraft && can(PERMISSIONS.documentsManage) ? (
                    <Button disabled={issue.isPending} onClick={() => issue.mutate()}>
                      {issue.isPending ? <Spinner data-icon="inline-start" /> : null}
                      Issue
                    </Button>
                  ) : null}
                </>
              }
            />

            {isDraft && document.document_type === 'INVOICE' && can(PERMISSIONS.documentsManage) ? (
              <div className="flex max-w-xs flex-col gap-2">
                <Label htmlFor="issue-due-date">Due date when issued</Label>
                <Input id="issue-due-date" type="date" value={dueDate} onChange={(event) => setDueDate(event.target.value)} />
                <p className="text-xs text-muted-foreground">Leave empty for 30 days after issue.</p>
              </div>
            ) : null}

            <div className="grid gap-6 lg:grid-cols-2">
              <Card>
                <CardHeader>
                  <CardTitle>Details</CardTitle>
                </CardHeader>
                <CardContent>
                  <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                    <dt className="text-muted-foreground">Customer</dt>
                    <dd>
                      <Link to={`/admin/customers/${document.customer_id}`} className="underline-offset-4 hover:underline">
                        {document.customer_display_name}
                      </Link>
                    </dd>
                    <dt className="text-muted-foreground">Created</dt>
                    <dd>{new Date(document.created_at).toLocaleString()}</dd>
                    <dt className="text-muted-foreground">Issued</dt>
                    <dd>{document.issued_at ? new Date(document.issued_at).toLocaleString() : '—'}</dd>
                    {document.due_date ? (
                      <>
                        <dt className="text-muted-foreground">Due</dt>
                        <dd>{new Date(document.due_date).toLocaleDateString()}</dd>
                      </>
                    ) : null}
                    {document.order_id ? (
                      <>
                        <dt className="text-muted-foreground">Order</dt>
                        <dd>
                          <Link to={`/admin/orders/${document.order_id}`} className="underline-offset-4 hover:underline">
                            View order
                          </Link>
                        </dd>
                      </>
                    ) : null}
                    {document.notes ? (
                      <>
                        <dt className="text-muted-foreground">Notes</dt>
                        <dd className="whitespace-pre-line">{document.notes}</dd>
                      </>
                    ) : null}
                  </dl>
                </CardContent>
              </Card>

              <Card>
                <CardHeader>
                  <CardTitle>Totals</CardTitle>
                </CardHeader>
                <CardContent>
                  <dl className="flex flex-col gap-2 text-sm">
                    <MoneyRow label="Subtotal" money={document.subtotal} />
                    <MoneyRow label="Discount" money={document.discount_total} />
                    <MoneyRow label="Tax" money={document.tax_total} />
                    <MoneyRow label="Total" money={document.grand_total} strong />
                    {document.document_type === 'INVOICE' && document.is_posted ? (
                      <>
                        <MoneyRow label="Paid" money={document.amount_paid} />
                        <MoneyRow label="Amount due" money={document.amount_due} strong />
                      </>
                    ) : null}
                  </dl>
                </CardContent>
              </Card>
            </div>

            <ResponsiveTable
              wide
              data={document.lines}
              getRowKey={(line) => line.id}
              columns={[
                { key: 'description', header: 'Description', primary: true, cell: (line) => line.description },
                { key: 'sku', header: 'SKU', cell: (line) => line.sku || '—' },
                {
                  key: 'quantity',
                  header: 'Qty',
                  mobile: true,
                  cell: (line) => <span className="tabular-nums">{Number(line.quantity)}</span>,
                },
                {
                  key: 'unit',
                  header: 'Unit price',
                  cell: (line) => <MoneyText amount={line.unit_price.amount} currency={line.unit_price.currency} />,
                },
                { key: 'tax', header: 'Tax', cell: (line) => `${Number(line.tax_rate)}%` },
                {
                  key: 'discount',
                  header: 'Discount',
                  cell: (line) => <MoneyText amount={line.discount_amount.amount} currency={line.discount_amount.currency} />,
                },
                {
                  key: 'total',
                  header: 'Total',
                  mobile: true,
                  cell: (line) => <MoneyText amount={line.line_total.amount} currency={line.line_total.currency} />,
                },
              ]}
            />
          </>
        ) : null}
      </QueryState>
    </section>
  )
}

function MoneyRow({ label, money, strong }: { label: string; money: { amount: string; currency: string }; strong?: boolean }) {
  return (
    <div className={strong ? 'flex justify-between font-medium' : 'flex justify-between text-muted-foreground'}>
      <dt>{label}</dt>
      <dd>
        <MoneyText amount={money.amount} currency={money.currency} />
      </dd>
    </div>
  )
}
