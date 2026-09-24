import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ArrowLeftIcon, InfoIcon, ShoppingCartIcon, TruckIcon } from 'lucide-react'
import { toast } from 'sonner'
import { MoneyText } from '@/components/MoneyText'
import { ProductImage } from '@/components/ProductImage'
import { QuantityStepper } from '@/components/QuantityStepper'
import { QueryState } from '@/components/QueryState'
import { StockBadge } from '@/components/StockBadge'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { useCart } from '@/features/portal/context/CartContext'
import { catalogApi, type ProductDetail, type ProductVariant } from '@/lib/api/catalog'
import { formatQuantity } from '@/lib/format'
import { cn } from '@/lib/utils'

export function PortalProductPage() {
  const { id } = useParams()

  const { data: product, isLoading, error } = useQuery({
    queryKey: ['portal', 'product', id],
    queryFn: () => catalogApi.getProduct(id!),
    enabled: Boolean(id),
  })

  return (
    <section className="flex flex-col gap-6">
      <Link
        to="/portal/catalog"
        className="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeftIcon className="size-4" />
        Shop
      </Link>

      <QueryState isLoading={isLoading} error={error || (!isLoading && !product) ? 'Product not found.' : null}>
        {product ? <ProductView key={product.id} product={product} /> : null}
      </QueryState>
    </section>
  )
}

