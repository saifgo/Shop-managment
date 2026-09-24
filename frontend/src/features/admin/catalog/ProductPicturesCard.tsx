import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { AlertCircleIcon, ImagePlusIcon, StarIcon, Trash2Icon } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Spinner } from '@/components/ui/spinner'
import { catalogApi, type ProductDetail } from '@/lib/api/catalog'
import { ApiError, apiUrl } from '@/lib/api/client'

const ACCEPTED_TYPES = 'image/jpeg,image/png,image/webp,image/gif'
const MAX_BYTES = 5 * 1024 * 1024

export function ProductPicturesCard({ product }: { product: ProductDetail }) {
  const queryClient = useQueryClient()
  const fileInput = useRef<HTMLInputElement>(null)
  const [localError, setLocalError] = useState<string | null>(null)

  const onSaved = (saved: ProductDetail) => {
    queryClient.setQueryData(['admin', 'product', product.id], saved)
    void queryClient.invalidateQueries({ queryKey: ['admin', 'products'] })
  }

  const upload = useMutation({
    mutationFn: async (files: File[]) => {
      let saved = product
      for (const file of files) {
        saved = await catalogApi.uploadProductMedia(product.id, file)
      }
      return saved
    },
    onSuccess: onSaved,
    // Earlier files in a multi-file upload may have been saved before the failure.
    onSettled: () => void queryClient.invalidateQueries({ queryKey: ['admin', 'product', product.id] }),
  })

  const remove = useMutation({
    mutationFn: (mediaId: string) => catalogApi.deleteProductMedia(product.id, mediaId),
    onSuccess: onSaved,
  })

  const makePrimary = useMutation({
    mutationFn: (mediaId: string) => catalogApi.setPrimaryProductMedia(product.id, mediaId),
    onSuccess: onSaved,
  })

  const handleFiles = (list: FileList | null) => {
    setLocalError(null)
    upload.reset()
    const files = Array.from(list ?? [])
    if (files.length === 0) return

    const tooBig = files.find((file) => file.size > MAX_BYTES)
    if (tooBig) {
      setLocalError(`"${tooBig.name}" is larger than 5 MB.`)
      return
    }

    upload.mutate(files)
  }

  const failed = upload.error ?? remove.error ?? makePrimary.error
  const errorMessage = localError ?? (failed ? errorText(failed) : null)
  const busy = upload.isPending || remove.isPending || makePrimary.isPending

  return (
    <Card>
      <CardHeader>
        <CardTitle>Pictures</CardTitle>
        <CardDescription>JPEG, PNG, WebP or GIF, up to 5 MB. The main picture is shown in the catalog.</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {errorMessage ? (
          <Alert variant="destructive">
            <AlertCircleIcon />
            <AlertTitle>Picture not saved</AlertTitle>
            <AlertDescription>{errorMessage}</AlertDescription>
          </Alert>
        ) : null}

        {product.media.length > 0 ? (
          <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            {product.media.map((media) => (
              <li key={media.id} className="flex flex-col gap-2">
                <div className="relative aspect-square overflow-hidden rounded-lg border bg-muted">
                  <img
                    src={apiUrl(media.url)}
                    alt={media.alt_text ?? product.name}
                    className="size-full object-cover"
                    loading="lazy"
                  />
                  {media.is_primary ? (
                    <Badge className="absolute top-2 left-2">Main</Badge>
                  ) : null}
                </div>
                <div className="flex gap-1">
                  {!media.is_primary ? (
                    <Button
                      variant="outline"
                      size="sm"
                      className="flex-1"
                      disabled={busy}
                      onClick={() => makePrimary.mutate(media.id)}
                    >
                      <StarIcon data-icon="inline-start" />
                      Make main
                    </Button>
                  ) : (
                    <span className="flex-1" />
                  )}
                  <Button
                    variant="outline"
                    size="sm"
                    aria-label="Remove picture"
                    disabled={busy}
                    onClick={() => {
                      if (window.confirm('Remove this picture?')) remove.mutate(media.id)
                    }}
                  >
                    <Trash2Icon />
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-sm text-muted-foreground">No pictures yet.</p>
        )}

        <input
          ref={fileInput}
          type="file"
          accept={ACCEPTED_TYPES}
          multiple
          className="hidden"
          onChange={(e) => {
            handleFiles(e.target.files)
            e.target.value = ''
          }}
        />
        <Button variant="secondary" className="self-start" disabled={busy} onClick={() => fileInput.current?.click()}>
          {upload.isPending ? <Spinner data-icon="inline-start" /> : <ImagePlusIcon data-icon="inline-start" />}
          {upload.isPending ? 'Uploading…' : 'Add pictures'}
        </Button>
      </CardContent>
    </Card>
  )
}

function errorText(error: unknown): string {
  if (error instanceof ApiError) return error.message
  return 'Something went wrong. Try again.'
}
