import { MinusIcon, PlusIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

interface QuantityStepperProps {
  value: number
  onChange: (value: number) => void
  min?: number
  max?: number
  label: string
  size?: 'sm' | 'default'
  className?: string
}

/** − [ n ] + control for whole-unit quantities; clamps to min/max. */
export function QuantityStepper({ value, onChange, min = 1, max, label, size = 'default', className }: QuantityStepperProps) {
  const clamp = (next: number) => {
    const floored = Number.isFinite(next) ? Math.floor(next) : min
    return Math.max(min, max !== undefined ? Math.min(max, floored) : floored)
  }

  const buttonSize = size === 'sm' ? 'icon-sm' : 'icon'

  return (
    <div className={cn('inline-flex items-center rounded-lg border bg-background', className)} role="group" aria-label={label}>
      <Button
        type="button"
        variant="ghost"
        size={buttonSize}
        aria-label="Decrease quantity"
        disabled={value <= min}
        onClick={() => onChange(clamp(value - 1))}
      >
        <MinusIcon />
      </Button>
      <input
        type="number"
        inputMode="numeric"
        min={min}
        max={max}
        value={value}
        aria-label={label}
        onChange={(e) => onChange(clamp(Number(e.target.value)))}
        className={cn(
          'w-12 bg-transparent text-center text-sm tabular-nums outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none',
          size === 'sm' ? 'h-7' : 'h-8',
        )}
      />
      <Button
        type="button"
        variant="ghost"
        size={buttonSize}
        aria-label="Increase quantity"
        disabled={max !== undefined && value >= max}
        onClick={() => onChange(clamp(value + 1))}
      >
        <PlusIcon />
      </Button>
    </div>
  )
}
