import { Badge, badgeVariants } from '@/components/ui/badge'
import type { VariantProps } from 'class-variance-authority'
import { cn } from '@/lib/utils'

type BadgeVariant = NonNullable<VariantProps<typeof badgeVariants>['variant']>

const STATUS_VARIANT_MAP: Record<string, BadgeVariant> = {
  // Neutral / waiting
  DRAFT: 'secondary',
  PENDING: 'secondary',
  PLANNED: 'secondary',
  ISSUED: 'secondary',
  REQUESTED: 'secondary',
  OPEN: 'secondary',

  // Active / in flight
  CONFIRMED: 'default',
  IN_PROGRESS: 'default',
  PAUSED: 'outline',
  PACKED: 'default',
  DISPATCHED: 'default',
  IN_TRANSIT: 'default',
  READY: 'default',
  READY_TO_DELIVER: 'default',
  PARTIALLY_PAID: 'default',
  PARTIALLY_DELIVERED: 'default',
  APPROVED: 'default',
  SENT: 'default',
  PARTIALLY_RECEIVED: 'default',
  POSTED: 'outline',

  // Settled / complete
  COMPLETED: 'outline',
  DELIVERED: 'outline',
  PAID: 'outline',
  CLOSED: 'outline',
  FULFILLED: 'outline',
  RECEIVED: 'outline',

  // Destructive
  CANCELLED: 'destructive',
  CANCELED: 'destructive',
  REJECTED: 'destructive',
  FAILED: 'destructive',
  OVERDUE: 'destructive',
  VOID: 'destructive',
  RETURNED: 'destructive',
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
    <Badge variant={variant} className={cn(className)}>
      {label ?? formatStatusLabel(status)}
    </Badge>
  )
}
