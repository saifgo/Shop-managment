import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { PlusIcon } from 'lucide-react'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { Button } from '@/components/ui/button'
import { Field, FieldLabel } from '@/components/ui/field'
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select'
import { PermissionGate } from '@/features/auth/components/PermissionGate'
import {
  DOCUMENT_TYPE_LABELS,
  documentsApi,
  documentTypeLabel,
  isDocumentType,
  type DocumentType,
} from '@/lib/api/documents'
import { PERMISSIONS } from '@/lib/auth/permissions'

export function AdminDocumentsPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const typeParam = searchParams.get('type')
  const type = isDocumentType(typeParam) ? typeParam : undefined
  const page = Math.max(1, Number(searchParams.get('page')) || 1)

  const { data, isLoading, error } = useQuery({
    queryKey: ['admin', 'documents', type ?? 'all', page],
    queryFn: () => documentsApi.list({ type, page }),
    placeholderData: (previous) => previous,
  })

  const updateParams = (next: { type?: string; page?: number }) => {
    const params = new URLSearchParams()
    const nextType = 'type' in next ? next.type : type
    if (nextType) params.set('type', nextType)
    if (next.page && next.page > 1) params.set('page', String(next.page))
    setSearchParams(params)
  }

  const newHref = type && type !== 'CREDIT_NOTE' ? `/admin/documents/new?type=${type}` : '/admin/documents/new'

  return (
    <section className="flex flex-col gap-6">
      <PageHeader
        title="Documents"
        description="Invoices, quotes, proformas, order forms, delivery notes and goods issue notes."
        action={
          <PermissionGate permission={PERMISSIONS.documentsManage}>
            <Button nativeButton={false} render={<Link to={newHref} />}>
              <PlusIcon data-icon="inline-start" />
              New document
            </Button>
          </PermissionGate>
        }
      />

      <Field className="max-w-xs">
        <FieldLabel htmlFor="document-type-filter">Type</FieldLabel>
        <NativeSelect
          id="document-type-filter"
          className="w-full"
          value={type ?? ''}
          onChange={(event) => updateParams({ type: event.target.value || undefined, page: 1 })}
        >
          <NativeSelectOption value="">All types</NativeSelectOption>
          {(Object.keys(DOCUMENT_TYPE_LABELS) as DocumentType[]).map((value) => (
            <NativeSelectOption key={value} value={value}>
              {documentTypeLabel(value)}
            </NativeSelectOption>
          ))}
        </NativeSelect>
      </Field>

      <QueryState
        isLoading={isLoading}
        error={error ? 'Unable to load documents.' : null}
        isEmpty={data?.items.length === 0}
        emptyTitle="No documents"
        emptyDescription={type ? 'No documents of this type yet.' : 'Documents you create will appear here.'}
      >
        <ResponsiveTable
          wide
          data={data?.items ?? []}
          getRowKey={(document) => document.id}
          columns={[
            {
              key: 'number',
              header: 'Number',
              primary: true,
              cell: (document) => (
                <Link to={`/admin/documents/${document.id}`} className="underline-offset-4 hover:underline">
                  {document.document_number ?? 'Draft'}
                </Link>
              ),
            },
            {
              key: 'type',
              header: 'Type',
              mobile: true,
              cell: (document) => DOCUMENT_TYPE_LABELS[document.document_type]?.french ?? document.document_type,
            },
            { key: 'customer', header: 'Customer', mobile: true, cell: (document) => document.customer_display_name },
            { key: 'status', header: 'Status', mobile: true, cell: (document) => <StatusBadge status={document.status} /> },
            {
              key: 'total',
              header: 'Total',
              cell: (document) => (
                <MoneyText amount={document.grand_total.amount} currency={document.grand_total.currency} />
              ),
            },
            {
              key: 'date',
              header: 'Date',
              cell: (document) => new Date(document.issued_at ?? document.created_at).toLocaleDateString(),
            },
          ]}
        />

        {data && data.meta.total_pages > 1 ? (
          <div className="flex items-center justify-end gap-2 text-sm">
            <span className="text-muted-foreground">
              Page {data.meta.page} of {data.meta.total_pages}
            </span>
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => updateParams({ page: page - 1 })}>
              Previous
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={page >= data.meta.total_pages}
              onClick={() => updateParams({ page: page + 1 })}
            >
              Next
            </Button>
          </div>
        ) : null}
      </QueryState>
    </section>
  )
}
