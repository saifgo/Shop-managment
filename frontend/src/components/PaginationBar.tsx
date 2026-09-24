import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'

interface PaginationBarProps {
  page: number
  totalPages: number
  total: number
  noun?: string
  onPageChange: (page: number) => void
}

/** Compact "12 orders · Page 1 of 3 ‹ ›" footer for paginated lists. */
export function PaginationBar({ page, totalPages, total, noun = 'results', onPageChange }: PaginationBarProps) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
      <span className="tabular-nums">
        {total} {noun}
      </span>
      {totalPages > 1 ? (
        <div className="flex items-center gap-2">
          <span className="tabular-nums">
            Page {page} of {totalPages}
          </span>
          <Button
            variant="outline"
            size="icon-sm"
            aria-label="Previous page"
            disabled={page <= 1}
            onClick={() => onPageChange(page - 1)}
          >
            <ChevronLeftIcon />
          </Button>
          <Button
            variant="outline"
            size="icon-sm"
            aria-label="Next page"
            disabled={page >= totalPages}
            onClick={() => onPageChange(page + 1)}
          >
            <ChevronRightIcon />
          </Button>
        </div>
      ) : null}
    </div>
  )
}