function ProductView({ product }: { product: ProductDetail }) {
  const navigate = useNavigate()
  const { addItem, items } = useCart()
  const variants = product.variants.filter((variant) => variant.is_active)
  const [variantId, setVariantId] = useState(
    () => (variants.find((variant) => variant.stock_status !== 'out_of_stock') ?? variants[0])?.id ?? '',
  )
  const [quantity, setQuantity] = useState(1)
  const media = [...product.media].sort((a, b) => Number(b.is_primary) - Number(a.is_primary) || a.sort_order - b.sort_order)
  const [imageIndex, setImageIndex] = useState(0)
  const image = media[imageIndex]

  const variant = variants.find((item) => item.id === variantId)
  const allowBackorder = product.backorder_policy === 'allow'
  const available = variant ? Math.floor(Number(variant.available_quantity)) : 0
  const inCart = variant ? Number(items.find((item) => item.variantId === variant.id)?.quantity ?? 0) : 0
  // Without backorders the cart cannot hold more than what is in stock.
  const maxQuantity = allowBackorder ? undefined : Math.max(0, available - inCart)
  const willBackorder = allowBackorder && quantity + inCart > available
  const canAdd = Boolean(variant) && (allowBackorder || (maxQuantity ?? 0) >= 1)

  const add = () => {
    if (!variant) return
    addItem(
      {
        variantId: variant.id,
        productId: product.id,
        productName: product.name,
        variantName: variant.name,
        sku: variant.sku,
        imageUrl: media[0]?.url ?? null,
      },
      quantity.toFixed(4),
    )
    toast.success(`${quantity} × ${product.name} (${variant.name}) added to your cart.`, {
      action: { label: 'View cart', onClick: () => navigate('/portal/checkout') },
    })
    setQuantity(1)
  }

  return (
    <div className="grid gap-8 lg:grid-cols-2 lg:gap-12">
      <div className="flex flex-col gap-3">
        <div className="aspect-square overflow-hidden rounded-2xl border bg-muted">
          <ProductImage
            url={image?.url}
            alt={image?.alt_text ?? product.name}
            loading="eager"
            className="size-full"
            iconClassName="size-10"
          />
        </div>
        {media.length > 1 ? (
          <div className="flex gap-2 overflow-x-auto pb-1">
            {media.map((item, index) => (
              <button
                key={item.id}
                type="button"
                onClick={() => setImageIndex(index)}
                aria-label={`Show picture ${index + 1}`}
                aria-current={index === imageIndex}
                className={cn(
                  'size-16 shrink-0 overflow-hidden rounded-lg border-2 bg-muted',
                  index === imageIndex ? 'border-foreground' : 'border-transparent opacity-70 hover:opacity-100',
                )}
              >
                <ProductImage url={item.url} alt="" className="size-full" iconClassName="size-4" />
              </button>
            ))}
          </div>
        ) : null}
      </div>

      <div className="flex flex-col gap-6">
        <div className="flex flex-col gap-2">
          {product.category_name ? <span className="text-sm text-muted-foreground">{product.category_name}</span> : null}
          <h1 className="font-heading text-3xl font-medium tracking-tight">{product.name}</h1>
          {variant ? (
            <div className="flex flex-wrap items-baseline gap-2 text-xl">
              <MoneyText amount={variant.price.amount} currency={variant.price.currency} />
              {variant.price.source === 'customer_override' &&
              Number(variant.price.base_amount) > Number(variant.price.amount) ? (
                <>
                  <MoneyText
                    amount={variant.price.base_amount}
                    currency={variant.price.currency}
                    className="text-base text-muted-foreground line-through"
                  />
                  <span className="text-sm text-emerald-700">Your negotiated price</span>
                </>
              ) : null}
            </div>
          ) : null}
          <p className="text-xs text-muted-foreground">Price excludes tax, calculated at checkout.</p>
        </div>

        {variants.length === 0 ? (
          <Alert>
            <InfoIcon />
            <AlertDescription>This product is not available to order right now.</AlertDescription>
          </Alert>
        ) : (
          <>
            {variants.length > 1 ? (
              <fieldset className="flex flex-col gap-2">
                <legend className="mb-2 text-sm font-medium">Choose an option</legend>
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                  {variants.map((option) => (
                    <VariantOption
                      key={option.id}
                      variant={option}
                      selected={option.id === variantId}
                      allowBackorder={allowBackorder}
                      onSelect={() => {
                        setVariantId(option.id)
                        setQuantity(1)
                      }}
                    />
                  ))}
                </div>
              </fieldset>
            ) : variant ? (
              <div className="flex items-center gap-2 text-sm">
                <span className="text-muted-foreground">{variant.name}</span>
                <StockBadge status={variant.stock_status} backorderAllowed={allowBackorder} />
              </div>
            ) : null}

            <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
              <QuantityStepper
                value={quantity}
                onChange={setQuantity}
                min={1}
                max={maxQuantity !== undefined ? Math.max(1, maxQuantity) : undefined}
                label="Quantity"
              />
              <Button size="lg" className="sm:flex-1" disabled={!canAdd} onClick={add}>
                <ShoppingCartIcon data-icon="inline-start" />
                {canAdd ? 'Add to cart' : 'Out of stock'}
              </Button>
            </div>

            {inCart > 0 ? (
              <p className="text-sm text-muted-foreground">
                {formatQuantity(inCart)} already in your cart ·{' '}
                <Link to="/portal/checkout" className="text-foreground underline underline-offset-4">
                  View cart
                </Link>
              </p>
            ) : null}

            {willBackorder ? (
              <Alert>
                <TruckIcon />
                <AlertDescription>
                  {available > 0
                    ? `${available} can ship from stock; the rest will be made for you and shipped when ready.`
                    : 'This piece will be made for your order and shipped when ready.'}
                </AlertDescription>
              </Alert>
            ) : null}
          </>
        )}

        {product.description ? (
          <div className="flex flex-col gap-2 border-t pt-6">
            <h2 className="text-sm font-medium">About this piece</h2>
            <p className="text-sm leading-relaxed whitespace-pre-line text-muted-foreground">{product.description}</p>
          </div>
        ) : null}
      </div>
    </div>
  )
}

function VariantOption({
  variant,
  selected,
  allowBackorder,
  onSelect,
}: {
  variant: ProductVariant
  selected: boolean
  allowBackorder: boolean
  onSelect: () => void
}) {
  const unavailable = variant.stock_status === 'out_of_stock' && !allowBackorder
  const attributes = Object.values(variant.attributes).filter(Boolean).join(' · ')

  return (
    <button
      type="button"
      onClick={onSelect}
      aria-pressed={selected}
      disabled={unavailable}
      className={cn(
        'flex flex-col items-start gap-1 rounded-xl border p-3 text-left transition-colors',
        selected ? 'border-foreground ring-1 ring-foreground' : 'hover:border-foreground/40',
        unavailable && 'cursor-not-allowed opacity-50',
      )}
    >
      <span className="flex w-full items-baseline justify-between gap-2">
        <span className="font-medium">{variant.name}</span>
        <MoneyText amount={variant.price.amount} currency={variant.price.currency} className="text-sm" />
      </span>
      {attributes ? <span className="text-xs text-muted-foreground">{attributes}</span> : null}
      <StockBadge status={variant.stock_status} backorderAllowed={allowBackorder} className="mt-1" />
    </button>
  )
}
