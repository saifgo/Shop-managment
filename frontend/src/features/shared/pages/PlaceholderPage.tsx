import { PageHeader } from '@/components/PageHeader'

interface PlaceholderPageProps {
  title: string
  description: string
}

export function PlaceholderPage({ title, description }: PlaceholderPageProps) {
  return (
    <section className="flex flex-col gap-4">
      <PageHeader title={title} description={description} />
      <p className="text-sm text-muted-foreground">This area will be implemented in a future phase.</p>
    </section>
  )
}
