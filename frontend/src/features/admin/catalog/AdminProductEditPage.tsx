import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { AlertCircleIcon, ArrowLeftIcon, PencilIcon, PlusIcon } from 'lucide-react'
import { toast } from 'sonner'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { StockBadge } from '@/components/StockBadge'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardAction, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldDescription, FieldGroup, FieldLabel, FieldTitle } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { InputGroup, InputGroupAddon, InputGroupInput, InputGroupText } from '@/components/ui/input-group'
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select'
import { Spinner } from '@/components/ui/spinner'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { CustomerSelect } from '@/features/admin/components/CustomerSelect'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { catalogApi, flattenCategories, type CategoryNode, type ProductDetail } from '@/lib/api/catalog'
import { customersApi } from '@/lib/api/customers'
import { PERMISSIONS } from '@/lib/auth/permissions'
import { formatQuantity, slugify } from '@/lib/format'
import { ProductPicturesCard } from './ProductPicturesCard'
import { VariantDialog } from './VariantDialog'

const VISIBILITY_HELP: Record<string, string> = {
  public: 'Shown in the customer store.',
  hidden: 'Not listed in the store; staff can still sell it.',
  internal: 'Staff only — e.g. samples or production parts.',
}

export function AdminProductEditPage() {
  const { id } = useParams()
  // Route `catalog/new` has no `:id` param, so `id` is undefined there.
  const isNew = !id || id === 'new'

  const { data: product, isLoading, error } = useQuery({
    queryKey: ['admin', 'product', id],
    queryFn: () => catalogApi.getProduct(id!),
    enabled: !isNew && Boolean(id),
  })

  const { data: categories } = useQuery({
    queryKey: ['admin', 'categories'],
    queryFn: () => catalogApi.listCategories(),
  })

  if (isNew) {
    return <ProductEditor categories={categories?.items ?? []} />
  }

  return (
    <QueryState isLoading={isLoading} error={error || (!isLoading && !product) ? 'Product not found.' : null}>
      {product ? <ProductEditor key={product.id} product={product} categories={categories?.items ?? []} /> : null}
    </QueryState>
  )
}

function formFromProduct(product?: ProductDetail) {
  return {
    name: product?.name ?? '',
    slug: product?.slug ?? '',
    description: product?.description ?? '',
    visibility: product?.visibility ?? 'public',
    backorder_policy: product?.backorder_policy ?? 'allow',
    category_id: product?.category_id ?? '',
    is_active: product?.is_active ?? true,
  }
}

