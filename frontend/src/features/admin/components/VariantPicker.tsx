import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ChevronLeftIcon, PlusIcon, SearchIcon } from 'lucide-react'
import { MoneyText } from '@/components/MoneyText'
import { QueryState } from '@/components/QueryState'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { catalogApi } from '@/lib/api/catalog'

export interface PickedVariant {
  variant_id: string
  sku: string
  /** "Product — Variant" */
  label: string
  /** Catalog price as seen by the admin; customer-specific pricing is applied server-side. */
  price: { amount: string; currency: string }
}

interface VariantPickerProps {
  onSelect: (variant: PickedVariant) => void
  triggerLabel?: string
}

function useDebouncedValue(value: string, delayMs: number) {
  const [debounced, setDebounced] = useState(value)

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(value), delayMs)
    return () => window.clearTimeout(timer)
  }, [value, delayMs])

  return debounced
}

export function VariantPicker({ onSelect, triggerLabel = 'Add product' }: VariantPickerProps) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [productId, setProductId] = useState<string | null>(null)
  const debouncedSearch = useDebouncedValue(search.trim(), 250)

  const products = useQuery({
    queryKey: ['admin', 'variant-picker', 'products', debouncedSearch],
    queryFn: () => catalogApi.listProducts({ search: debouncedSearch || undefined, per_page: 20 }),
    enabled: open && productId === null,
  })

  const product = useQuery({
    queryKey: ['admin', 'variant-picker', 'product', productId],
    queryFn: () => catalogApi.getProduct(productId!),
    enabled: productId !== null,
  })

  const close = () => {
    setOpen(false)
    setProductId(null)
    setSearch('')
  }

  return (
    <Dialog open={open} onOpenChange={(next) => (next ? setOpen(true) : close())}>
      <DialogTrigger render={<Button type="button" variant="outline" />}>
        <PlusIcon data-icon="inline-start" />
        {triggerLabel}
      </DialogTrigger>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{product.data ? product.data.name : 'Choose a product'}</DialogTitle>
          <DialogDescription>
            {productId ? 'Pick the variant to add.' : 'Search the catalog by name or SKU.'}
          </DialogDescription>
        </DialogHeader>

        {productId === null ? (
          <div className="flex flex-col gap-3">
            <div className="relative">
              <SearchIcon className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                autoFocus
                aria-label="Search products"
                placeholder="Search products…"
                className="pl-8"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
              />
            </div>
            <QueryState
              isLoading={products.isLoading}
              error={products.error ? 'Unable to load products.' : null}
              isEmpty={products.data?.items.length === 0}
              emptyTitle="No products found"
              emptyDescription="Try a different search."
            >
              <ul className="flex max-h-80 flex-col overflow-y-auto">
                {products.data?.items.map((item) => (
                  <li key={item.id}>
                    <button
                      type="button"
                      className="flex w-full items-center justify-between gap-3 rounded-md px-2 py-2 text-left hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
                      onClick={() => setProductId(item.id)}
                    >
                      <span className="min-w-0 truncate">{item.name}</span>
                      <span className="shrink-0 text-xs text-muted-foreground">
                        {item.variant_count} variant{item.variant_count === 1 ? '' : 's'}
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            </QueryState>
          </div>
        ) : (
          <div className="flex flex-col gap-3">
            <Button type="button" variant="ghost" size="sm" className="self-start" onClick={() => setProductId(null)}>
              <ChevronLeftIcon data-icon="inline-start" />
              Back to products
            </Button>
            <QueryState
              isLoading={product.isLoading}
              error={product.error ? 'Unable to load variants.' : null}
              isEmpty={product.data?.variants.length === 0}
              emptyTitle="No variants"
              emptyDescription="This product has no variants to add."
            >
              <ul className="flex max-h-80 flex-col overflow-y-auto">
                {product.data?.variants.map((variant) => (
                  <li key={variant.id}>
                    <button
                      type="button"
                      disabled={!variant.is_active}
                      className="flex w-full items-center justify-between gap-3 rounded-md px-2 py-2 text-left hover:bg-muted focus-visible:bg-muted focus-visible:outline-none disabled:opacity-50"
                      onClick={() => {
                        onSelect({
                          variant_id: variant.id,
                          sku: variant.sku,
                          label: `${product.data!.name} — ${variant.name}`,
                          price: { amount: variant.price.amount, currency: variant.price.currency },
                        })
                        close()
                      }}
                    >
                      <span className="flex min-w-0 flex-col">
                        <span className="truncate">{variant.name}</span>
                        <span className="text-xs text-muted-foreground">
                          {variant.sku}
                          {variant.is_active ? '' : ' · inactive'}
                        </span>
                      </span>
                      <MoneyText amount={variant.price.amount} currency={variant.price.currency} className="shrink-0" />
                    </button>
                  </li>
                ))}
              </ul>
            </QueryState>
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}
