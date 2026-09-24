import { Link } from 'react-router-dom'
import { MoneyText } from '@/components/MoneyText'
import { ProductImage } from '@/components/ProductImage'
import { StockBadge } from '@/components/StockBadge'
import type { ProductSummary } from '@/lib/api/catalog'

export function ProductCard({ product }: { product: ProductSummary }) {
  return (
    <Link to={`/portal/catalog/${product.id}`} className="group flex flex-col gap-3">
      <div className="relative aspect-square overflow-hidden rounded-xl border bg-muted">
        <ProductImage
          url={product.primary_image_url}
          alt={product.name}
          className="size-full object-cover transition-transform duration-300 group-hover:scale-[1.03]"
          iconClassName="size-8"
        />
        <StockBadge
          status={product.stock_status}
          backorderAllowed={product.backorder_policy === 'allow'}
          className="absolute top-2 left-2 shadow-sm"
        />
      </div>
      <div className="flex flex-col gap-0.5">
        <span className="text-xs text-muted-foreground">{product.category_name ?? 'Pottery'}</span>
        <span className="font-medium underline-offset-4 group-hover:underline">{product.name}</span>
        <span className="text-sm">
          {product.from_price ? (
            <>
              {product.variant_count > 1 ? <span className="text-muted-foreground">From </span> : null}
              <MoneyText amount={product.from_price.amount} currency={product.from_price.currency} />
            </>
          ) : (
            <span className="text-muted-foreground">Price on request</span>
          )}
        </span>
      </div>
    </Link>
  )
}
