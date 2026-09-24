import { Badge } from '@/components/ui/badge'
import { formatQuantity } from '@/lib/format'
import { cn } from '@/lib/utils'

export type StockStatus = 'in_stock' | 'low_stock' | 'out_of_stock'

interface StockBadgeProps {
  status: StockStatus | string
  quantity?: string | number
  /** Customer-facing wording: out-of-stock items that allow backorders are "made to order". */
  backorderAllowed?: boolean
  showQuantity?: boolean
  className?: string
}

export function StockBadge({ status, quantity, backorderAllowed, showQuantity = false, className }: StockBadgeProps) {
  const qty = showQuantity && quantity !== undefined ? ` · ${formatQuantity(quantity)}` : ''

  if (status === 'out_of_stock') {
    return backorderAllowed ? (
      <Badge variant="outline" className={cn('border-amber-300 bg-amber-50 text-amber-800', className)}>
        Made to order
      </Badge>
    ) : (
      <Badge variant="outline" className={cn('border-red-200 bg-red-50 text-red-700', className)}>
        Out of stock
      </Badge>
    )
  }

  if (status === 'low_stock') {
    return (
      <Badge variant="outline" className={cn('border-amber-300 bg-amber-50 text-amber-800', className)}>
        Low stock{qty}
      </Badge>
    )
  }

  return (
    <Badge variant="outline" className={cn('border-emerald-200 bg-emerald-50 text-emerald-700', className)}>
      In stock{qty}
    </Badge>
  )
}
