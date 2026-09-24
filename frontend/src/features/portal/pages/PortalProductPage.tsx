import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { useCart } from '@/features/portal/context/CartContext'
import { catalogApi } from '@/lib/api/catalog'
import { apiUrl } from '@/lib/api/client'

export function PortalProductPage() {
  const { id } = useParams()
  const { addItem } = useCart()

  const { data: product, isLoading, error } = useQuery({
    queryKey: ['portal', 'product', id],
    queryFn: () => catalogApi.getProduct(id!),
    enabled: Boolean(id),
  })

  const primaryImage = product?.media.find((item) => item.is_primary) ?? product?.media[0]
  const variants = product?.variants.filter((variant) => variant.is_active) ?? []

  return (
    <section className="flex flex-col gap-6">
      <Link to="/portal/catalog" className="text-sm text-muted-foreground">
        Catalog
      </Link>

      <QueryState
        isLoading={isLoading}
        error={error || (!isLoading && !product) ? 'Product not found.' : null}
      >
        {product ? (
          <div className="grid gap-6 lg:grid-cols-2">
            <div>
              {primaryImage ? (
                <img
                  src={apiUrl(primaryImage.url)}
                  alt={primaryImage.alt_text ?? product.name}
                  className="w-full rounded-lg"
                />
              ) : (
                <div className="aspect-[4/3] rounded-lg bg-muted" />
              )}
            </div>

            <div className="flex flex-col gap-4">
              <PageHeader title={product.name} description={product.description ?? undefined} />
              <Badge variant="secondary">{product.availability_message}</Badge>

              <ResponsiveTable
                data={variants}
                getRowKey={(variant) => variant.id}
                columns={[
                  {
                    key: 'variant',
                    header: 'Variant',
                    primary: true,
                    cell: (variant) => `${variant.name} (${variant.sku})`,
                  },
                  {
                    key: 'attributes',
                    header: 'Attributes',
                    cell: (variant) =>
                      Object.entries(variant.attributes)
                        .map(([k, v]) => `${k}: ${v}`)
                        .join(', ') || '—',
                  },
                  {
                    key: 'price',
                    header: 'Your price',
                    cell: (variant) => (
                      <span className="flex flex-col gap-0.5">
                        <MoneyText amount={variant.price.amount} currency={variant.price.currency} />
                        {variant.price.source === 'customer_override' ? (
                          <span className="text-sm text-muted-foreground">negotiated</span>
                        ) : null}
                      </span>
                    ),
                  },
                ]}
                rowAction={(variant) => (
                  <Button
                    size="sm"
                    onClick={() =>
                      addItem({
                        variantId: variant.id,
                        productId: product.id,
                        productName: product.name,
                        variantName: variant.name,
                        sku: variant.sku,
                      })
                    }
                  >
                    Add to cart
                  </Button>
                )}
              />
            </div>
          </div>
        ) : null}
      </QueryState>
    </section>
  )
}