function ProductEditor({ product, categories }: { product?: ProductDetail; categories: CategoryNode[] }) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { can } = useAuth()
  const canManage = can(PERMISSIONS.catalogManage)
  const isNew = !product

  const [form, setForm] = useState(() => formFromProduct(product))
  // Keep the slug following the name until someone edits the slug by hand.
  const [slugTouched, setSlugTouched] = useState(() => Boolean(product))
  const saved = formFromProduct(product)
  const isDirty = JSON.stringify(form) !== JSON.stringify(saved)

  const saveProduct = useMutation({
    mutationFn: () => {
      const payload = {
        name: form.name.trim(),
        slug: form.slug.trim() || slugify(form.name),
        description: form.description.trim() || null,
        visibility: form.visibility,
        backorder_policy: form.backorder_policy,
        category_id: form.category_id || null,
        is_active: form.is_active,
      }
      return product ? catalogApi.updateProduct(product.id, payload) : catalogApi.createProduct(payload)
    },
    onSuccess: (result) => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'products'] })
      if (isNew) {
        toast.success('Product created. Add variants and pictures next.')
        navigate(`/admin/catalog/${result.id}`)
      } else {
        queryClient.setQueryData(['admin', 'product', result.id], result)
        toast.success('Product saved.')
      }
    },
  })

  const canSave = form.name.trim() !== '' && (form.slug.trim() !== '' || slugify(form.name) !== '')
  const activeVariants = product?.variants.filter((variant) => variant.is_active).length ?? 0

  return (
    <section className="flex flex-col gap-6">
      <Link
        to="/admin/catalog"
        className="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeftIcon className="size-4" />
        Products
      </Link>

      <PageHeader
        title={isNew ? 'New product' : product.name}
        description={
          isNew
            ? 'Start with the basics. Variants, prices and pictures come right after saving.'
            : `${activeVariants} active ${activeVariants === 1 ? 'variant' : 'variants'} · ${formatQuantity(product.available_quantity)} available to sell`
        }
        action={
          canManage ? (
            <>
              {!isNew ? (
                <Badge variant={form.is_active ? 'secondary' : 'outline'}>{form.is_active ? 'Active' : 'Archived'}</Badge>
              ) : null}
              <Button onClick={() => saveProduct.mutate()} disabled={saveProduct.isPending || !canSave || (!isNew && !isDirty)}>
                {saveProduct.isPending ? <Spinner data-icon="inline-start" /> : null}
                {isNew ? 'Create product' : isDirty ? 'Save changes' : 'Saved'}
              </Button>
            </>
          ) : null
        }
      />

      {saveProduct.isError ? (
        <Alert variant="destructive">
          <AlertCircleIcon />
          <AlertTitle>Unable to save</AlertTitle>
          <AlertDescription>{saveProduct.error.message}</AlertDescription>
        </Alert>
      ) : null}

      {!isNew && product.variants.length === 0 ? (
        <Alert>
          <AlertCircleIcon />
          <AlertTitle>This product can’t be sold yet</AlertTitle>
          <AlertDescription>Add at least one variant with a price so customers and staff can order it.</AlertDescription>
        </Alert>
      ) : null}

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div className="flex min-w-0 flex-col gap-6">
          <Card>
            <CardHeader>
              <CardTitle>Details</CardTitle>
            </CardHeader>
            <CardContent>
              <fieldset disabled={!canManage} className="contents">
                <FieldGroup>
                  <Field>
                    <FieldLabel htmlFor="product-name">Name</FieldLabel>
                    <Input
                      id="product-name"
                      placeholder="Berber tagine"
                      value={form.name}
                      onChange={(e) =>
                        setForm({
                          ...form,
                          name: e.target.value,
                          slug: slugTouched ? form.slug : slugify(e.target.value),
                        })
                      }
                    />
                  </Field>
                  <Field>
                    <FieldLabel htmlFor="product-slug">URL name</FieldLabel>
                    <InputGroup>
                      <InputGroupAddon>
                        <InputGroupText>/catalog/</InputGroupText>
                      </InputGroupAddon>
                      <InputGroupInput
                        id="product-slug"
                        value={form.slug}
                        onChange={(e) => {
                          setSlugTouched(true)
                          setForm({ ...form, slug: slugify(e.target.value) })
                        }}
                      />
                    </InputGroup>
                    <FieldDescription>Filled in from the name. Must be unique.</FieldDescription>
                  </Field>
                  <Field>
                    <FieldLabel htmlFor="product-description">Description</FieldLabel>
                    <Textarea
                      id="product-description"
                      rows={4}
                      placeholder="Clay, finish, dimensions, care instructions…"
                      value={form.description}
                      onChange={(e) => setForm({ ...form, description: e.target.value })}
                    />
                  </Field>
                  <Field>
                    <FieldLabel htmlFor="product-category">Category</FieldLabel>
                    <NativeSelect
                      id="product-category"
                      className="w-full"
                      value={form.category_id}
                      onChange={(e) => setForm({ ...form, category_id: e.target.value })}
                    >
                      <NativeSelectOption value="">Uncategorized</NativeSelectOption>
                      {flattenCategories(categories).map((category) => (
                        <NativeSelectOption key={category.id} value={category.id}>
                          {category.label}
                        </NativeSelectOption>
                      ))}
                    </NativeSelect>
                  </Field>
                </FieldGroup>
              </fieldset>
            </CardContent>
          </Card>

          {product ? <VariantsCard product={product} canManage={canManage} /> : null}
          {product && canManage && product.variants.length > 0 ? <CustomerPriceCard product={product} /> : null}
        </div>

        <div className="flex flex-col gap-6">
          <Card>
            <CardHeader>
              <CardTitle>Sales settings</CardTitle>
            </CardHeader>
            <CardContent>
              <fieldset disabled={!canManage} className="contents">
                <FieldGroup>
                  {!isNew ? (
                    <Field orientation="horizontal">
                      <Switch
                        id="product-active"
                        checked={form.is_active}
                        onCheckedChange={(checked) => setForm({ ...form, is_active: checked })}
                      />
                      <FieldLabel htmlFor="product-active" className="flex flex-col items-start gap-0.5">
                        <span>Active</span>
                        <span className="text-xs font-normal text-muted-foreground">
                          Archived products can’t be ordered.
                        </span>
                      </FieldLabel>
                    </Field>
                  ) : null}
                  <Field>
                    <FieldTitle id="visibility-label">Visibility</FieldTitle>
                    <ToggleGroup
                      variant="outline"
                      spacing={0}
                      aria-labelledby="visibility-label"
                      value={[form.visibility]}
                      onValueChange={(next) => {
                        if (next[0]) setForm({ ...form, visibility: next[0] })
                      }}
                    >
                      <ToggleGroupItem value="public">Public</ToggleGroupItem>
                      <ToggleGroupItem value="hidden">Hidden</ToggleGroupItem>
                      <ToggleGroupItem value="internal">Internal</ToggleGroupItem>
                    </ToggleGroup>
                    <FieldDescription>{VISIBILITY_HELP[form.visibility]}</FieldDescription>
                  </Field>
                  <Field>
                    <FieldTitle id="backorder-label">When out of stock</FieldTitle>
                    <ToggleGroup
                      variant="outline"
                      spacing={0}
                      aria-labelledby="backorder-label"
                      value={[form.backorder_policy]}
                      onValueChange={(next) => {
                        if (next[0]) setForm({ ...form, backorder_policy: next[0] })
                      }}
                    >
                      <ToggleGroupItem value="allow">Make to order</ToggleGroupItem>
                      <ToggleGroupItem value="deny">Stop selling</ToggleGroupItem>
                    </ToggleGroup>
                    <FieldDescription>
                      {form.backorder_policy === 'allow'
                        ? 'Customers can still order; the shortfall becomes production demand.'
                        : 'Orders are limited to the quantity in stock.'}
                    </FieldDescription>
                  </Field>
                </FieldGroup>
              </fieldset>
            </CardContent>
          </Card>

          {product ? (
            <ProductPicturesCard product={product} />
          ) : (
            <Card>
              <CardHeader>
                <CardTitle>Pictures</CardTitle>
                <CardDescription>Create the product first, then add pictures.</CardDescription>
              </CardHeader>
            </Card>
          )}
        </div>
      </div>
    </section>
  )
}

