import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { SearchIcon } from 'lucide-react'
import { PageHeader } from '@/components/PageHeader'
import { PaginationBar } from '@/components/PaginationBar'
import { QueryState } from '@/components/QueryState'
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group'
import { ScrollArea, ScrollBar } from '@/components/ui/scroll-area'
import { Skeleton } from '@/components/ui/skeleton'
import { useDebouncedValue } from '@/hooks/use-debounced-value'
import { ProductCard } from '@/features/portal/components/ProductCard'
import { catalogApi, flattenCategories } from '@/lib/api/catalog'
import { cn } from '@/lib/utils'

const PER_PAGE = 24

export function PortalCatalogPage() {
  const [params, setParams] = useSearchParams()
  const categoryId = params.get('category') ?? ''
  const [search, setSearch] = useState(params.get('q') ?? '')
  const [page, setPage] = useState(1)
  const debouncedSearch = useDebouncedValue(search.trim())

  const { data: categories } = useQuery({
    queryKey: ['portal', 'categories'],
    queryFn: () => catalogApi.listCategories(),
  })

  const { data, isLoading, error, isFetching } = useQuery({
    queryKey: ['portal', 'products', categoryId, debouncedSearch, page],
    queryFn: () =>
      catalogApi.listProducts({
        category: categoryId || undefined,
        search: debouncedSearch || undefined,
        per_page: PER_PAGE,
        page,
      }),
    placeholderData: keepPreviousData,
  })

  const selectCategory = (id: string) => {
    setPage(1)
    setParams(id ? { category: id } : {}, { replace: true })
  }

  const categoryOptions = [{ id: '', label: 'All' }, ...flattenCategories(categories?.items ?? [])]

  return (
    <section className="flex flex-col gap-6">
      <PageHeader title="Shop" description="Handmade pottery. Prices shown are your prices." />

      <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <ScrollArea className="max-w-full">
          <div className="flex gap-2 pb-1" role="group" aria-label="Filter by category">
            {categoryOptions.map((category) => (
              <button
                key={category.id || 'all'}
                type="button"
                onClick={() => selectCategory(category.id)}
                aria-pressed={categoryId === category.id}
                className={cn(
                  'shrink-0 rounded-full border px-3 py-1 text-sm whitespace-nowrap transition-colors',
                  categoryId === category.id
                    ? 'border-foreground bg-foreground text-background'
                    : 'bg-background text-muted-foreground hover:text-foreground',
                )}
              >
                {category.label}
              </button>
            ))}
          </div>
          <ScrollBar orientation="horizontal" />
        </ScrollArea>
        <InputGroup className="md:max-w-xs">
          <InputGroupAddon>
            <SearchIcon />
          </InputGroupAddon>
          <InputGroupInput
            placeholder="Search products"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
            aria-label="Search products"
          />
        </InputGroup>
      </div>

      <QueryState
        isLoading={isLoading}
        error={error ? 'Unable to load catalog.' : null}
        isEmpty={data?.items.length === 0}
        emptyTitle="No products match your filters"
        emptyDescription="Try a different category or search term."
        loadingSkeleton={<CatalogSkeleton />}
      >
        <div className={cn('flex flex-col gap-6', isFetching && 'opacity-70 transition-opacity')}>
          <div className="grid grid-cols-2 gap-x-4 gap-y-8 sm:grid-cols-3 lg:grid-cols-4">
            {(data?.items ?? []).map((product) => (
              <ProductCard key={product.id} product={product} />
            ))}
          </div>
          {data ? (
            <PaginationBar
              page={data.meta.page}
              totalPages={data.meta.total_pages}
              total={data.meta.total}
              noun={data.meta.total === 1 ? 'product' : 'products'}
              onPageChange={(next) => {
                setPage(next)
                window.scrollTo({ top: 0, behavior: 'smooth' })
              }}
            />
          ) : null}
        </div>
      </QueryState>
    </section>
  )
}

function CatalogSkeleton() {
  return (
    <div className="grid grid-cols-2 gap-x-4 gap-y-8 sm:grid-cols-3 lg:grid-cols-4">
      {Array.from({ length: 8 }, (_, index) => (
        <div key={index} className="flex flex-col gap-3">
          <Skeleton className="aspect-square rounded-xl" />
          <Skeleton className="h-4 w-2/3" />
          <Skeleton className="h-4 w-1/3" />
        </div>
      ))}
    </div>
  )
}
