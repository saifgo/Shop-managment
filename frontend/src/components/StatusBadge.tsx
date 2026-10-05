import { Badge, badgeVariants } from '@/components/ui/badge'
import type { VariantProps } from 'class-variance-authority'
import { cn } from '@/lib/utils'

type BadgeVariant = NonNullable<VariantProps<typeof badgeVariants>['variant']>

const STATUS_VARIANT_MAP: Record<string, BadgeVariant> = {
  // Waiting / needs attention
  DRAFT: 'warning',
  PENDING: 'warning',
  PLANNED: 'warning',
  ISSUED: 'secondary',
  REQUESTED: 'warning',
  OPEN: 'secondary',

  // Active / in flight
  CONFIRMED: 'info',
  IN_PROGRESS: 'info',
  PAUSED: 'warning',
  PACKED: 'info',
  DISPATCHED: 'info',
  IN_TRANSIT: 'info',
  READY: 'info',
  READY_TO_DELIVER: 'info',
  PARTIALLY_PAID: 'info',
  PARTIALLY_DELIVERED: 'info',
  APPROVED: 'info',
  SENT: 'info',
  PARTIALLY_RECEIVED: 'info',
  POSTED: 'info',

  // Settled / complete
  COMPLETED: 'success',
  DELIVERED: 'success',
  PAID: 'success',
  CLOSED: 'success',
  FULFILLED: 'success',
  RECEIVED: 'success',

  // Failed / reversed
  CANCELLED: 'error',
  CANCELED: 'error',
  REJECTED: 'error',
  FAILED: 'error',
  OVERDUE: 'error',
  VOID: 'error',
  RETURNED: 'error',
}

function formatStatusLabel(status: string) {
  return status
    .replace(/_/g, ' ')
    .toLowerCase()
    .replace(/\b\w/g, (char) => char.toUpperCase())
}

interface StatusBadgeProps {
  status: string
  label?: string
  className?: string
}

export function StatusBadge({ status, label, className }: StatusBadgeProps) {
  const key = status.trim().toUpperCase()
  const variant = STATUS_VARIANT_MAP[key] ?? 'outline'

  return (
    <Badge variant={variant} size="lg" className={cn(className)}>
      {label ?? formatStatusLabel(status)}
    </Badge>
  )
}
