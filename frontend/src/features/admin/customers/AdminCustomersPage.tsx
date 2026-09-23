import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable, type ResponsiveTableColumn } from '@/components/ResponsiveTable'
import { Badge } from '@/components/ui/badge'
import { buttonVariants } from '@/components/ui/button'
import { Field, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { customersApi, type CustomerSummary } from '@/lib/api/customers'

const columns: ResponsiveTableColumn<CustomerSummary>[] = [
  {
    key: 'name',
    header: 'Name',
    primary: true,
    cell: (customer) => (
      <Link
        to={`/admin/customers/${customer.id}`}
        className="underline-offset-4 hover:underline"
      >
        {customer.display_name}
      </Link>
    ),
  },
  {
    key: 'type',
    header: 'Type',
    mobile: true,
    cell: (customer) => customer.type,
  },
  {
    key: 'portal',
    header: 'Portal',
    mobile: true,
    cell: (customer) =>
      customer.portal_user_id ? <Badge variant="secondary">Linked</Badge> : '—',
  },
  {
    key: 'status',
    header: 'Status',
    mobile: true,
    cell: (customer) => (
      <Badge variant={customer.is_active ? 'secondary' : 'outline'}>
        {customer.is_active ? 'Active' : 'Inactive'}
      </Badge>
    ),
  },
]

export function AdminCustomersPage() {
  const [search, setSearch] = useState('')
  const { data, isLoading, error } = useQuery({
    queryKey: ['admin', 'customers', search],
    queryFn: () => customersApi.list({ search: search || undefined, per_page: 50 }),
  })

  return (
    <section className="flex flex-col gap-6">
      <PageHeader
        title="Customers"
        description="Customer records, contacts, addresses, and portal accounts."
        action={
          <>
            <Field className="sm:w-56">
              <FieldLabel htmlFor="customer-search" className="sr-only">
                Search customers
              </FieldLabel>
              <Input
                id="customer-search"
                placeholder="Search customers"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </Field>
            <Link to="/admin/customers/new" className={buttonVariants()}>
              New customer
            </Link>
          </>
        }
      />

      <QueryState
        isLoading={isLoading}
        error={error ? 'Failed to load customers.' : null}
        isEmpty={!!data && data.items.length === 0}
        emptyTitle="No customers"
        emptyDescription="There are no customers matching this view."
        emptyAction={
          <Link to="/admin/customers/new" className={buttonVariants()}>
            New customer
          </Link>
        }
      >
        {data ? (
          <ResponsiveTable
            data={data.items}
            columns={columns}
            getRowKey={(customer) => customer.id}
          />
        ) : null}
      </QueryState>
    </section>
  )
}
