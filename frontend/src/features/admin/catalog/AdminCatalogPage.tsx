import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ImageIcon, PlusIcon, SearchIcon, TagsIcon } from 'lucide-react'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { PaginationBar } from '@/components/PaginationBar'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable, type ResponsiveTableColumn } from '@/components/ResponsiveTable'
import { StatusBadge } from '@/components/StatusBadge'
import { StockBadge } from '@/components/StockBadge'
import { Badge } from '@/components/ui/badge'
import { buttonVariants } from '@/components/ui/button'
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group'
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select'
import { PermissionGate } from '@/features/auth/components/PermissionGate'
import { useDebouncedValue } from '@/hooks/use-debounced-value'
import { catalogApi, flattenCategories, type ProductSummary } from '@/lib/api/catalog'
import { apiUrl } from '@/lib/api/client'
import { PERMISSIONS } from '@/lib/auth/permissions'

const PER_PAGE = 25

const columns: ResponsiveTableColumn<ProductSummary>[] = [
  {
    key: 'name',
    header: 'Product',
    primary: true,
    cell: (product) => (
      <Link to={`/admin/catalog/${product.id}`} className="group flex items-center gap-3">
        {product.primary_image_url ? (
          <img
            src={apiUrl(product.primary_image_url)}
            alt=""
            className="size-10 shrink-0 rounded-md border object-cover"
            loading="lazy"
          />
        ) : (
          <div className="flex size-10 shrink-0 items-center justify-center rounded-md border bg-muted text-muted-foreground">
            <ImageIcon className="size-4" />
          </div>
        )}
        <span className="flex min-w-0 flex-col">
          <span className="truncate font-medium underline-offset-4 group-hover:underline">{product.name}</span>
          <span className="truncate text-xs text-muted-foreground">
            {product.variant_count} {product.variant_count === 1 ? 'variant' : 'variants'}
          </span>
        </span>
      </Link>
    ),
  },
  {
    key: 'category',
    header: 'Category',
    cell: (product) => product.category_name ?? <span className="text-muted-foreground">Uncategorized</span>,
  },
  {
    key: 'from_price',
    header: 'From',
    mobile: true,
    cell: (product) =>
      product.from_price ? (
        <MoneyText amount={product.from_price.amount} currency={product.from_price.currency} />
      ) : (
        <span className="text-muted-foreground">No price</span>
      ),
  },
  {
    key: 'stock',
    header: 'Stock',
    mobile: true,
    cell: (product) =>
      product.variant_count === 0 ? (
        <span className="text-muted-foreground">No variants</span>
      ) : (
        <StockBadge status={product.stock_status} quantity={product.available_quantity} showQuantity />
      ),
  },
  {
    key: 'visibility',
    header: 'Visibility',
    cell: (product) => <StatusBadge status={product.visibility} />,
  },
  {
    key: 'status',
    header: 'Status',
    mobile: true,
    cell: (product) => (
      <Badge variant={product.is_active ? 'secondary' : 'outline'}>{product.is_active ? 'Active' : 'Archived'}</Badge>
    ),
  },
]

