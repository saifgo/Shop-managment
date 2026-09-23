import type { ReactNode } from 'react'
import { AlertCircleIcon, InboxIcon } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  Empty,
  EmptyContent,
  EmptyDescription,
  EmptyHeader,
  EmptyMedia,
  EmptyTitle,
} from '@/components/ui/empty'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'

interface QueryStateProps {
  isLoading?: boolean
  error?: Error | string | null
  isEmpty?: boolean
  emptyTitle?: string
  emptyDescription?: string
  emptyAction?: ReactNode
  loadingSkeleton?: ReactNode
  className?: string
  children: ReactNode
}

function DefaultLoadingSkeleton() {
  return (
    <div className="flex flex-col gap-3">
      <Skeleton className="h-8 w-48" />
      <Skeleton className="h-24 w-full" />
      <Skeleton className="h-24 w-full" />
    </div>
  )
}

function resolveErrorMessage(error: Error | string) {
  return typeof error === 'string' ? error : error.message
}

export function QueryState({
  isLoading = false,
  error = null,
  isEmpty = false,
  emptyTitle = 'No results',
  emptyDescription = 'There is nothing to show yet.',
  emptyAction,
  loadingSkeleton,
  className,
  children,
}: QueryStateProps) {
  if (isLoading) {
    return (
      <div className={cn(className)}>{loadingSkeleton ?? <DefaultLoadingSkeleton />}</div>
    )
  }

  if (error) {
    return (
      <Alert variant="destructive" className={cn(className)}>
        <AlertCircleIcon />
        <AlertTitle>Something went wrong</AlertTitle>
        <AlertDescription>{resolveErrorMessage(error)}</AlertDescription>
      </Alert>
    )
  }

  if (isEmpty) {
    return (
      <Empty className={cn('border border-dashed', className)}>
        <EmptyHeader>
          <EmptyMedia variant="icon">
            <InboxIcon />
          </EmptyMedia>
          <EmptyTitle>{emptyTitle}</EmptyTitle>
          <EmptyDescription>{emptyDescription}</EmptyDescription>
        </EmptyHeader>
        {emptyAction ? <EmptyContent>{emptyAction}</EmptyContent> : null}
      </Empty>
    )
  }

  return <>{children}</>
}
