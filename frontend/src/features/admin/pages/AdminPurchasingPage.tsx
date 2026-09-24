import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { PlusIcon } from 'lucide-react'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { PermissionGate } from '@/features/auth/components/PermissionGate'
import { NewSupplierDialog } from '@/features/admin/purchasing/NewSupplierDialog'
import { purchasingApi } from '@/lib/api/purchasing'
import { PERMISSIONS } from '@/lib/auth/permissions'

export function AdminSuppliersPage() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['admin', 'suppliers'],
    queryFn: () => purchasingApi.listSuppliers({ per_page: 100 }),
  })

  return (
    <section className="flex flex-col gap-6">
      <PageHeader
        title="Suppliers"
        action={
          <PermissionGate permission={PERMISSIONS.purchasingManage}>
            <NewSupplierDialog />
          </PermissionGate>
        }
      />
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
            { key: 'phone', header: 'Phone', cell: (supplier) => supplier.contact_phone ?? '—' },
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
      <PageHeader
        title="Purchase Orders"
        action={
          <PermissionGate permission={PERMISSIONS.purchasingManage}>
            <Button nativeButton={false} render={<Link to="/admin/purchasing/new" />}>
              <PlusIcon data-icon="inline-start" />
              New purchase order
            </Button>
          </PermissionGate>
        }
      />
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
            {
              key: 'reference',
              header: 'Reference',
              primary: true,
              cell: (po) => (
                <Link to={`/admin/purchasing/${po.id}`} className="underline-offset-4 hover:underline">
                  {po.reference}
                </Link>
              ),
            },
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