function VariantsCard({ product, canManage }: { product: ProductDetail; canManage: boolean }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Variants & prices</CardTitle>
        <CardDescription>Each size or finish you sell, with its own SKU, price and stock.</CardDescription>
        {canManage ? (
          <CardAction>
            <VariantDialog
              productId={product.id}
              productName={product.name}
              trigger={
                <Button size="sm">
                  <PlusIcon data-icon="inline-start" />
                  Add variant
                </Button>
              }
            />
          </CardAction>
        ) : null}
      </CardHeader>
      <CardContent>
        {product.variants.length === 0 ? (
          <p className="text-sm text-muted-foreground">No variants yet.</p>
        ) : (
          <ResponsiveTable
            data={product.variants}
            getRowKey={(variant) => variant.id}
            columns={[
              {
                key: 'variant',
                header: 'Variant',
                primary: true,
                cell: (variant) => (
                  <span className="flex flex-col gap-0.5">
                    <span className={variant.is_active ? 'font-medium' : 'font-medium text-muted-foreground line-through'}>
                      {variant.name}
                    </span>
                    <span className="text-xs text-muted-foreground">
                      {variant.sku}
                      {Object.keys(variant.attributes).length > 0
                        ? ` · ${Object.entries(variant.attributes)
                            .map(([key, value]) => `${key}: ${value}`)
                            .join(', ')}`
                        : ''}
                    </span>
                  </span>
                ),
              },
              {
                key: 'price',
                header: 'Price',
                mobile: true,
                cell: (variant) => <MoneyText amount={variant.base_price.amount} currency={variant.base_price.currency} />,
              },
              {
                key: 'stock',
                header: 'Stock',
                mobile: true,
                cell: (variant) =>
                  variant.is_active ? (
                    <span className="flex flex-col gap-0.5">
                      <StockBadge status={variant.stock_status} quantity={variant.available_quantity} showQuantity />
                      {variant.on_hand !== undefined && variant.on_hand !== variant.available_quantity ? (
                        <span className="text-xs text-muted-foreground">{formatQuantity(variant.on_hand)} on hand</span>
                      ) : null}
                    </span>
                  ) : (
                    <Badge variant="outline">Not for sale</Badge>
                  ),
              },
            ]}
            rowAction={
              canManage
                ? (variant) => (
                    <VariantDialog
                      productId={product.id}
                      productName={product.name}
                      variant={variant}
                      trigger={
                        <Button variant="ghost" size="icon-sm" aria-label={`Edit ${variant.sku}`}>
                          <PencilIcon />
                        </Button>
                      }
                    />
                  )
                : undefined
            }
          />
        )}
      </CardContent>
    </Card>
  )
}

