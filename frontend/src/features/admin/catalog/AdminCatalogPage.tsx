import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable, type ResponsiveTableColumn } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { Badge } from '@/components/ui/badge'
import { buttonVariants } from '@/components/ui/button'
import { PermissionGate } from '@/features/auth/components/PermissionGate'
import { catalogApi, type ProductSummary } from '@/lib/api/catalog'
import { PERMISSIONS } from '@/lib/auth/permissions'

const columns: ResponsiveTableColumn<ProductSummary>[] = [
  {
    key: 'name',
    header: 'Name',
    primary: true,
    cell: (product) => (
      <Link to={`/admin/catalog/${product.id}`} className="underline-offset-4 hover:underline">
        {product.name}
      </Link>
    ),
  },
  {
    key: 'category',
    header: 'Category',
    mobile: true,
    cell: (product) => product.category_name ?? '—',
  },
  {
    key: 'visibility',
    header: 'Visibility',
    cell: (product) => <StatusBadge status={product.visibility} />,
  },
  {
    key: 'from_price',
    header: 'From price',
    mobile: true,
    cell: (product) =>
      product.from_price ? (
        <MoneyText amount={product.from_price.amount} currency={product.from_price.currency} />
      ) : (
        '—'
      ),
  },
  {
    key: 'variants',
    header: 'Variants',
    className: 'tabular-nums',
    cell: (product) => product.variant_count,
  },
  {
    key: 'status',
    header: 'Status',
    mobile: true,
    cell: (product) => (
      <Badge variant={product.is_active ? 'secondary' : 'outline'}>
        {product.is_active ? 'Active' : 'Inactive'}
      </Badge>
    ),
  },
]

export function AdminCatalogPage() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['admin', 'products'],
    queryFn: () => catalogApi.listProducts({ per_page: 50 }),
  })

  return (
    <section className="flex flex-col gap-6">
      <PageHeader
        title="Catalog"
        description="Manage products, variants, categories, and pricing."
        action={
          <>
            <PermissionGate permission={PERMISSIONS.catalogManage}>
              <Link to="/admin/catalog/new" className={buttonVariants()}>
                New product
              </Link>
            </PermissionGate>
            <Link
              to="/admin/catalog/categories"
              className={buttonVariants({ variant: 'outline' })}
            >
              Categories
            </Link>
          </>
        }
      />

      <QueryState
        isLoading={isLoading}
        error={error ? 'Failed to load products.' : null}
        isEmpty={!!data && data.items.length === 0}
        emptyTitle="No products"
        emptyDescription="There are no products in the catalog yet."
        emptyAction={
          <PermissionGate permission={PERMISSIONS.catalogManage}>
            <Link to="/admin/catalog/new" className={buttonVariants()}>
              New product
            </Link>
          </PermissionGate>
        }
      >
        {data ? (
          <div className="flex flex-col gap-3">
            <ResponsiveTable
              data={data.items}
              columns={columns}
              getRowKey={(product) => product.id}
            />
            <p className="text-muted-foreground">{data.meta.total} products</p>
          </div>
        ) : null}
      </QueryState>
    </section>
  )
}
