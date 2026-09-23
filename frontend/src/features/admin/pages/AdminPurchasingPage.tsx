import { useQuery } from '@tanstack/react-query'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { Badge } from '@/components/ui/badge'
import { purchasingApi } from '@/lib/api/purchasing'

export function AdminSuppliersPage() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['admin', 'suppliers'],
    queryFn: () => purchasingApi.listSuppliers(),
  })

  return (
    <section className="flex flex-col gap-6">
      <PageHeader title="Suppliers" />
      <QueryState
        isLoading={isLoading}
        error={error ? 'Failed to load suppliers.' : null}
        isEmpty={data?.items.length === 0}
        emptyTitle="No suppliers"
        emptyDescription="Suppliers will appear here once they are added."
      >
        <ResponsiveTable
          data={data?.items ?? []}
          getRowKey={(supplier) => supplier.id}
          columns={[
            { key: 'code', header: 'Code', primary: true, cell: (supplier) => supplier.code },
            { key: 'name', header: 'Name', cell: (supplier) => supplier.name },
            { key: 'email', header: 'Email', cell: (supplier) => supplier.contact_email ?? '—' },
            {
              key: 'status',
              header: 'Status',
              cell: (supplier) => (
                <Badge variant={supplier.is_active ? 'secondary' : 'outline'}>
                  {supplier.is_active ? 'Active' : 'Inactive'}
                </Badge>
              ),
            },
          ]}
        />
      </QueryState>
    </section>
  )
}

export function AdminPurchasingPage() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['admin', 'purchase-orders'],
    queryFn: () => purchasingApi.listPurchaseOrders(),
  })

  return (
    <section className="flex flex-col gap-6">
      <PageHeader title="Purchase Orders" />
      <QueryState
        isLoading={isLoading}
        error={error ? 'Failed to load purchase orders.' : null}
        isEmpty={data?.items.length === 0}
        emptyTitle="No purchase orders"
        emptyDescription="Purchase orders will appear here once they are created."
      >
        <ResponsiveTable
          data={data?.items ?? []}
          getRowKey={(po) => po.id}
          columns={[
            { key: 'reference', header: 'Reference', primary: true, cell: (po) => po.reference },
            { key: 'supplier', header: 'Supplier', cell: (po) => po.supplier_name },
            {
              key: 'status',
              header: 'Status',
              cell: (po) => <StatusBadge status={po.status} />,
            },
            {
              key: 'total',
              header: 'Total',
              cell: (po) => <MoneyText amount={po.grand_total.amount} currency={po.grand_total.currency} />,
            },
          ]}
        />
      </QueryState>
    </section>
  )
}