function CustomerPriceCard({ product }: { product: ProductDetail }) {
  const [customerId, setCustomerId] = useState('')
  const [variantId, setVariantId] = useState('')
  const [amount, setAmount] = useState('')
  const variant = product.variants.find((item) => item.id === variantId)
  const amountValid = /^\d+([.,]\d{1,4})?$/.test(amount.trim())

  const save = useMutation({
    mutationFn: () =>
      customersApi.createPriceOverride(customerId, {
        variant_id: variantId,
        price_amount: amount.trim().replace(',', '.'),
        price_currency: variant?.base_price.currency ?? 'TND',
      }),
    onSuccess: () => {
      toast.success('Customer price saved. It applies to their next cart and orders.')
      setAmount('')
    },
  })

  return (
    <Card>
      <CardHeader>
        <CardTitle>Customer-specific price</CardTitle>
        <CardDescription>Give a wholesale or negotiated price to one customer. Other customers keep the base price.</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {save.isError ? (
          <Alert variant="destructive">
            <AlertCircleIcon />
            <AlertDescription>{save.error.message}</AlertDescription>
          </Alert>
        ) : null}
        <FieldGroup>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field>
              <FieldLabel htmlFor="override-customer">Customer</FieldLabel>
              <CustomerSelect id="override-customer" value={customerId} onChange={setCustomerId} />
            </Field>
            <Field>
              <FieldLabel htmlFor="override-variant">Variant</FieldLabel>
              <NativeSelect
                id="override-variant"
                className="w-full"
                value={variantId}
                onChange={(e) => setVariantId(e.target.value)}
              >
                <NativeSelectOption value="">Select variant</NativeSelectOption>
                {product.variants.map((item) => (
                  <NativeSelectOption key={item.id} value={item.id}>
                    {item.name} ({item.sku})
                  </NativeSelectOption>
                ))}
              </NativeSelect>
            </Field>
          </div>
          <Field>
            <FieldLabel htmlFor="override-amount">Their price</FieldLabel>
            <InputGroup className="sm:max-w-56">
              <InputGroupInput
                id="override-amount"
                inputMode="decimal"
                placeholder="0.00"
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
              />
              <InputGroupAddon align="inline-end">
                <InputGroupText>{variant?.base_price.currency ?? 'TND'}</InputGroupText>
              </InputGroupAddon>
            </InputGroup>
            {variant ? (
              <FieldDescription>
                Base price: <MoneyText amount={variant.base_price.amount} currency={variant.base_price.currency} />
              </FieldDescription>
            ) : null}
          </Field>
        </FieldGroup>
      </CardContent>
      <CardFooter>
        <Button
          variant="secondary"
          onClick={() => save.mutate()}
          disabled={save.isPending || !customerId || !variantId || !amountValid}
        >
          {save.isPending ? <Spinner data-icon="inline-start" /> : null}
          Save customer price
        </Button>
      </CardFooter>
    </Card>
  )
}
