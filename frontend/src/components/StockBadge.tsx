import { Badge } from '@/components/ui/badge'
import { formatQuantity } from '@/lib/format'

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
      <Badge variant="warning" className={className}>
        Made to order
      </Badge>
    ) : (
      <Badge variant="error" className={className}>
        Out of stock
      </Badge>
    )
  }

  if (status === 'low_stock') {
    return (
      <Badge variant="warning" className={className}>
        Low stock{qty}
      </Badge>
    )
  }

  return (
    <Badge variant="success" className={className}>
      In stock{qty}
    </Badge>
  )
}
