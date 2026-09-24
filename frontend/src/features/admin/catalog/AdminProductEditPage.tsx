import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { ResponsiveTable } from '@/components/ResponsiveTable'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Field, FieldGroup, FieldLabel, FieldTitle } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select'
import { Spinner } from '@/components/ui/spinner'
import { Textarea } from '@/components/ui/textarea'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { catalogApi, type ProductDetail } from '@/lib/api/catalog'
import { customersApi } from '@/lib/api/customers'
import { ProductPicturesCard } from './ProductPicturesCard'
import { AlertCircleIcon } from 'lucide-react'

const emptyForm = {
  name: '',
  slug: '',
  description: '',
  visibility: 'public',
  backorder_policy: 'allow',
  category_id: '',
  is_active: true,
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
      {product ? (
        <ProductEditor
          key={product.id}
          product={product}
          categories={categories?.items ?? []}
          productId={id}
        />
      ) : null}
    </QueryState>
  )
}

function ProductEditor({
  product,
  categories,
  productId,
}: {
  product?: ProductDetail
  categories: Array<{ id: string; name: string; children: typeof categories }>
  productId?: string
}) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const isNew = !productId

  const [form, setForm] = useState(() =>
    product
      ? {
          name: product.name,
          slug: product.slug,
          description: product.description ?? '',
          visibility: product.visibility,
          backorder_policy: product.backorder_policy,
          category_id: product.category_id ?? '',
          is_active: product.is_active,
        }
      : emptyForm,
  )

  const [variantForm, setVariantForm] = useState({
    sku: '',
    name: '',
    base_price_amount: '',
    base_price_currency: 'TND',
    attributes: 'size=',
  })

  const [overrideCustomerId, setOverrideCustomerId] = useState('')
  const [overrideVariantId, setOverrideVariantId] = useState('')
  const [overrideAmount, setOverrideAmount] = useState('')

  const { data: customers } = useQuery({
    queryKey: ['admin', 'customers-select'],
    queryFn: () => customersApi.list({ per_page: 100 }),
  })

  const saveProduct = useMutation({
    mutationFn: async () => {
      const payload = {
        name: form.name,
        slug: form.slug,
        description: form.description || null,
        visibility: form.visibility,
        backorder_policy: form.backorder_policy,
        category_id: form.category_id || null,
        is_active: form.is_active,
      }

      if (isNew) {
        return catalogApi.createProduct(payload)
      }

      return catalogApi.updateProduct(productId!, payload)
    },
    onSuccess: (saved) => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'products'] })
      if (isNew) {
        navigate(`/admin/catalog/${saved.id}`)
      }
    },
  })

  const addVariant = useMutation({
    mutationFn: async () => {
      const attributes = Object.fromEntries(
        variantForm.attributes
          .split(',')
          .map((pair) => pair.trim())
          .filter(Boolean)
          .map((pair) => {
            const [key, value] = pair.split('=')
            return [key, value ?? '']
          }),
      )

      return catalogApi.createVariant(productId!, {
        sku: variantForm.sku,
        name: variantForm.name,
        base_price_amount: variantForm.base_price_amount,
        base_price_currency: variantForm.base_price_currency,
        attributes,
      })
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'product', productId] })
      setVariantForm({ sku: '', name: '', base_price_amount: '', base_price_currency: 'TND', attributes: 'size=' })
    },
  })

  const addOverride = useMutation({
    mutationFn: () =>
      customersApi.createPriceOverride(overrideCustomerId, {
        variant_id: overrideVariantId,
        price_amount: overrideAmount,
        price_currency: 'TND',
      }),
    onSuccess: () => {
      setOverrideAmount('')
    },
  })

  const flatCategories = flattenCategories(categories)

  return (
    <section className="flex flex-col gap-6">
      <Link to="/admin/catalog" className="text-sm text-muted-foreground">
        Catalog
      </Link>
      <PageHeader title={isNew ? 'New product' : product?.name ?? 'Product'} />

      {saveProduct.isError ? (
        <Alert variant="destructive">
          <AlertCircleIcon />
          <AlertTitle>Unable to save</AlertTitle>
          <AlertDescription>The product could not be saved. Check the fields and try again.</AlertDescription>
        </Alert>
      ) : null}

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Product</CardTitle>
          </CardHeader>
          <CardContent>
            <FieldGroup>
              <Field>
                <FieldLabel htmlFor="product-name">Name</FieldLabel>
                <Input
                  id="product-name"
                  value={form.name}
                  onChange={(e) => setForm({ ...form, name: e.target.value })}
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="product-slug">Slug</FieldLabel>
                <Input
                  id="product-slug"
                  value={form.slug}
                  onChange={(e) => setForm({ ...form, slug: e.target.value })}
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="product-description">Description</FieldLabel>
                <Textarea
                  id="product-description"
                  rows={4}
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
                  <NativeSelectOption value="">None</NativeSelectOption>
                  {flatCategories.map((category) => (
                    <NativeSelectOption key={category.id} value={category.id}>
                      {category.label}
                    </NativeSelectOption>
                  ))}
                </NativeSelect>
              </Field>
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
              </Field>
              <Field>
                <FieldTitle id="backorder-label">Backorder policy</FieldTitle>
                <ToggleGroup
                  variant="outline"
                  spacing={0}
                  aria-labelledby="backorder-label"
                  value={[form.backorder_policy]}
                  onValueChange={(next) => {
                    if (next[0]) setForm({ ...form, backorder_policy: next[0] })
                  }}
                >
                  <ToggleGroupItem value="allow">Allow backorders</ToggleGroupItem>
                  <ToggleGroupItem value="deny">Deny backorders</ToggleGroupItem>
                </ToggleGroup>
              </Field>
              {!isNew ? (
                <Field orientation="horizontal">
                  <Checkbox
                    id="product-active"
                    checked={form.is_active}
                    onCheckedChange={(checked) => setForm({ ...form, is_active: checked })}
                  />
                  <FieldLabel htmlFor="product-active">Active</FieldLabel>
                </Field>
              ) : null}
            </FieldGroup>
          </CardContent>
          <CardFooter>
            <Button onClick={() => saveProduct.mutate()} disabled={saveProduct.isPending}>
              {saveProduct.isPending ? <Spinner data-icon="inline-start" /> : null}
              {saveProduct.isPending ? 'Saving…' : 'Save product'}
            </Button>
          </CardFooter>
        </Card>

        {isNew ? (
          <Card>
            <CardHeader>
              <CardTitle>Pictures</CardTitle>
              <CardDescription>Save the product first, then you can add pictures.</CardDescription>
            </CardHeader>
          </Card>
        ) : null}

        {!isNew && product ? (
          <div className="flex flex-col gap-6">
            <ProductPicturesCard product={product} />

            <Card>
              <CardHeader>
                <CardTitle>Variants</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-5">
                <ResponsiveTable
                  data={product.variants}
                  getRowKey={(variant) => variant.id}
                  columns={[
                    { key: 'sku', header: 'SKU', primary: true, cell: (variant) => variant.sku },
                    { key: 'name', header: 'Name', cell: (variant) => variant.name },
                    {
                      key: 'price',
                      header: 'Base price',
                      cell: (variant) => (
                        <MoneyText amount={variant.base_price.amount} currency={variant.base_price.currency} />
                      ),
                    },
                  ]}
                />

                <FieldGroup>
                  <Field>
                    <FieldLabel htmlFor="variant-sku">SKU</FieldLabel>
                    <Input
                      id="variant-sku"
                      value={variantForm.sku}
                      onChange={(e) => setVariantForm({ ...variantForm, sku: e.target.value })}
                    />
                  </Field>
                  <Field>
                    <FieldLabel htmlFor="variant-name">Name</FieldLabel>
                    <Input
                      id="variant-name"
                      value={variantForm.name}
                      onChange={(e) => setVariantForm({ ...variantForm, name: e.target.value })}
                    />
                  </Field>
                  <Field>
                    <FieldLabel htmlFor="variant-price">Base price</FieldLabel>
                    <Input
                      id="variant-price"
                      value={variantForm.base_price_amount}
                      onChange={(e) => setVariantForm({ ...variantForm, base_price_amount: e.target.value })}
                    />
                  </Field>
                  <Field>
                    <FieldLabel htmlFor="variant-attributes">Attributes (key=value, comma separated)</FieldLabel>
                    <Input
                      id="variant-attributes"
                      value={variantForm.attributes}
                      onChange={(e) => setVariantForm({ ...variantForm, attributes: e.target.value })}
                    />
                  </Field>
                </FieldGroup>
              </CardContent>
              <CardFooter>
                <Button variant="secondary" onClick={() => addVariant.mutate()} disabled={addVariant.isPending}>
                  {addVariant.isPending ? <Spinner data-icon="inline-start" /> : null}
                  Add variant
                </Button>
              </CardFooter>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Customer price override</CardTitle>
              </CardHeader>
              <CardContent>
                <FieldGroup>
                  <Field>
                    <FieldLabel htmlFor="override-customer">Customer</FieldLabel>
                    <NativeSelect
                      id="override-customer"
                      className="w-full"
                      value={overrideCustomerId}
                      onChange={(e) => setOverrideCustomerId(e.target.value)}
                    >
                      <NativeSelectOption value="">Select customer</NativeSelectOption>
                      {customers?.items.map((customer) => (
                        <NativeSelectOption key={customer.id} value={customer.id}>
                          {customer.display_name}
                        </NativeSelectOption>
                      ))}
                    </NativeSelect>
                  </Field>
                  <Field>
                    <FieldLabel htmlFor="override-variant">Variant</FieldLabel>
                    <NativeSelect
                      id="override-variant"
                      className="w-full"
                      value={overrideVariantId}
                      onChange={(e) => setOverrideVariantId(e.target.value)}
                    >
                      <NativeSelectOption value="">Select variant</NativeSelectOption>
                      {product.variants.map((variant) => (
                        <NativeSelectOption key={variant.id} value={variant.id}>
                          {variant.sku} — {variant.name}
                        </NativeSelectOption>
                      ))}
                    </NativeSelect>
                  </Field>
                  <Field>
                    <FieldLabel htmlFor="override-amount">Override amount (TND)</FieldLabel>
                    <Input
                      id="override-amount"
                      value={overrideAmount}
                      onChange={(e) => setOverrideAmount(e.target.value)}
                    />
                  </Field>
                </FieldGroup>
              </CardContent>
              <CardFooter>
                <Button variant="secondary" onClick={() => addOverride.mutate()} disabled={addOverride.isPending}>
                  {addOverride.isPending ? <Spinner data-icon="inline-start" /> : null}
                  Save override
                </Button>
              </CardFooter>
            </Card>
          </div>
        ) : null}
      </div>
    </section>
  )
}

function flattenCategories(
  nodes: Array<{ id: string; name: string; children: typeof nodes }>,
  prefix = '',
): Array<{ id: string; label: string }> {
  return nodes.flatMap((node) => [
    { id: node.id, label: `${prefix}${node.name}` },
    ...flattenCategories(node.children, `${prefix}${node.name} / `),
  ])
}
