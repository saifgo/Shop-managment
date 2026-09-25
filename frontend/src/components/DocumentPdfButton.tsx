import type { ComponentProps, ReactNode } from 'react'
import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import { documentsApi } from '@/lib/api/documents'

interface DocumentPdfButtonProps extends Pick<ComponentProps<typeof Button>, 'variant' | 'size' | 'className'> {
  document: { id: string; document_number?: string | null }
  children: ReactNode
  'aria-label'?: string
}

/**
 * Downloads a document's PDF. A plain link cannot send the bearer token the download
 * endpoint requires, so the file is fetched and saved from script.
 */
export function DocumentPdfButton({ document, children, ...props }: DocumentPdfButtonProps) {
  const download = useMutation({
    mutationFn: () => documentsApi.downloadPdf(document),
    onError: (err) => toast.error(err.message),
  })

  return (
    <Button {...props} disabled={download.isPending} onClick={() => download.mutate()}>
      {download.isPending ? <Spinner /> : children}
    </Button>
  )
}
