import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select'
import { catalogApi, type CategoryNode } from '@/lib/api/catalog'
import { apiUrl } from '@/lib/api/client'

export function PortalCatalogPage() {
  const [categoryId, setCategoryId] = useState('')
  const [search, setSearch] = useState('')

  const { data: categories } = useQuery({
    queryKey: ['portal', 'categories'],
    queryFn: () => catalogApi.listCategories(),
  })

  const { data, isLoading, error } = useQuery({
    queryKey: ['portal', 'products', categoryId, search],
    queryFn: () =>
      catalogApi.listProducts({
        category: categoryId || undefined,
        search: search || undefined,
        per_page: 24,
      }),
  })

  return (
    <section className="flex flex-col gap-6">
      <PageHeader title="Catalog" description="Browse products with your negotiated pricing applied by the server." />

      <div className="flex flex-col gap-3 sm:flex-row">
        <NativeSelect
          className="w-full sm:w-56"
          value={categoryId}
          onChange={(e) => setCategoryId(e.target.value)}
          aria-label="Filter by category"
        >
          <NativeSelectOption value="">All categories</NativeSelectOption>
          {flattenCategories(categories?.items ?? []).map((category) => (
            <NativeSelectOption key={category.id} value={category.id}>
              {category.label}
            </NativeSelectOption>
          ))}
        </NativeSelect>
        <Input
          placeholder="Search products"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          aria-label="Search products"
          className="sm:max-w-xs"
        />
      </div>

      <QueryState
        isLoading={isLoading}
        error={error ? 'Unable to load catalog.' : null}
        isEmpty={data?.items.length === 0}
        emptyTitle="No products match your filters"
        emptyDescription="Try a different category or search term."
      >
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {(data?.items ?? []).map((product) => (
            <Link key={product.id} to={`/portal/catalog/${product.id}`}>
              <Card>
                {product.primary_image_url ? (
                  <img src={apiUrl(product.primary_image_url)} alt={product.name} loading="lazy" />
                ) : (
                  <div className="aspect-[4/3] bg-muted" />
                )}
                <CardHeader>
                  <CardTitle>{product.name}</CardTitle>
                  <CardDescription>{product.category_name ?? 'Uncategorized'}</CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-2">
                  {product.from_price ? (
                    <MoneyText amount={product.from_price.amount} currency={product.from_price.currency} />
                  ) : (
                    '—'
                  )}
                  <Badge variant="secondary">
                    {product.backorder_policy === 'allow' ? 'Backorders allowed' : 'In-stock only'}
                  </Badge>
                </CardContent>
              </Card>
            </Link>
          ))}
        </div>
      </QueryState>
    </section>
  )
}

function flattenCategories(nodes: CategoryNode[], prefix = ''): Array<{ id: string; label: string }> {
  return nodes.flatMap((node) => [
    { id: node.id, label: `${prefix}${node.name}` },
    ...flattenCategories(node.children, `${prefix}${node.name} / `),
  ])
}
