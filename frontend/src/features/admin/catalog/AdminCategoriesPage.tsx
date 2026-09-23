import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { QueryState } from '@/components/QueryState'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select'
import { Spinner } from '@/components/ui/spinner'
import { catalogApi } from '@/lib/api/catalog'

export function AdminCategoriesPage() {
  const queryClient = useQueryClient()
  const { data, isLoading, error } = useQuery({
    queryKey: ['admin', 'categories'],
    queryFn: () => catalogApi.listCategories(),
  })

  const [form, setForm] = useState({ name: '', slug: '', parent_id: '' })

  const createCategory = useMutation({
    mutationFn: () =>
      catalogApi.createCategory({
        name: form.name,
        slug: form.slug,
        parent_id: form.parent_id || null,
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'categories'] })
      setForm({ name: '', slug: '', parent_id: '' })
    },
  })

  const flat = flattenCategories(data?.items ?? [])

  return (
    <section className="flex flex-col gap-6">
      <Link to="/admin/catalog" className="text-sm text-muted-foreground">
        Catalog
      </Link>
      <PageHeader title="Categories" />

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Tree</CardTitle>
          </CardHeader>
          <CardContent>
            <QueryState
              isLoading={isLoading}
              error={error ? 'Unable to load categories.' : null}
              isEmpty={!isLoading && (data?.items.length ?? 0) === 0}
              emptyTitle="No categories yet"
              emptyDescription="Create a category to organize the catalog."
            >
              <CategoryTree nodes={data?.items ?? []} />
            </QueryState>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>New category</CardTitle>
          </CardHeader>
          <CardContent>
            <FieldGroup>
              <Field>
                <FieldLabel htmlFor="category-name">Name</FieldLabel>
                <Input
                  id="category-name"
                  value={form.name}
                  onChange={(e) => setForm({ ...form, name: e.target.value })}
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="category-slug">Slug</FieldLabel>
                <Input
                  id="category-slug"
                  value={form.slug}
                  onChange={(e) => setForm({ ...form, slug: e.target.value })}
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="category-parent">Parent</FieldLabel>
                <NativeSelect
                  id="category-parent"
                  className="w-full"
                  value={form.parent_id}
                  onChange={(e) => setForm({ ...form, parent_id: e.target.value })}
                >
                  <NativeSelectOption value="">Root</NativeSelectOption>
                  {flat.map((category) => (
                    <NativeSelectOption key={category.id} value={category.id}>
                      {category.label}
                    </NativeSelectOption>
                  ))}
                </NativeSelect>
              </Field>
            </FieldGroup>
          </CardContent>
          <CardFooter>
            <Button onClick={() => createCategory.mutate()} disabled={createCategory.isPending}>
              {createCategory.isPending ? <Spinner data-icon="inline-start" /> : null}
              Create category
            </Button>
          </CardFooter>
        </Card>
      </div>
    </section>
  )
}

function CategoryTree({
  nodes,
}: {
  nodes: Array<{ id: string; name: string; slug: string; children: typeof nodes }>
}) {
  return (
    <ul className="flex flex-col gap-2">
      {nodes.map((node) => (
        <li key={node.id} className="flex flex-col gap-2">
          <div className="flex items-baseline gap-2">
            <span className="font-medium">{node.name}</span>
            <span className="text-sm text-muted-foreground">({node.slug})</span>
          </div>
          {node.children.length ? (
            <div className="pl-4">
              <CategoryTree nodes={node.children} />
            </div>
          ) : null}
        </li>
      ))}
    </ul>
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
