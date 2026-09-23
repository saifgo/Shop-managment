import { cn } from '@/lib/utils'

interface MoneyTextProps {
  amount: number | string
  currency?: string
  locale?: string
  className?: string
}

function formatMoney(
  amount: number | string,
  currency: string | undefined,
  locale: string,
) {
  const value = typeof amount === 'number' ? amount : Number(amount)
  if (Number.isNaN(value)) {
    return String(amount)
  }

  if (currency) {
    return new Intl.NumberFormat(locale, {
      style: 'currency',
      currency,
    }).format(value)
  }

  return new Intl.NumberFormat(locale, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(value)
}

export function MoneyText({
  amount,
  currency,
  locale = 'en-US',
  className,
}: MoneyTextProps) {
  return (
    <span className={cn('tabular-nums', className)}>
      {formatMoney(amount, currency, locale)}
    </span>
  )
}
