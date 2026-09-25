import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import { DownloadIcon, ExternalLinkIcon, FileXIcon } from 'lucide-react'
import { MoneyText } from '@/components/MoneyText'
import { Button } from '@/components/ui/button'
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty'
import { Skeleton } from '@/components/ui/skeleton'
import { sharedDocumentsApi } from '@/lib/api/documents'

/** A4 at 96 CSS pixels per inch, the size the preview HTML is laid out at. */
const PAGE_WIDTH = 794
const PAGE_HEIGHT = 1123

/** Public page behind a document's share link: preview plus download, no sign-in. */
export function SharedDocumentPage() {
  const { token = '' } = useParams()
  const { data, isLoading, error } = useQuery({
    queryKey: ['shared-document', token],
    queryFn: () => sharedDocumentsApi.get(token),
    retry: false,
  })

  useEffect(() => {
    if (!data) return
    const previous = window.document.title
    window.document.title = [`${data.title} N° ${data.document_number ?? ''}`, data.company_name].filter(Boolean).join(' · ')
    return () => {
      window.document.title = previous
    }
  }, [data])

  if (error) {
    return (
      <div className="flex min-h-svh items-center justify-center bg-muted/60 px-4">
        <Empty>
          <EmptyHeader>
            <EmptyMedia variant="icon">
              <FileXIcon />
            </EmptyMedia>
            <EmptyTitle>This link no longer works</EmptyTitle>
            <EmptyDescription>
              It may have been revoked or mistyped. Ask the sender for a new link.
            </EmptyDescription>
          </EmptyHeader>
        </Empty>
      </div>
    )
  }

  const heading = data ? `${data.title} N° ${data.document_number ?? ''}` : null

  return (
    <div className="min-h-svh bg-muted/60">
      <header className="sticky top-0 z-10 border-b bg-background/95 backdrop-blur supports-backdrop-filter:bg-background/80">
        <div className="mx-auto flex max-w-4xl flex-wrap items-center gap-x-4 gap-y-3 px-4 py-3">
          <div className="flex min-w-0 basis-full flex-col sm:basis-auto sm:flex-1">
            {isLoading || !data ? (
              <>
                <Skeleton className="h-3.5 w-28" />
                <Skeleton className="mt-1.5 h-5 w-44" />
              </>
            ) : (
              <>
                {data.company_name ? <span className="truncate text-xs text-muted-foreground">{data.company_name}</span> : null}
                <h1 className="truncate font-heading text-base font-medium">{heading}</h1>
              </>
            )}
          </div>
          <div className="flex w-full gap-2 sm:w-auto">
            <Button
              variant="outline"
              className="flex-1 sm:flex-none"
              nativeButton={false}
              render={<a href={sharedDocumentsApi.pdfUrl(token)} target="_blank" rel="noreferrer" />}
            >
              <ExternalLinkIcon data-icon="inline-start" />
              Open PDF
            </Button>
            <Button
              className="flex-1 sm:flex-none"
              nativeButton={false}
              render={<a href={sharedDocumentsApi.pdfUrl(token, true)} download={data?.filename} />}
            >
              <DownloadIcon data-icon="inline-start" />
              Download PDF
            </Button>
          </div>
        </div>
      </header>

      <main className="mx-auto flex max-w-4xl flex-col gap-4 px-4 py-6">
        {data ? (
          <dl className="grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:flex sm:flex-wrap">
            <Fact label="Billed to" value={data.customer_display_name} />
            {data.issued_at ? <Fact label="Date" value={new Date(data.issued_at).toLocaleDateString()} /> : null}
            {data.due_date ? <Fact label="Due" value={new Date(data.due_date).toLocaleDateString()} /> : null}
            <Fact
              label="Total"
              value={<MoneyText amount={data.grand_total.amount} currency={data.grand_total.currency} />}
            />
          </dl>
        ) : null}

        {isLoading ? (
          <Skeleton className="aspect-[794/1123] w-full" />
        ) : (
          <A4Preview src={sharedDocumentsApi.previewUrl(token)} title={heading ?? 'Document'} />
        )}
      </main>
    </div>
  )
}

function Fact({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="flex flex-col">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="font-medium">{value}</dd>
    </div>
  )
}

/** Shows the A4 preview page scaled down to the available width, like a PDF viewer would. */
function A4Preview({ src, title }: { src: string; title: string }) {
  const container = useRef<HTMLDivElement>(null)
  const [scale, setScale] = useState(1)

  useLayoutEffect(() => {
    const element = container.current
    if (!element) return
    const update = () => setScale(Math.min(1, element.clientWidth / PAGE_WIDTH))
    update()
    const observer = new ResizeObserver(update)
    observer.observe(element)
    return () => observer.disconnect()
  }, [])

  return (
    <div ref={container} className="w-full">
      <div
        className="mx-auto overflow-hidden rounded-sm bg-white shadow-sm ring-1 ring-foreground/10"
        style={{ width: PAGE_WIDTH * scale, height: PAGE_HEIGHT * scale }}
      >
        {/* The preview response's CSP already blocks scripts and outside resources. A sandbox
            attribute would be redundant, and some embedded browsers refuse sandboxed frames. */}
        <iframe
          src={src}
          title={title}
          className="origin-top-left border-0 bg-white"
          style={{ width: PAGE_WIDTH, height: PAGE_HEIGHT, transform: `scale(${scale})` }}
        />
      </div>
    </div>
  )
}