export function AdminCatalogPage() {
  const [search, setSearch] = useState('')
  const [categoryId, setCategoryId] = useState('')
  const [status, setStatus] = useState<'' | 'active' | 'inactive'>('')
  const [visibility, setVisibility] = useState('')
  const [page, setPage] = useState(1)
  const debouncedSearch = useDebouncedValue(search.trim())

  const filters = { search: debouncedSearch, categoryId, status, visibility, page }
  const { data, isLoading, error, isFetching } = useQuery({
    queryKey: ['admin', 'products', filters],
    queryFn: () =>
      catalogApi.listProducts({
        page,
        per_page: PER_PAGE,
        search: debouncedSearch || undefined,
        category: categoryId || undefined,
        status: status || undefined,
        visibility: visibility || undefined,
      }),
    placeholderData: keepPreviousData,
  })

  const { data: categories } = useQuery({
    queryKey: ['admin', 'categories'],
    queryFn: () => catalogApi.listCategories(),
  })

  const hasFilters = Boolean(debouncedSearch || categoryId || status || visibility)
  const resetPage = <T,>(setter: (value: T) => void) => (value: T) => {
    setter(value)
    setPage(1)
  }

  return (
    <section className="flex flex-col gap-6">
      <PageHeader
        title="Products"
        description="Everything you sell: pieces, variants, prices, pictures, and stock."
        action={
          <>
            <PermissionGate permission={PERMISSIONS.catalogManage}>
              <Link to="/admin/catalog/categories" className={buttonVariants({ variant: 'outline' })}>
                <TagsIcon data-icon="inline-start" />
                Categories
              </Link>
              <Link to="/admin/catalog/new" className={buttonVariants()}>
                <PlusIcon data-icon="inline-start" />
                New product
              </Link>
            </PermissionGate>
          </>
        }
      />

      <div className="flex flex-col gap-2 md:flex-row md:items-center">
        <InputGroup className="md:max-w-sm">
          <InputGroupAddon>
            <SearchIcon />
          </InputGroupAddon>
          <InputGroupInput
            placeholder="Search name or SKU"
            value={search}
            onChange={(e) => resetPage(setSearch)(e.target.value)}
            aria-label="Search products"
          />
        </InputGroup>
        <NativeSelect
          className="md:w-48"
          value={categoryId}
          onChange={(e) => resetPage(setCategoryId)(e.target.value)}
          aria-label="Filter by category"
        >
          <NativeSelectOption value="">All categories</NativeSelectOption>
          {flattenCategories(categories?.items ?? []).map((category) => (
            <NativeSelectOption key={category.id} value={category.id}>
              {category.label}
            </NativeSelectOption>
          ))}
        </NativeSelect>
        <NativeSelect
          className="md:w-40"
          value={visibility}
          onChange={(e) => resetPage(setVisibility)(e.target.value)}
          aria-label="Filter by visibility"
        >
          <NativeSelectOption value="">Any visibility</NativeSelectOption>
          <NativeSelectOption value="public">Public</NativeSelectOption>
          <NativeSelectOption value="hidden">Hidden</NativeSelectOption>
          <NativeSelectOption value="internal">Internal</NativeSelectOption>
        </NativeSelect>
        <NativeSelect
          className="md:w-36"
          value={status}
          onChange={(e) => resetPage(setStatus)(e.target.value as '' | 'active' | 'inactive')}
          aria-label="Filter by status"
        >
          <NativeSelectOption value="">Any status</NativeSelectOption>
          <NativeSelectOption value="active">Active</NativeSelectOption>
          <NativeSelectOption value="inactive">Archived</NativeSelectOption>
        </NativeSelect>
      </div>

      <QueryState
        isLoading={isLoading}
        error={error ? 'Failed to load products.' : null}
        isEmpty={!!data && data.items.length === 0}
        emptyTitle={hasFilters ? 'No products match these filters' : 'No products yet'}
        emptyDescription={
          hasFilters ? 'Try another search or clear the filters.' : 'Create your first product to start selling.'
        }
        emptyAction={
          hasFilters ? undefined : (
            <PermissionGate permission={PERMISSIONS.catalogManage}>
              <Link to="/admin/catalog/new" className={buttonVariants()}>
                New product
              </Link>
            </PermissionGate>
          )
        }
      >
        {data ? (
          <div className={isFetching ? 'flex flex-col gap-3 opacity-70 transition-opacity' : 'flex flex-col gap-3'}>
            <ResponsiveTable data={data.items} columns={columns} getRowKey={(product) => product.id} />
            <PaginationBar
              page={data.meta.page}
              totalPages={data.meta.total_pages}
              total={data.meta.total}
              noun={data.meta.total === 1 ? 'product' : 'products'}
              onPageChange={setPage}
            />
          </div>
        ) : null}
      </QueryState>
    </section>
  )
}
