import { useState } from 'react'
import { ImageIcon } from 'lucide-react'
import { apiUrl } from '@/lib/api/client'
import { cn } from '@/lib/utils'

interface ProductImageProps {
  url?: string | null
  alt: string
  className?: string
  iconClassName?: string
  loading?: 'lazy' | 'eager'
}

/** Product picture that falls back to a neutral placeholder when missing or when the file fails to load. */
export function ProductImage({ url, alt, className, iconClassName = 'size-6', loading = 'lazy' }: ProductImageProps) {
  const [failedUrl, setFailedUrl] = useState<string | null>(null)

  if (!url || failedUrl === url) {
    return (
      <div
        role="img"
        aria-label={alt || 'No picture'}
        className={cn('flex items-center justify-center bg-muted text-muted-foreground', className)}
      >
        <ImageIcon className={iconClassName} />
      </div>
    )
  }

  return (
    <img
      src={apiUrl(url)}
      alt={alt}
      loading={loading}
      onError={() => setFailedUrl(url)}
      className={cn('bg-muted object-cover', className)}
    />
  )
}
